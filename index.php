<?php
/**
 * Dashboard — station overview (home page)
 */
require_once __DIR__ . '/config.php';

$pageTitle    = 'Dashboard';
$pageSubtitle = "Welcome back, " . ($_SESSION['full_name'] ?? 'User') . " — here's your station overview";

/* ---- KPIs ---- */
$todayRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$todayLiters  = (float)$pdo->query("SELECT COALESCE(SUM(liters_sold),0) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$todayTx      = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$allRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn();
$allTx        = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();

/* ---- Tanks ---- */
$tanks = $pdo->query("SELECT * FROM fuel_inventory ORDER BY id")->fetchAll();
$totalStock = 0.0; $stockValue = 0.0;
foreach ($tanks as $t) {
    $totalStock += (float)$t['current_stock_liters'];
    $stockValue += (float)$t['current_stock_liters'] * (float)$t['price_per_liter'];
}

/* ---- Recent activity ---- */
$recentSales = $pdo->query(
    "SELECT s.*, fi.fuel_type
     FROM sales s JOIN fuel_inventory fi ON fi.id = s.fuel_inventory_id
     ORDER BY s.sold_at DESC, s.id DESC LIMIT 8"
)->fetchAll();

$recentPrice = $pdo->query("SELECT * FROM price_history ORDER BY created_at DESC, id DESC LIMIT 6")->fetchAll();

/* ---- Recent audit activity (admins only) ---- */
$recentActivity = is_admin()
    ? $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 8")->fetchAll()
    : [];

/* ---- Suppliers / fuel purchases ---- */
$debt = supplier_debt_overview($pdo);
$recentPurchases = $pdo->query(
    "SELECT p.*, s.company_name
     FROM supplier_purchases p JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.created_at DESC, p.id DESC LIMIT 6"
)->fetchAll();

/* ---- Market prices (fuel price intelligence) ---- */
$marketFuelTypes = market_fuel_types($pdo);
$marketStaleDays = market_stale_days($pdo);
$marketRows      = market_current_rows($pdo);
$marketCompRows  = market_compare($pdo);
$marketDemoCount = 0;
try {
    $marketDemoCount = (int)$pdo->query("SELECT COUNT(*) FROM market_prices WHERE is_demo = 1")->fetchColumn();
} catch (Throwable $e) {
    $marketDemoCount = 0;
}

/* Newest observation per fuel (across all tracked cities). */
$marketByFuel = [];
foreach ($marketRows as $mRow) {
    $mFt = (string)$mRow['fuel_type'];
    if (!isset($marketByFuel[$mFt]) || strcmp((string)$mRow['price_date'], (string)$marketByFuel[$mFt]['price_date']) > 0) {
        $marketByFuel[$mFt] = $mRow;
    }
}

/* 30-day series per fuel — drives the dashboard sparklines. */
$marketSpark = [];
foreach ($marketFuelTypes as $mFt) {
    $vals = [];
    foreach (market_series($pdo, $mFt, null, 30) as $pt) {
        $vals[] = (float)$pt['price'];
    }
    $marketSpark[$mFt] = $vals;
}

/* Selling price / market delta per fuel. */
$marketVsSell = [];
foreach ($marketCompRows as $cRow) {
    $marketVsSell[(string)$cRow['fuel_type']] = $cRow;
}

/** Inline SVG sparkline (no chart library needed on the dashboard). */
$sparkline = static function (array $values, string $stroke): string {
    $n = count($values);
    if ($n < 2) {
        return '';
    }
    $min = min($values);
    $max = max($values);
    $range = ($max - $min) > 0 ? ($max - $min) : 1.0;
    $w = 120.0; $h = 30.0; $pad = 2.0;
    $pts = [];
    foreach ($values as $i => $v) {
        $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
        $y = $h - $pad - (($v - $min) / $range) * ($h - 2 * $pad);
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-full" style="height:2rem" preserveAspectRatio="none" aria-hidden="true">'
        . '<polyline fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/></svg>';
};
$marketColors = ['Petrol' => '#F59E0B', 'Diesel' => '#22D3EE', 'LPG' => '#A78BFA'];


/* ---- Shared lookups ---- */
$fuelGrad = [
    'Petrol' => 'from-orange-500 to-amber-500',
    'Diesel' => 'from-cyan-500 to-blue-500',
    'LPG'    => 'from-purple-500 to-pink-500',
];
$fuelText = [
    'Petrol' => 'text-orange-400',
    'Diesel' => 'text-cyan-400',
    'LPG'    => 'text-purple-400',
];
$statusMap = [
    'OK'   => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-circle-check',         'label' => 'OK'],
    'LOW'  => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-triangle-exclamation', 'label' => 'LOW STOCK'],
    'FULL' => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-circle-exclamation',   'label' => 'FULL'],
];

include __DIR__ . '/includes/header.php';
?>

<!-- KPI CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Today's Revenue</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($todayRevenue) ?></p>
            <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-receipt mr-1"></i><?= $todayTx ?> transactions</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-dollar-sign text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Liters Sold Today</p>
            <p class="text-white text-2xl font-bold mt-1"><?= fmt($todayLiters) ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i>Across all fuels</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-gas-pump text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Stock</p>
            <p class="text-white text-2xl font-bold mt-1"><?= fmt($totalStock, 0) ?> <span class="text-sm font-normal text-slate-400">L</span></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-warehouse mr-1"></i>Value $<?= money($stockValue) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-cubes-stacked text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">All-Time Revenue</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($allRevenue) ?></p>
            <p class="text-purple-400 text-xs mt-2"><i class="fa-solid fa-chart-line mr-1"></i><?= $allTx ?> total sales</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500/20 to-purple-500/5 flex items-center justify-center">
            <i class="fa-solid fa-sack-dollar text-purple-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- TANK OVERVIEW -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($tanks as $tank):
        $cap = (float)$tank['tank_capacity_liters'];
        $cur = (float)$tank['current_stock_liters'];
        $pct = $cap > 0 ? min(100, max(0, ($cur / $cap) * 100)) : 0;
        $st  = $statusMap[$tank['status']] ?? $statusMap['OK'];
        $grad = $fuelGrad[$tank['fuel_type']] ?? 'from-slate-500 to-slate-600';
    ?>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 transition">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $fuelGrad[$tank['fuel_type']] ?? 'from-slate-500 to-slate-600' ?> flex items-center justify-center">
                    <i class="fa-solid fa-gas-pump text-white"></i>
                </div>
                <div>
                    <h3 class="text-white font-semibold"><?= htmlspecialchars($tank['fuel_type']) ?></h3>
                    <p class="text-slate-500 text-xs">Tank #<?= (int)$tank['id'] ?></p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $statusMap[$tank['status']]['cls'] ?? $statusMap['OK']['cls'] ?>">
                <i class="fa-solid <?= $statusMap[$tank['status']]['icon'] ?? 'fa-circle-check' ?>"></i>
                <?= $statusMap[$tank['status']]['label'] ?? 'OK' ?>
            </span>
        </div>
        <div class="flex items-end justify-between text-sm mb-2">
            <span class="text-slate-400"><?= fmt($tank['current_stock_liters']) ?> / <?= fmt($tank['tank_capacity_liters'], 0) ?> L</span>
            <span class="text-amber-400 font-semibold">$<?= money($tank['price_per_liter']) ?>/L</span>
        </div>
        <div class="h-3 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r <?= $fuelGrad[$tank['fuel_type']] ?? 'from-slate-500 to-slate-600' ?>" style="width:<?= min(100, max(0, ((float)$tank['current_stock_liters'] / max(1, (float)$tank['tank_capacity_liters']))) * 100) ?>%"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

    <!-- Recent Sales -->
    <div class="xl:col-span-2 bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-clock-rotate-left text-cyan-400 mr-2"></i>Recent Sales</h3>
            <a href="sales.php" class="text-xs text-amber-400 hover:text-amber-300">View all <i class="fa-solid fa-arrow-right ml-1"></i></a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                        <th class="px-6 py-3 font-medium">Invoice</th>
                        <th class="px-6 py-3 font-medium">Fuel</th>
                        <th class="px-6 py-3 font-medium">Liters</th>
                        <th class="px-6 py-3 font-medium">Amount</th>
                        <th class="px-6 py-3 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php if (empty($recentSales)): ?>
                    <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">
                        <i class="fa-solid fa-receipt text-3xl mb-3 block text-slate-600"></i>No sales recorded yet.
                    </td></tr>
                    <?php else: foreach ($recentSales as $s): ?>
                    <tr class="hover:bg-slate-700/40 transition">
                        <td class="px-6 py-3">
                            <a href="invoice.php?id=<?= (int)$s['id'] ?>" class="text-cyan-400 hover:text-cyan-300 font-medium text-xs">
                                <?= htmlspecialchars($s['invoice_no'] ?: ('#' . $s['id'])) ?>
                            </a>
                        </td>
                        <td class="px-6 py-3"><span class="<?= $fuelText[$s['fuel_type']] ?? 'text-slate-300' ?> font-medium"><?= htmlspecialchars($s['fuel_type']) ?></span></td>
                        <td class="px-6 py-3 text-slate-300"><?= fmt($s['liters_sold']) ?> L</td>
                        <td class="px-6 py-3 text-emerald-400 font-semibold">$<?= money($s['total_amount']) ?></td>
                        <td class="px-6 py-3 text-slate-500 text-xs"><?= date('M d, Y H:i', strtotime($s['sold_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent price changes -->
    <div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-tags text-amber-400 mr-2"></i>Recent Price Changes</h3>
            <?php if (is_admin()): ?>
            <a href="last_price.php" class="text-xs text-cyan-400 hover:text-cyan-300">Manage</a>
            <?php endif; ?>
        </div>
        <div class="divide-y divide-slate-700">
            <?php if (empty($recentPrice)): ?>
            <p class="px-6 py-10 text-center text-slate-500 text-sm">No price changes logged.</p>
            <?php else: foreach ($recentPrice as $p):
                $diff = (float)$p['difference'];
                $badge = $diff > 0
                    ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                    : ($diff < 0 ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-slate-700 text-slate-300 border-slate-600');
                $sign = $diff > 0 ? '+' : ($diff < 0 ? '-' : '');
            ?>
            <div class="px-6 py-3 flex items-center justify-between">
                <div>
                    <p class="text-white text-sm font-medium"><?= htmlspecialchars($p['fuel_type']) ?></p>
                    <p class="text-slate-500 text-xs">$<?= money($p['old_price']) ?> → $<?= money($p['new_price']) ?></p>
                    <p class="text-slate-600 text-[11px] mt-0.5"><?= htmlspecialchars($p['changed_by']) ?> • <?= date('M d, H:i', strtotime($p['created_at'])) ?></p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold border <?= $badge ?>">
                    <?= $sign ?>$<?= money(abs($diff)) ?>
                </span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- SUPPLIERS / PURCHASES (admin only — these pages are blocked for operators) -->
<?php if (is_admin()): ?>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

    <!-- Recent deliveries -->
    <div class="xl:col-span-2 bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-truck-ramp-box text-amber-400 mr-2"></i>Recent Fuel Purchases</h3>
            <a href="purchases.php" class="text-xs text-amber-400 hover:text-amber-300">View all <i class="fa-solid fa-arrow-right ml-1"></i></a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                        <th class="px-6 py-3 font-medium">Purchase</th>
                        <th class="px-6 py-3 font-medium">Supplier</th>
                        <th class="px-6 py-3 font-medium">Fuel</th>
                        <th class="px-6 py-3 font-medium">Liters</th>
                        <th class="px-6 py-3 font-medium">Total</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php if (empty($recentPurchases)): ?>
                    <tr><td colspan="6" class="px-6 py-10 text-center text-slate-500">
                        <i class="fa-solid fa-truck-field text-2xl mb-2 block text-slate-600"></i>No supplier purchases recorded yet.
                    </td></tr>
                    <?php else: foreach ($recentPurchases as $rp):
                        $ps = purchase_status_meta($rp['status']);
                    ?>
                    <tr class="hover:bg-slate-700/40 transition">
                        <td class="px-6 py-3 text-slate-300 text-xs"><?= htmlspecialchars($rp['purchase_no'] ?: ('#' . $rp['id'])) ?></td>
                        <td class="px-6 py-3 text-white"><?= htmlspecialchars($rp['company_name']) ?></td>
                        <td class="px-6 py-3"><span class="<?= $fuelText[$rp['fuel_type']] ?? 'text-slate-300' ?> font-medium"><?= htmlspecialchars($rp['fuel_type']) ?></span></td>
                        <td class="px-6 py-3 text-slate-300"><?= fmt($rp['liters']) ?> L</td>
                        <td class="px-6 py-3 text-emerald-400 font-semibold">$<?= money($rp['total_cost']) ?></td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $ps['cls'] ?>">
                                <i class="fa-solid <?= $ps['icon'] ?>"></i> <?= $ps['label'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Supplier debt -->
    <div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-hand-holding-dollar text-red-400 mr-2"></i>Supplier Debt</h3>
            <a href="suppliers.php" class="text-xs text-cyan-400 hover:text-cyan-300">Suppliers</a>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <p class="text-slate-400 text-xs uppercase tracking-wider">Outstanding</p>
                <p class="text-2xl font-bold <?= $debt['outstanding'] > 0 ? 'text-red-400' : 'text-white' ?>">$<?= money($debt['outstanding']) ?></p>
                <p class="text-slate-500 text-xs mt-1"><?= $debt['unpaid_invoices'] ?> open invoice<?= $debt['unpaid_invoices'] === 1 ? '' : 's' ?></p>
            </div>
            <div class="grid grid-cols-2 gap-4 pt-2 border-t border-slate-700">
                <div>
                    <p class="text-slate-400 text-xs uppercase tracking-wider">Purchased</p>
                    <p class="text-white font-semibold">$<?= money($debt['purchases']) ?></p>
                </div>
                <div>
                    <p class="text-slate-400 text-xs uppercase tracking-wider">Paid</p>
                    <p class="text-emerald-400 font-semibold">$<?= money($debt['paid']) ?></p>
                </div>
            </div>
            <a href="supplier_reports.php?report=debts"
               class="block text-center px-4 py-2 rounded-xl bg-slate-700/60 border border-slate-600 text-slate-200 text-xs hover:text-white hover:border-cyan-500/40 transition">
                <i class="fa-solid fa-file-invoice mr-1"></i> Debt Report
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MARKET PRICES -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between flex-wrap gap-2">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-arrow-trend-up text-cyan-400 mr-2"></i>Market Prices</h3>
            <p class="text-slate-500 text-xs mt-0.5">
                Latest tracked fuel price per city · freshness window <?= $marketStaleDays ?> day(s)
            </p>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($marketDemoCount > 0): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-fuchsia-500/10 text-fuchsia-300 border border-fuchsia-500/30 text-[11px] font-semibold">
                <i class="fa-solid fa-flask"></i>DEMO
            </span>
            <?php endif; ?>
            <a href="market_prices.php" class="text-xs text-cyan-400 hover:text-cyan-300">Open module <i class="fa-solid fa-arrow-right ml-1"></i></a>
        </div>
    </div>

    <?php if (empty($marketByFuel)): ?>
    <div class="p-6 text-center text-slate-500 text-sm">
        <i class="fa-solid fa-arrow-trend-up text-2xl mb-2 block text-slate-600"></i>
        No market prices recorded yet.
        <a href="market_prices.php" class="text-amber-400 hover:text-amber-300">Record or import prices</a>.
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 divide-y sm:divide-y-0 divide-slate-700 xl:divide-x">
        <?php foreach ($marketFuelTypes as $mFt):
            $row  = $marketByFuel[$mFt] ?? null;
            if ($row === null) { continue; }
            $vals = $marketSpark[$mFt] ?? [];
            $comp = $marketVsSell[$mFt] ?? null;
            $up   = ($row['change'] ?? null) !== null && (float)$row['change'] > 0;
            $down = ($row['change'] ?? null) !== null && (float)$row['change'] < 0;
        ?>
        <div class="p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full" style="background: <?= htmlspecialchars($marketColors[$mFt] ?? '#22D3EE') ?>"></span>
                    <span class="text-white font-medium text-sm"><?= htmlspecialchars($mFt) ?></span>
                    <?php if (!empty($row['is_demo'])): ?><span class="text-fuchsia-300 text-[10px] font-semibold">DEMO</span><?php endif; ?>
                </div>
                <?php if (!empty($row['is_fresh'])): ?>
                    <span class="text-emerald-400 text-[11px]"><i class="fa-solid fa-circle-check mr-1"></i>Current</span>
                <?php else: ?>
                    <span class="text-amber-400 text-[11px]"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Stale</span>
                <?php endif; ?>
            </div>

            <div class="mt-2 flex items-end justify-between gap-3">
                <div>
                    <p class="text-2xl font-bold text-white">$<?= money($row['price_per_liter']) ?><span class="text-slate-500 text-xs font-normal ml-1">/ L</span></p>
                    <?php if ($row['change'] === null): ?>
                        <p class="text-slate-500 text-xs mt-0.5">first observation · <?= htmlspecialchars((string)$row['city']) ?></p>
                    <?php else:
                        $pct = (float)$row['change_pct']; ?>
                        <p class="text-xs mt-0.5 <?= $up ? 'text-emerald-400' : ($down ? 'text-red-400' : 'text-slate-400') ?>">
                            <i class="fa-solid <?= $up ? 'fa-arrow-trend-up' : ($down ? 'fa-arrow-trend-down' : 'fa-equals') ?> mr-1"></i>
                            <?= $up ? '+' : ($down ? '-' : '') ?>$<?= money(abs((float)$row['change'])) ?>
                            (<?= $up ? '+' : ($down ? '-' : '') ?><?= number_format(abs($pct), 2) ?>%)
                            <span class="text-slate-500">· <?= htmlspecialchars((string)$row['city']) ?></span>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="w-24 flex-shrink-0">
                    <?= $sparkline($vals, $marketColors[$mFt] ?? '#22D3EE') ?>
                </div>
            </div>

            <?php if ($comp && $comp['vs_market'] !== null): ?>
            <p class="text-[11px] text-slate-500 mt-2 pt-2 border-t border-slate-700">
                Your price <span class="text-slate-300">$<?= money($comp['selling_price']) ?></span>
                <span class="<?= $comp['vs_market'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                    (<?= $comp['vs_market'] >= 0 ? '+' : '-' ?>$<?= money(abs((float)$comp['vs_market'])) ?> vs market)
                </span>
            </p>
            <?php endif; ?>

            <p class="text-[11px] text-slate-500 mt-1">
                <i class="fa-solid fa-tower-broadcast mr-1"></i><?= htmlspecialchars((string)$row['source']) ?>
                · <?= htmlspecialchars(date('M j', strtotime((string)$row['price_date']))) ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (is_admin()): ?>
<!-- RECENT AUDIT ACTIVITY -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-shield-halved text-amber-400 mr-2"></i>Recent Activity</h3>
        <a href="activity_logs.php" class="text-xs text-cyan-400 hover:text-cyan-300">Full audit trail <i class="fa-solid fa-arrow-right ml-1"></i></a>
    </div>
    <div class="divide-y divide-slate-700">
        <?php if (empty($recentActivity)): ?>
        <p class="px-6 py-10 text-center text-slate-500 text-sm">
            <i class="fa-solid fa-shield-halved text-2xl mb-2 block text-slate-600"></i>No activity recorded yet.
        </p>
        <?php else: foreach ($recentActivity as $log):
            $m = activity_meta($log['action']);
        ?>
        <div class="px-6 py-3 flex items-start gap-3">
            <span class="mt-0.5 w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 border <?= $m['cls'] ?>">
                <i class="fa-solid <?= $m['icon'] ?> text-xs"></i>
            </span>
            <div class="flex-1 min-w-0">
                <p class="text-slate-200 text-sm">
                    <span class="text-white font-medium"><?= htmlspecialchars($log['user_name']) ?></span>
                    <span class="text-slate-500 text-xs ml-1">(<?= htmlspecialchars(role_label($log['role'] ?? null)) ?>)</span>
                </p>
                <p class="text-slate-400 text-xs mt-0.5"><?= htmlspecialchars($log['description'] ?? '') ?></p>
            </div>
            <span class="text-slate-500 text-[11px] whitespace-nowrap mt-1"><?= date('M d, H:i', strtotime($log['created_at'])) ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
