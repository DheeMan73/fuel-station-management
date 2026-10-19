<?php
/**
 * Last Price — System Admin only.
 * Change active fuel prices (logged to price_history) and
 * sync with an external market-rate JSON feed.
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'Last Price';
$pageSubtitle = 'Update active prices — every change is logged';

/* ==========================================================
 *  Update a fuel price
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_price') {
    verify_csrf();

    $tankId   = (int)($_POST['tank_id'] ?? 0);
    $newPrice = parse_decimal($_POST['new_price'] ?? '', 2);

    if ($tankId <= 0 || $newPrice === null || $newPrice <= 0) {
        set_flash('error', 'Please select a fuel and enter a valid positive price (e.g. 1.85).');
        redirect('last_price.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? FOR UPDATE");
        $stmt->execute([$tankId]);
        $tank = $stmt->fetch();

        if (!$tank) {
            throw new RuntimeException('Fuel tank not found.');
        }

        $oldPrice = (float)$tank['price_per_liter'];

        if (abs($oldPrice - $newPrice) < 0.005) {
            $pdo->rollBack();
            set_flash('error', 'That is already the current price — nothing logged.');
            redirect('last_price.php');
        }

        $pdo->prepare("UPDATE fuel_inventory SET price_per_liter = ?, last_updated = NOW() WHERE id = ?")
            ->execute([$newPrice, $tankId]);

        $diff = round($newPrice - $oldPrice, 2);

        $pdo->prepare(
            "INSERT INTO price_history (fuel_type, product_id, old_price, new_price, difference, changed_by, changed_by_id, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Manual', NOW())"
        )->execute([
            $tank['fuel_type'],
            $tankId,
            $oldPrice,
            $newPrice,
            $diff,
            $_SESSION['full_name'] ?? 'System Admin',
            (int)($_SESSION['user_id'] ?? 0) ?: null,
        ]);

        $pdo->commit();

        log_activity($pdo, 'price_change', sprintf(
            '%s price %s from $%s to $%s (%s$%s).',
            $tank['fuel_type'],
            $diff > 0 ? 'increased' : 'decreased',
            money($oldPrice), money($newPrice),
            $diff > 0 ? '+' : '-', money(abs($diff))
        ), 'tank', $tankId);

        set_flash('success', sprintf(
            '%s price %s from $%s to $%s (%s$%s).',
            $tank['fuel_type'],
            $diff > 0 ? 'increased' : 'decreased',
            money($oldPrice),
            money($newPrice),
            $diff > 0 ? '+' : '-',
            money(abs($diff))
        ));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Price update failed: ' . $e->getMessage());
    }
    redirect('last_price.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$tanks = $pdo->query("SELECT * FROM fuel_inventory ORDER BY fuel_type")->fetchAll();

$history = $pdo->query(
    "SELECT * FROM price_history ORDER BY created_at DESC, id DESC LIMIT 100"
)->fetchAll();

$totalChanges   = (int)$pdo->query("SELECT COUNT(*) FROM price_history")->fetchColumn();
$increases      = (int)$pdo->query("SELECT COUNT(*) FROM price_history WHERE difference > 0")->fetchColumn();
$decreases      = (int)$pdo->query("SELECT COUNT(*) FROM price_history WHERE difference < 0")->fetchColumn();

$marketUrl = get_setting($pdo, 'market_feed_url', 'market_feed.json');

/* Both labels count as market-sourced: older rows stored 'Market',
   the sync now writes 'Market Rate Sync'. */
$marketSources = ['Market', MARKET_SYNC_SOURCE];
$lastSync  = $pdo->query(
    "SELECT created_at FROM price_history WHERE source IN ('Market', '" . MARKET_SYNC_SOURCE . "') ORDER BY created_at DESC LIMIT 1"
)->fetchColumn();

