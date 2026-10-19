<?php
/**
 * Price History — System Admin only.
 *
 * Read-only audit trail of every fuel price change. Rows are never
 * edited or deleted here — the table is append-only, so an old sale
 * always keeps the price it was sold at.
 *
 * Each row carries:
 *   product_id    -> fuel_inventory.id (the fuel whose price moved)
 *   old_price     -> the price in effect before the change
 *   new_price     -> the price in effect after the change
 *   changed_by    -> the user's display name (kept for readability)
 *   changed_by_id -> users.id of the admin who made the change
 *   created_at    -> when it happened
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'Price History';
$pageSubtitle = 'Every price change ever recorded — read only';

/* ==========================================================
 *  Filters
 * ========================================================= */
$fProduct = (int)($_GET['product'] ?? 0);
$fSource  = trim((string)($_GET['source'] ?? ''));
$fUser    = (int)($_GET['user'] ?? 0);
$fFrom    = trim((string)($_GET['from'] ?? ''));
$fTo      = trim((string)($_GET['to'] ?? ''));

$validSources = ['Manual', 'Market', MARKET_SYNC_SOURCE];
if (!in_array($fSource, $validSources, true)) {
    $fSource = '';
}
if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom) !== 1) { $fFrom = ''; }
if ($fTo   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)   !== 1) { $fTo   = ''; }

$where  = [];
$params = [];

if ($fProduct > 0) { $where[] = '(ph.product_id = ? OR ph.fuel_type = (SELECT fuel_type FROM fuel_inventory WHERE id = ?))'; $params[] = $fProduct; $params[] = $fProduct; }
if ($fSource !== '') { $where[] = 'ph.source = ?'; $params[] = $fSource; }
if ($fUser > 0)     { $where[] = 'ph.changed_by_id = ?'; $params[] = $fUser; }
if ($fFrom !== '')  { $where[] = 'ph.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')    { $where[] = 'ph.created_at <= ?'; $params[] = $fTo . ' 23:59:59'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ==========================================================
 *  CSV export (respects the same filters)
 * ========================================================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT ph.created_at, ph.fuel_type, ph.product_id, ph.old_price, ph.new_price, ph.difference,
                   ph.source, ph.changed_by, ph.changed_by_id, u.email AS user_email
            FROM price_history ph
            LEFT JOIN users u ON u.id = ph.changed_by_id
            $whereSql
            ORDER BY ph.created_at DESC, ph.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="price-history-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Changed At', 'Fuel', 'Product ID', 'Old Price', 'New Price', 'Difference', 'Source', 'Changed By', 'User ID', 'Email']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'], $r['fuel_type'], $r['product_id'],
            $r['old_price'], $r['new_price'], $r['difference'],
            $r['source'], $r['changed_by'], $r['changed_by_id'], $r['user_email'],
        ]);
    }
    fclose($out);
    log_activity($pdo, 'price_history_export', 'Exported ' . count($rows) . ' price-history row(s) to CSV.');
    exit;
}

