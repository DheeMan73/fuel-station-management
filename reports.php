<?php
/**
 * Reports — daily / weekly / monthly sales summaries.
 */
require_once __DIR__ . '/config.php';

/* RBAC: financial reports are admin-only (hidden + hard-blocked for operators). */
require_admin();

$pageTitle    = 'Reports';
$pageSubtitle = 'Daily, weekly and monthly sales performance';

$period = $_GET['period'] ?? 'daily';
if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $period = 'daily';
}

/* ---- Window + grouping per period ---- */
if ($period === 'daily') {
    $interval   = "INTERVAL 13 DAY";
    $groupExpr  = "DATE(sold_at)";
    $limitNote  = "Last 14 days";
} elseif ($period === 'weekly') {
    $interval   = "INTERVAL 55 DAY";
    $groupExpr  = "YEARWEEK(sold_at, 1)";
    $limitNote  = "Last 8 weeks";
} else {
    $interval   = "INTERVAL 11 MONTH";
    $groupExpr  = "DATE_FORMAT(sold_at, '%Y-%m')";
    $limitNote  = "Last 12 months";
}

/* ---- Breakdown rows ---- */
$rows = $pdo->query(
    "SELECT $groupExpr AS bucket,
            MIN(sold_at)  AS first_at,
            COUNT(*)      AS tx,
            COALESCE(SUM(liters_sold), 0)  AS liters,
            COALESCE(SUM(total_amount), 0) AS revenue
     FROM sales
     WHERE sold_at >= DATE_SUB(CURDATE(), $interval)
     GROUP BY $groupExpr
     ORDER BY first_at DESC"
)->fetchAll();

/* ---- Window totals ---- */
$totals = $pdo->query(
    "SELECT COUNT(*) AS tx,
            COALESCE(SUM(liters_sold), 0)  AS liters,
            COALESCE(SUM(total_amount), 0) AS revenue
     FROM sales
     WHERE sold_at >= DATE_SUB(CURDATE(), $interval)"
)->fetch();

$totals['tx']      = (int)$totals['tx'];
$totals['liters']  = (float)$totals['liters'];
$totals['revenue'] = (float)$totals['revenue'];
$avgTx = $totals['tx'] > 0 ? $totals['revenue'] / $totals['tx'] : 0.0;

/* ---- Revenue by fuel within the window ---- */
$byFuel = $pdo->query(
    "SELECT fi.fuel_type,
            COUNT(s.id) AS tx,
            COALESCE(SUM(s.liters_sold), 0)  AS liters,
            COALESCE(SUM(s.total_amount), 0) AS revenue
     FROM fuel_inventory fi
     LEFT JOIN sales s ON s.fuel_inventory_id = fi.id
          AND s.sold_at >= DATE_SUB(CURDATE(), $interval)
     GROUP BY fi.id, fi.fuel_type
     ORDER BY revenue DESC"
)->fetchAll();

$maxRow = 1.0;
foreach ($rows as $r) {
    $maxRow = max($maxRow, (float)$r['revenue']);
}

include __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    #sidebar, header, footer, .no-print { display: none !important; }
    .lg\:ml-64 { margin-left: 0 !important; }
    body { background: #fff !important; }
}
</style>

<!-- Period tabs -->
<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="inline-flex bg-slate-800 border border-slate-700 rounded-xl p-1">
        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?>
        <a href="reports.php?period=<?= $key ?>"
           class="px-4 py-2 rounded-lg text-sm font-medium transition
                  <?= $period === $key ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid <?= $key === 'daily' ? 'fa-calendar-day' : ($key === 'weekly' ? 'fa-calendar-week' : 'fa-calendar-days') ?> mr-1.5"></i><?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-3 no-print">
        <span class="text-slate-500 text-xs"><?= $limitNote ?></span>
        <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-700 border border-slate-600 text-slate-200 text-sm
                                                hover:text-white hover:border-cyan-500/40 transition">
            <i class="fa-solid fa-print mr-1"></i> Print
        </button>
    </div>
</div>