/* ---- Latest sync run + recent history (drives the summary panel) ---- */
$lastRun = $pdo->query("SELECT * FROM market_sync_runs ORDER BY id DESC LIMIT 1")->fetch();
$recentRuns = $pdo->query("SELECT * FROM market_sync_runs ORDER BY id DESC LIMIT 5")->fetchAll();
$runCount = (int)$pdo->query("SELECT COUNT(*) FROM market_sync_runs")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<!-- ACTIVE PRICES -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <?php
    $fuelGrad = ['Petrol' => 'from-orange-500 to-amber-500', 'Diesel' => 'from-cyan-500 to-blue-500', 'LPG' => 'from-purple-500 to-pink-500'];
    foreach ($tanks as $t):
        $grad = $fuelGrad[$t['fuel_type']] ?? 'from-slate-500 to-slate-600';
    ?>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center">
                    <i class="fa-solid fa-tag text-white"></i>
                </div>
                <div>
                    <h3 class="text-white font-semibold"><?= htmlspecialchars($t['fuel_type']) ?></h3>
                    <p class="text-slate-500 text-xs">Active price</p>
                </div>
            </div>
            <span class="text-amber-400 text-2xl font-bold">$<?= money($t['price_per_liter']) ?></span>
        </div>
        <p class="text-slate-500 text-xs mt-3">Tank #<?= (int)$t['id'] ?> • Last updated <?= date('M d, H:i', strtotime($t['last_updated'])) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- CHANGE PRICE FORM -->
    <div class="lg:col-span-1 bg-slate-800 border border-slate-700 rounded-2xl p-6">
        <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-tag text-amber-400 mr-2"></i>Change Active Price</h3>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_price">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Fuel Type</label>
                <select name="tank_id" required id="priceTank"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                               focus:outline-none focus:border-amber-500 transition">
                    <option value="" disabled selected>Select fuel…</option>
                    <?php foreach ($tanks as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" data-price="<?= money($t['price_per_liter']) ?>">
                        <?= htmlspecialchars($t['fuel_type']) ?> — currently $<?= money($t['price_per_liter']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">New Price ($ per liter)</label>
                <input type="number" step="0.01" min="0.01" name="new_price" required
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-amber-500 transition" placeholder="e.g. 1.90">
                <p class="text-slate-500 text-xs mt-1"><i class="fa-solid fa-circle-info mr-1"></i>Prices are stored as DECIMAL(10,2).</p>
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm
                                         hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
                <i class="fa-solid fa-check mr-1"></i> Save Price
            </button>
        </form>
    </div>

    <!-- MARKET SYNC -->
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
        <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-cloud-arrow-down text-cyan-400 mr-2"></i>Market Rate Sync</h3>
        <p class="text-slate-400 text-xs mb-4">
            Pull live rates from an external JSON feed and apply any differences to the active prices.
            Use a URL (<code>https://…</code>) or a local file path relative to the app.
        </p>
        <form method="POST" action="market_sync.php" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_url">
            <label class="text-slate-400 text-xs block">Feed URL / Path</label>
            <input type="text" name="market_feed_url" value="<?= htmlspecialchars((string)$marketUrl) ?>" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                          focus:outline-none focus:border-cyan-500 transition">
            <button type="submit" class="w-full py-2 rounded-xl bg-slate-700 border border-slate-600 text-slate-200 text-sm font-medium
                                         hover:border-cyan-500/40 hover:text-white transition">
                <i class="fa-solid fa-floppy-disk mr-1"></i> Save Feed
            </button>
        </form>
        <form method="POST" action="market_sync.php" class="mt-3"
              onsubmit="return confirm('Sync prices from the market feed now?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-cyan-600 text-white text-sm font-medium
                                         hover:from-cyan-400 hover:to-cyan-500 transition shadow-lg shadow-cyan-500/20">
                <i class="fa-solid fa-rotate mr-1"></i> Sync Now
            </button>
        </form>
        <p class="text-slate-500 text-xs mt-3">
            Last market sync: <span class="text-slate-300"><?= $lastSync ? date('M d, Y H:i', strtotime((string)$lastSync)) : 'never' ?></span>
        </p>
    </div>

    <!-- SUMMARY -->
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
        <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-chart-simple text-emerald-400 mr-2"></i>Sync Summary</h3>

        <?php if ($lastRun): ?>
        <p class="text-slate-500 text-xs mb-3">
            Last sync: <span class="text-slate-300"><?= date('M d, Y H:i', strtotime((string)$lastRun['finished_at'])) ?></span>
            · by <span class="text-slate-300"><?= htmlspecialchars((string)($lastRun['triggered_by'] ?? 'System')) ?></span>
        </p>
        <div class="space-y-3">
            <div class="flex items-center justify-between bg-slate-700/40 rounded-xl px-4 py-3">
                <span class="text-slate-300 text-sm"><i class="fa-solid fa-list text-slate-400 mr-2"></i>Total Changes</span>
                <span class="text-white font-bold"><?= (int)$lastRun['total_changes'] ?></span>
            </div>
            <div class="flex items-center justify-between bg-emerald-500/10 border border-emerald-500/20 rounded-xl px-4 py-3">
                <span class="text-emerald-400 text-sm"><i class="fa-solid fa-arrow-trend-up mr-2"></i>Increases</span>
                <span class="text-emerald-400 font-bold"><?= (int)$lastRun['increases'] ?></span>
            </div>
            <div class="flex items-center justify-between bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
                <span class="text-red-400 text-sm"><i class="fa-solid fa-arrow-trend-down mr-2"></i>Decreases</span>
                <span class="text-red-400 font-bold"><?= (int)$lastRun['decreases'] ?></span>
            </div>
            <div class="flex items-center justify-between bg-slate-700/40 rounded-xl px-4 py-3">
                <span class="text-slate-300 text-sm"><i class="fa-solid fa-minus text-slate-400 mr-2"></i>Unchanged / Skipped</span>
                <span class="text-slate-300 font-bold"><?= (int)$lastRun['unchanged'] ?> / <?= (int)$lastRun['skipped'] ?></span>
            </div>
        </div>
        <?php else: ?>
        <p class="text-slate-500 text-xs mb-4">No sync has run yet — use <span class="text-slate-300">Sync Now</span> to pull the feed.</p>
        <?php endif; ?>

        <p class="text-slate-500 text-[11px] mt-4 mb-2 uppercase tracking-wider">All time</p>
        <div class="grid grid-cols-3 gap-2 text-center">
            <div class="bg-slate-700/40 rounded-xl py-2.5">
                <p class="text-slate-400 text-[11px]">Total</p>
                <p class="text-white font-bold"><?= $totalChanges ?></p>
            </div>
            <div class="bg-emerald-500/10 rounded-xl py-2.5">
                <p class="text-emerald-400 text-[11px]">Up</p>
                <p class="text-emerald-400 font-bold"><?= $increases ?></p>
            </div>
            <div class="bg-red-500/10 rounded-xl py-2.5">
                <p class="text-red-400 text-[11px]">Down</p>
                <p class="text-red-400 font-bold"><?= $decreases ?></p>
            </div>
        </div>
    </div>
</div>

<!-- PRICE HISTORY -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-clock-rotate-left text-amber-400 mr-2"></i>Price Change History</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">When</th>
                    <th class="px-6 py-3 font-medium">Fuel</th>
                    <th class="px-6 py-3 font-medium">Old Price</th>
                    <th class="px-6 py-3 font-medium">New Price</th>
                    <th class="px-6 py-3 font-medium">Difference</th>
                    <th class="px-6 py-3 font-medium">Source</th>
                    <th class="px-6 py-3 font-medium">Changed By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($history)): ?>
                <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-tags text-3xl mb-3 block text-slate-600"></i>No price changes logged yet.
                </td></tr>
                <?php else: foreach ($history as $h):
                    $diff = (float)$h['difference'];
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-500 text-xs"><?= date('M d, Y H:i', strtotime($h['created_at'])) ?></td>
                    <td class="px-6 py-3 text-white font-medium"><?= htmlspecialchars($h['fuel_type']) ?></td>
                    <td class="px-6 py-3 text-slate-400">$<?= money($h['old_price']) ?></td>
                    <td class="px-6 py-3 text-slate-200 font-medium">$<?= money($h['new_price']) ?></td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border <?= $diff > 0 ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : ($diff < 0 ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-slate-700 text-slate-300 border-slate-600') ?>">
                            <i class="fa-solid <?= $diff > 0 ? 'fa-arrow-up' : ($diff < 0 ? 'fa-arrow-down' : 'fa-minus') ?>"></i>
                            <?= $diff > 0 ? '+' : ($diff < 0 ? '-' : '') ?>$<?= money(abs((float)$diff)) ?>
                        </span>
                    </td>
                    <td class="px-6 py-3">
                        <?php if (in_array(($h['source'] ?? 'Manual'), $marketSources, true)): ?>
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                            <i class="fa-solid fa-cloud mr-1"></i>Market
                        </span>
                        <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-700 text-slate-300 border border-slate-600">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars($h['changed_by']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RECENT SYNC RUNS -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between flex-wrap gap-2">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-cloud-arrow-down text-cyan-400 mr-2"></i>Recent Market Sync Runs</h3>
        <a href="price_history.php?source=<?= urlencode(MARKET_SYNC_SOURCE) ?>" class="text-amber-400 text-xs hover:text-amber-300">
            <i class="fa-solid fa-clock-rotate-left mr-1"></i>View all market price changes
        </a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">When</th>
                    <th class="px-6 py-3 font-medium">Feed</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Total Changes</th>
                    <th class="px-6 py-3 font-medium">Increases</th>
                    <th class="px-6 py-3 font-medium">Decreases</th>
                    <th class="px-6 py-3 font-medium">Triggered By</th>
                    <th class="px-6 py-3 font-medium">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($recentRuns)): ?>
                <tr><td colspan="8" class="px-6 py-10 text-center text-slate-500">
                    <i class="fa-solid fa-cloud text-3xl mb-3 block text-slate-600"></i>No market sync has run yet.
                </td></tr>
                <?php else: foreach ($recentRuns as $run): ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-500 text-xs whitespace-nowrap"><?= date('M d, Y H:i', strtotime((string)($run['finished_at'] ?? $run['started_at']))) ?></td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars((string)$run['feed']) ?></td>
                    <td class="px-6 py-3">
                        <?php if (($run['status'] ?? '') === 'error'): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border bg-red-500/10 text-red-400 border-red-500/20">
                            <i class="fa-solid fa-triangle-exclamation"></i> Failed
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border bg-emerald-500/10 text-emerald-400 border-emerald-500/20">
                            <i class="fa-solid fa-circle-check"></i> OK
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-white font-bold"><?= (int)$run['total_changes'] ?></td>
                    <td class="px-6 py-3 text-emerald-400 font-semibold">+<?= (int)$run['increases'] ?></td>
                    <td class="px-6 py-3 text-red-400 font-semibold">-<?= (int)$run['decreases'] ?></td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars((string)($run['triggered_by'] ?? '—')) ?></td>
                    <td class="px-6 py-3 text-slate-500 text-xs max-w-md">
                        <?php if (($run['status'] ?? '') === 'error'): ?>
                        <span class="text-red-400"><?= htmlspecialchars((string)$run['error_message']) ?></span>
                        <?php else: ?>
                        <?= htmlspecialchars((string)($run['detail'] ?? '—')) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
