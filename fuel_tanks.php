<?php
/**
 * Fuel Tanks — live tank levels, capacity validation & restocking.
 *
 * Rule: before adding fuel, (current_volume + added) must not exceed
 *       max_capacity. Overflow attempts are blocked and flagged FULL.
 */
require_once __DIR__ . '/config.php';

$pageTitle    = 'Fuel Tanks';
$pageSubtitle = 'Monitor stock levels and refill tanks';

$fuelGrad = [
    'Petrol' => 'from-orange-500 to-amber-500',
    'Diesel' => 'from-cyan-500 to-blue-500',
    'LPG'    => 'from-purple-500 to-pink-500',
];
$statusMap = [
    'OK'   => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-circle-check',         'label' => 'OK'],
    'LOW'  => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-triangle-exclamation', 'label' => 'LOW'],
    'FULL' => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-circle-exclamation',   'label' => 'FULL'],
];

/* ==========================================================
 *  Restock (with capacity validation)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restock') {
    verify_csrf();

    $tankId   = (int)($_POST['tank_id'] ?? 0);
    $addLiters = parse_decimal($_POST['add_liters'] ?? '', 2);

    if ($tankId <= 0 || $addLiters === null || $addLiters <= 0) {
        set_flash('error', 'Please choose a tank and enter a valid number of liters.');
        redirect('fuel_tanks.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? FOR UPDATE");
        $stmt->execute([$tankId]);
        $tank = $stmt->fetch();

        if (!$tank) {
            throw new RuntimeException('Tank not found.');
        }

        $current = (float)$tank['current_stock_liters'];
        $cap     = (float)$tank['tank_capacity_liters'];

        /* --- Validation: cannot exceed max capacity --- */
        if ($cap <= 0 || ($current + $addLiters) > ($cap + 0.0001)) {
            /* The tank never overflows, so leave its level untouched and
               simply reflect the true status (do NOT force FULL). */
            $status = $cap <= 0
                ? 'OK'
                : ($current >= ($cap - 0.0001) ? 'FULL' : (($current / $cap) <= 0.15 ? 'LOW' : 'OK'));
            $pdo->prepare("UPDATE fuel_inventory SET status = ? WHERE id = ?")->execute([$status, $tankId]);
            $pdo->commit();

            $space = max(0, $cap - $current);
            log_activity($pdo, 'tank_refill_blocked', sprintf(
                'Blocked refill of %s: tried to add %s L but only %s L of space remains (capacity %s L).',
                $tank['fuel_type'], fmt($addLiters), fmt($space), fmt($cap, 0)
            ), 'tank', $tankId);
            set_flash('error', 'Tank is FULL! Cannot exceed max capacity. Available space: ' . fmt($space) . ' L.');
            redirect('fuel_tanks.php');
        }

        $newStock = $current + $addLiters;
        $status   = $newStock >= ($cap - 0.0001) ? 'FULL' : (($cap > 0 && ($newStock / $cap) <= 0.15) ? 'LOW' : 'OK');

        $pdo->prepare("UPDATE fuel_inventory SET current_stock_liters = ?, status = ?, last_updated = NOW() WHERE id = ?")
            ->execute([$newStock, $status, $tankId]);

        $pdo->commit();

        log_activity($pdo, 'tank_refill', sprintf(
            'Refilled %s tank with %s L — new level %s L (%.1f%% of capacity).',
            $tank['fuel_type'], fmt($addLiters), fmt($newStock), $cap > 0 ? ($newStock / $cap) * 100 : 0
        ), 'tank', $tankId);

        set_flash('success', sprintf('Added %s L of %s. New level: %s L.',
            fmt($addLiters), $tank['fuel_type'], fmt($newStock)));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Restock failed: ' . $e->getMessage());
    }
    redirect('fuel_tanks.php');
}