<!-- Summary -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Revenue</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($totals['revenue']) ?></p>
        <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-sack-dollar mr-1"></i><?= $limitNote ?></p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Liters Sold</p>
        <p class="text-white text-2xl font-bold mt-1"><?= fmt($totals['liters']) ?> <span class="text-sm font-normal text-slate-400">L</span></p>
        <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-gas-pump mr-1"></i>All fuels</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Transactions</p>
        <p class="text-white text-2xl font-bold mt-1"><?= $totals['tx'] ?></p>
        <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-receipt mr-1"></i>Completed</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Avg / Transaction</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($avgTx) ?></p>
        <p class="text-purple-400 text-xs mt-2"><i class="fa-solid fa-calculator mr-1"></i>Per sale</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <!-- Breakdown -->
    <div class="lg:col-span-2 bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-table-list text-amber-400 mr-2"></i><?= ucfirst($period) ?> Breakdown</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                        <th class="px-6 py-3 font-medium"><?= $period === 'monthly' ? 'Month' : ($period === 'weekly' ? 'Week' : 'Day') ?></th>
                        <th class="px-6 py-3 font-medium">Transactions</th>
                        <th class="px-6 py-3 font-medium">Liters</th>
                        <th class="px-6 py-3 font-medium">Revenue</th>
                        <th class="px-6 py-3 font-medium">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">
                        <i class="fa-solid fa-chart-simple text-3xl mb-3 block text-slate-600"></i>No sales in this window.
                    </td></tr>
                    <?php else: foreach ($rows as $r):
                        $ts = strtotime((string)$r['first_at']);
                        if ($period === 'monthly')    $label = date('M Y', $ts);
                        elseif ($period === 'weekly') $label = 'Wk of ' . date('M d, Y', $ts);
                        else                          $label = date('D, M d Y', $ts);
                        $pct = $maxRow > 0 ? ((float)$r['revenue'] / $maxRow) * 100 : 0;
                    ?>
                    <tr class="hover:bg-slate-700/40 transition">
                        <td class="px-6 py-3 text-white font-medium"><?= htmlspecialchars($label) ?></td>
                        <td class="px-6 py-3 text-slate-300"><?= (int)$r['tx'] ?></td>
                        <td class="px-6 py-3 text-slate-300"><?= fmt($r['liters']) ?> L</td>
                        <td class="px-6 py-3 text-emerald-400 font-semibold">$<?= money($r['revenue']) ?></td>
                        <td class="px-6 py-3 w-40">
                            <div class="h-2 bg-slate-700 rounded-full overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-400" style="width:<?= round(min(100, $pct), 1) ?>%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot class="border-t border-slate-700 bg-slate-700/20">
                    <tr class="text-slate-300 font-medium">
                        <td class="px-6 py-3">Totals</td>
                        <td class="px-6 py-3"><?= $totals['tx'] ?></td>
                        <td class="px-6 py-3"><?= fmt($totals['liters']) ?> L</td>
                        <td class="px-6 py-3 text-emerald-400">$<?= money($totals['revenue']) ?></td>
                        <td class="px-6 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Revenue by fuel -->
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
        <h3 class="text-white font-semibold text-sm mb-5"><i class="fa-solid fa-chart-pie text-cyan-400 mr-2"></i>Revenue by Fuel</h3>
        <?php
        $fuelColors = [
            'Petrol' => ['bar' => 'from-orange-500 to-amber-500', 'text' => 'text-orange-400'],
            'Diesel' => ['bar' => 'from-cyan-500 to-blue-500',    'text' => 'text-cyan-400'],
            'LPG'    => ['bar' => 'from-purple-500 to-pink-500',  'text' => 'text-purple-400'],
        ];
        $totalRev = $totals['revenue'];
        foreach ($byFuel as $row):
            $rev = (float)$row['revenue'];
            $share = $totalRev > 0 ? ($rev / $totalRev) * 100 : 0;
            $fc = $fuelColors[$row['fuel_type']] ?? ['bar' => 'from-slate-500 to-slate-600', 'text' => 'text-slate-400'];
        ?>
        <div class="mb-5 last:mb-0">
            <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-2">
                    <span class="<?= $fc['text'] ?> font-medium text-sm"><?= htmlspecialchars($row['fuel_type']) ?></span>
                    <span class="text-slate-500 text-xs"><?= (int)$row['tx'] ?> sales</span>
                </div>
                <span class="text-white font-semibold text-sm">$<?= money($rev) ?></span>
            </div>
            <div class="h-2.5 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r <?= $fc['bar'] ?>" style="width:<?= round($share, 1) ?>%"></div>
            </div>
            <p class="text-slate-500 text-xs mt-1"><?= fmt($row['liters']) ?> L &mdash; <?= round($share, 1) ?>% of revenue</p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