/* ==========================================================
 *  Pagination
 * ========================================================= */
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM price_history ph $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listStmt = $pdo->prepare(
    "SELECT ph.*, u.full_name AS changer_name, u.email AS changer_email
     FROM price_history ph
     LEFT JOIN users u ON u.id = ph.changed_by_id
     $whereSql
     ORDER BY ph.created_at DESC, ph.id DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

/* ==========================================================
 *  KPIs
 * ========================================================= */
$kpi = [
    'total'  => (int)$pdo->query("SELECT COUNT(*) FROM price_history")->fetchColumn(),
    'up'     => (int)$pdo->query("SELECT COUNT(*) FROM price_history WHERE difference > 0")->fetchColumn(),
    'down'   => (int)$pdo->query("SELECT COUNT(*) FROM price_history WHERE difference < 0")->fetchColumn(),
    'market' => (int)$pdo->query("SELECT COUNT(*) FROM price_history WHERE source IN ('Market', '" . MARKET_SYNC_SOURCE . "')")->fetchColumn(),
];

/* Biggest single move on record */
$biggest = $pdo->query(
    "SELECT fuel_type, difference, created_at FROM price_history ORDER BY ABS(difference) DESC, id DESC LIMIT 1"
)->fetch();

/* ==========================================================
 *  Price trend data for the chart (full history per fuel)
 * ========================================================= */
$fuels = $pdo->query("SELECT id, fuel_type, price_per_liter FROM fuel_inventory ORDER BY fuel_type")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();

$series = [];
foreach ($fuels as $f) {
    $sr = $pdo->prepare("SELECT created_at, new_price FROM price_history WHERE product_id = ? OR fuel_type = ? ORDER BY created_at ASC, id ASC");
    $sr->execute([(int)$f['id'], $f['fuel_type']]);
    $series[$f['fuel_type']] = $sr->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/includes/header.php';
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Changes</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $kpi['total'] ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-infinity mr-1"></i>append-only log</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-clock-rotate-left text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Increases / Decreases</p>
            <p class="text-2xl font-bold mt-1">
                <span class="text-emerald-400"><?= $kpi['up'] ?></span>
                <span class="text-slate-600 mx-1">/</span>
                <span class="text-red-400"><?= $kpi['down'] ?></span>
            </p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-arrows-up-down mr-1"></i><?= $kpi['market'] ?> from market feed</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-arrow-trend-up text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Active Prices Now</p>
            <p class="text-white text-2xl font-bold mt-1"><?= count($fuels) ?> fuels</p>
            <p class="text-slate-500 text-xs mt-2">
                <?php foreach ($fuels as $f): ?>
                <?= htmlspecialchars($f['fuel_type']) ?> $<?= money($f['price_per_liter']) ?><?= $f !== end($fuels) ? ' · ' : '' ?>
                <?php endforeach; ?>
            </p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-tag text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Largest Move</p>
            <?php if ($biggest): ?>
            <p class="text-white text-lg font-bold mt-1">
                <?= htmlspecialchars($biggest['fuel_type']) ?>
                <span class="<?= $biggest['difference'] > 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                    <?= $biggest['difference'] > 0 ? '+' : '-' ?>$<?= money(abs((float)$biggest['difference'])) ?>
                </span>
            </p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-calendar mr-1"></i><?= date('M d, Y', strtotime($biggest['created_at'])) ?></p>
            <?php else: ?>
            <p class="text-slate-500 text-2xl font-bold mt-1">—</p>
            <?php endif; ?>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500/20 to-purple-500/5 flex items-center justify-center">
            <i class="fa-solid fa-bolt text-purple-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- CHART -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-chart-line text-amber-400 mr-2"></i>Price Trend Over Time</h3>
    <?php if (empty($series) || !array_filter($series)): ?>
    <p class="text-slate-500 text-sm py-8 text-center">No price changes recorded yet.</p>
    <?php else: ?>
    <canvas id="priceTrend" height="110"></canvas>
    <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
        <div>
            <label class="text-slate-400 text-xs block mb-1">Fuel</label>
            <select name="product" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                <option value="0">All fuels</option>
                <?php foreach ($fuels as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $fProduct === (int)$f['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($f['fuel_type']) ?> (id <?= (int)$f['id'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Source</label>
            <select name="source" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                <option value="">All</option>
                <option value="Manual" <?= $fSource === 'Manual' ? 'selected' : '' ?>>Manual</option>
                <option value="Market" <?= $fSource === 'Market' ? 'selected' : '' ?>>Market (legacy)</option>
                <option value="<?= htmlspecialchars(MARKET_SYNC_SOURCE) ?>" <?= $fSource === MARKET_SYNC_SOURCE ? 'selected' : '' ?>><?= htmlspecialchars(MARKET_SYNC_SOURCE) ?></option>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Changed By</label>
            <select name="user" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                <option value="0">Anyone</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div class="flex items-center gap-2">
            <button class="flex-1 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white text-sm font-medium hover:from-amber-400 hover:to-amber-500 transition">
                <i class="fa-solid fa-filter mr-1"></i>Filter
            </button>
            <a href="price_history.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"
               class="px-3 py-2.5 rounded-xl bg-slate-700 border border-slate-600 text-slate-200 text-sm hover:border-amber-500/40 transition"
               title="Export these rows to CSV"><i class="fa-solid fa-file-csv"></i></a>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between flex-wrap gap-2">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-table-list text-cyan-400 mr-2"></i>Change Log <span class="text-slate-500 font-normal">(<?= number_format($totalRows) ?> rows)</span></h3>
        <a href="last_price.php" class="text-amber-400 text-xs hover:text-amber-300"><i class="fa-solid fa-tag mr-1"></i>Update a price</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">Changed At</th>
                    <th class="px-6 py-3 font-medium">Fuel</th>
                    <th class="px-6 py-3 font-medium">Product ID</th>
                    <th class="px-6 py-3 font-medium">Old Price</th>
                    <th class="px-6 py-3 font-medium">New Price</th>
                    <th class="px-6 py-3 font-medium">Difference</th>
                    <th class="px-6 py-3 font-medium">Source</th>
                    <th class="px-6 py-3 font-medium">Changed By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-tags text-3xl mb-3 block text-slate-600"></i>No price changes match the current filters.
                </td></tr>
                <?php else: foreach ($rows as $r):
                    $diff = (float)$r['difference'];
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-500 text-xs whitespace-nowrap"><?= date('M d, Y · H:i', strtotime($r['created_at'])) ?></td>
                    <td class="px-6 py-3 text-white font-medium"><?= htmlspecialchars($r['fuel_type']) ?></td>
                    <td class="px-6 py-3 text-slate-500 text-xs"><?= $r['product_id'] ? '#' . (int)$r['product_id'] : '—' ?></td>
                    <td class="px-6 py-3 text-slate-400">$<?= money($r['old_price']) ?></td>
                    <td class="px-6 py-3 text-slate-200 font-medium">$<?= money($r['new_price']) ?></td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border <?= $diff > 0 ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : ($diff < 0 ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-slate-700 text-slate-300 border-slate-600') ?>">
                            <i class="fa-solid <?= $diff > 0 ? 'fa-arrow-up' : ($diff < 0 ? 'fa-arrow-down' : 'fa-minus') ?>"></i>
                            <?= $diff > 0 ? '+' : ($diff < 0 ? '-' : '') ?>$<?= money(abs($diff)) ?>
                            <span class="text-[10px] opacity-70">(<?= $r['old_price'] > 0 ? (($diff / $r['old_price']) * 100 >= 0 ? '+' : '') . number_format(($diff / $r['old_price']) * 100, 2) . '%' : '—' ?>)</span>
                        </span>
                    </td>
                    <td class="px-6 py-3">
                        <?php if (in_array(($r['source'] ?? 'Manual'), ['Market', MARKET_SYNC_SOURCE], true)): ?>
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-cyan-500/10 text-cyan-400 border border-cyan-500/20"><i class="fa-solid fa-cloud mr-1"></i><?= htmlspecialchars($r['source']) ?><?php if (!empty($r['sync_run_id'])): ?> <span class="opacity-60">#<?= (int)$r['sync_run_id'] ?></span><?php endif; ?></span>
                        <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-700 text-slate-300 border border-slate-600">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-xs">
                        <?php if ($r['changed_by_id']): ?>
                        <span class="text-slate-300"><?= htmlspecialchars((string)($r['changer_name'] ?? $r['changed_by'])) ?></span>
                        <span class="text-slate-600 ml-1">#<?= (int)$r['changed_by_id'] ?></span>
                        <?php else: ?>
                        <span class="text-slate-500"><?= htmlspecialchars((string)$r['changed_by']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-slate-700 flex items-center justify-between flex-wrap gap-2">
        <p class="text-slate-500 text-xs">Page <?= $page ?> of <?= $totalPages ?></p>
        <div class="flex items-center gap-1">
            <?php
            $qs = $_GET;
            $link = function (int $p) use ($qs): string {
                $qs['page'] = $p;
                return 'price_history.php?' . htmlspecialchars(http_build_query($qs));
            };
            if ($page > 1): ?>
            <a href="<?= $link($page - 1) ?>" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs"><i class="fa-solid fa-chevron-left"></i></a>
            <?php endif;
            $start = max(1, $page - 3);
            $end   = min($totalPages, $page + 3);
            for ($i = $start; $i <= $end; $i++): ?>
            <a href="<?= $link($i) ?>" class="px-3 py-1.5 rounded-lg text-xs <?= $i === $page ? 'bg-amber-500 text-white font-semibold' : 'bg-slate-700 hover:bg-slate-600 text-slate-200' ?>"><?= $i ?></a>
            <?php endfor;
            if ($page < $totalPages): ?>
            <a href="<?= $link($page + 1) ?>" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs"><i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($series) && array_filter($series)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('priceTrend');
    if (!el || typeof Chart === 'undefined') { return; }
    var palette = { Petrol: '#f59e0b', Diesel: '#22d3ee', LPG: '#a78bfa' };
    var labels = [], series = {};
    var data = <?= json_encode($series, JSON_UNESCAPED_SLASHES) ?>;

    Object.keys(data).forEach(function (fuel) {
        series[fuel] = {};
        data[fuel].forEach(function (p) {
            var d = p.created_at.replace(' ', 'T');
            if (labels.indexOf(d) === -1) { labels.push(d); }
            series[fuel][d] = parseFloat(p.new_price);
        });
    });
    labels.sort();

    var datasets = Object.keys(series).map(function (fuel) {
        var last = null;
        return {
            label: fuel,
            data: labels.map(function (d) { if (series[fuel][d] !== undefined) { last = series[fuel][d]; } return last; }),
            borderColor: palette[fuel] || '#94a3b8',
            backgroundColor: (palette[fuel] || '#94a3b8') + '22',
            tension: 0.3, fill: false, spanGaps: true, pointRadius: 3
        };
    });

    new Chart(el, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true, interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#cbd5e1' } },
                tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': $' + c.parsed.y.toFixed(2); } } }
            },
            scales: {
                x: { ticks: { color: '#64748b', maxTicksLimit: 8, maxRotation: 0 }, grid: { color: 'rgba(148,163,184,.08)' } },
                y: { ticks: { color: '#64748b', callback: function (v) { return '$' + v; } }, grid: { color: 'rgba(148,163,184,.08)' } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