$tanks = $pdo->query("SELECT * FROM fuel_inventory ORDER BY id")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if (!is_admin()): ?>
<div class="bg-cyan-500/10 border border-cyan-500/25 text-cyan-300 px-5 py-3 rounded-xl text-sm flex items-center gap-2">
    <i class="fa-solid fa-circle-info"></i>
    You are signed in as a <strong>Station Operator</strong>. Fuel price changes require an administrator.
</div>
<?php endif; ?>

<!-- TANK CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <?php foreach ($tanks as $tank):
        $cap = (float)$tank['tank_capacity_liters'];
        $cur = (float)$tank['current_stock_liters'];
        $pct = $cap > 0 ? min(100, max(0, ($cur / $cap) * 100)) : 0;
        $grad = $fuelGrad[$tank['fuel_type']] ?? 'from-slate-500 to-slate-600';
        $st = $statusMap[$tank['status']] ?? $statusMap['OK'];
    ?>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 relative overflow-hidden transition">
        <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full bg-gradient-to-br <?= $grad ?> opacity-10 blur-2xl"></div>

        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center shadow-lg">
                    <i class="fa-solid fa-gas-pump text-white"></i>
                </div>
                <div>
                    <h3 class="text-white font-semibold"><?= htmlspecialchars($tank['fuel_type']) ?></h3>
                    <p class="text-slate-500 text-xs">Tank #<?= (int)$tank['id'] ?></p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $st['cls'] ?>">
                <i class="fa-solid <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
            </span>
        </div>

        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Capacity</span><span class="text-white font-medium"><?= fmt($cap, 0) ?> L</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Current Stock</span><span class="text-white font-medium"><?= fmt($cur) ?> L</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Available Space</span><span class="text-cyan-400 font-medium"><?= fmt(max(0, $cap - $cur)) ?> L</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Price/Liter</span><span class="text-amber-400 font-semibold">$<?= money($tank['price_per_liter']) ?></span></div>
            <div class="flex justify-between items-center pt-1"><span class="text-slate-400">Fill Level</span><span class="text-white font-medium"><?= round($pct, 1) ?>%</span></div>
            <div class="h-3 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r <?= $grad ?>" style="width:<?= round($pct, 1) ?>%"></div>
            </div>
            <?php if ($cap > 0 && ($cur / $cap) < LOW_FUEL_THRESHOLD): ?>
            <div class="mt-2 flex items-center gap-2 text-xs font-medium text-red-300 bg-red-500/10 border border-red-500/25 rounded-lg px-3 py-2">
                <i class="fa-solid fa-triangle-exclamation animate-pulse"></i> WARNING: Low Fuel Stock!
            </div>
            <?php endif; ?>
        </div>

        <div class="flex gap-2 mt-4">
            <button onclick="openRestockModal(<?= (int)$tank['id'] ?>, '<?= htmlspecialchars($tank['fuel_type'], ENT_QUOTES) ?>', <?= $cur ?>, <?= $cap ?>)"
                    class="flex-1 px-3 py-2 rounded-lg bg-slate-700/60 border border-slate-600 text-slate-300 text-xs font-medium
                           hover:border-cyan-500/40 hover:text-white transition">
                <i class="fa-solid fa-truck-ramp-box mr-1 text-cyan-400"></i> Restock
            </button>
            <?php if (is_admin()): ?>
            <a href="last_price.php"
               class="flex-1 text-center px-3 py-2 rounded-lg bg-slate-700/60 border border-slate-600 text-slate-300 text-xs font-medium
                      hover:border-amber-500/40 hover:text-white transition">
                <i class="fa-solid fa-tag mr-1 text-amber-400"></i> Set Price
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- DETAIL TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-table text-amber-400 mr-2"></i>Tank Inventory Detail</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">#</th>
                    <th class="px-6 py-3 font-medium">Fuel</th>
                    <th class="px-6 py-3 font-medium">Capacity (L)</th>
                    <th class="px-6 py-3 font-medium">Stock (L)</th>
                    <th class="px-6 py-3 font-medium">Space (L)</th>
                    <th class="px-6 py-3 font-medium">Fill</th>
                    <th class="px-6 py-3 font-medium">Price/L</th>
                    <th class="px-6 py-3 font-medium">Stock Value</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Updated</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php foreach ($tanks as $tank):
                    $cap = (float)$tank['tank_capacity_liters'];
                    $cur = (float)$tank['current_stock_liters'];
                    $pct = $cap > 0 ? ($cur / $cap) * 100 : 0;
                    $st = $statusMap[$tank['status']] ?? $statusMap['OK'];
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-500"><?= (int)$tank['id'] ?></td>
                    <td class="px-6 py-3 text-white font-medium"><?= htmlspecialchars($tank['fuel_type']) ?></td>
                    <td class="px-6 py-3 text-slate-300"><?= fmt($cap, 0) ?></td>
                    <td class="px-6 py-3 text-white font-medium"><?= fmt($cur) ?></td>
                    <td class="px-6 py-3 text-slate-400"><?= fmt(max(0, $cap - $cur)) ?></td>
                    <td class="px-6 py-3 text-slate-300"><?= round($pct, 1) ?>%</td>
                    <td class="px-6 py-3 text-amber-400 font-semibold">$<?= money($tank['price_per_liter']) ?></td>
                    <td class="px-6 py-3 text-emerald-400 font-medium">$<?= money($cur * (float)$tank['price_per_liter']) ?></td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border <?= $st['cls'] ?>">
                            <i class="fa-solid <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-3 text-slate-500 text-xs"><?= date('M d, H:i', strtotime($tank['last_updated'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RESTOCK MODAL -->
<div id="restockModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-truck-ramp-box text-cyan-400 mr-2"></i>Restock Tank</h3>
            <button onclick="closeModal('restockModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restock">
            <input type="hidden" name="tank_id" id="restock_tank_id">
            <div class="bg-slate-700/50 rounded-xl px-4 py-3">
                <p class="text-slate-400 text-xs">Fuel Type</p>
                <p class="text-white font-medium" id="restock_fuel_type">—</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-slate-700/50 rounded-xl px-4 py-3">
                    <p class="text-slate-400 text-xs">Current Stock</p>
                    <p class="text-white font-medium"><span id="restock_current">0</span> L</p>
                </div>
                <div class="bg-slate-700/50 rounded-xl px-4 py-3">
                    <p class="text-slate-400 text-xs">Capacity</p>
                    <p class="text-white font-medium"><span id="restock_capacity">0</span> L</p>
                </div>
            </div>
            <div class="bg-cyan-500/10 border border-cyan-500/20 rounded-xl px-4 py-2.5 text-xs text-cyan-300">
                <i class="fa-solid fa-circle-info mr-1"></i>
                Max you can add: <strong id="restock_space">0</strong> L
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Liters to Add</label>
                <input type="number" step="0.01" min="0.01" name="add_liters" id="restock_add" required
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-cyan-500 transition" placeholder="e.g. 2000">
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-cyan-600 text-white font-medium text-sm
                                         hover:from-cyan-400 hover:to-cyan-500 transition shadow-lg shadow-cyan-500/20">
                <i class="fa-solid fa-check mr-1"></i> Confirm Restock
            </button>
        </form>
    </div>
</div>

<script>
function openRestockModal(id, type, current, capacity) {
    const space = Math.max(0, capacity - current);
    document.getElementById('restock_tank_id').value = id;
    document.getElementById('restock_fuel_type').textContent = type;
    document.getElementById('restock_current').textContent = Number(current).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('restock_capacity').textContent = Number(capacity).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('restock_space').textContent = space.toLocaleString(undefined, {minimumFractionDigits: 2});
    const add = document.getElementById('restock_add');
    add.max = space;
    showModal('restockModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
