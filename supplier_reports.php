<?php
/**
 * Supplier Reports — fuel purchase report, delivery report,
 * debt report and per-supplier statements.
 */
require_once __DIR__ . '/config.php';

/* RBAC: supplier reports are admin-only (hidden + hard-blocked for operators). */
require_admin();

$pageTitle    = 'Supplier Reports';
$pageSubtitle = 'Purchase, delivery, debt and statement reports';

$report = trim((string)($_GET['report'] ?? 'purchases'));
if (!in_array($report, ['purchases', 'deliveries', 'debts', 'statement'], true)) {
    $report = 'purchases';
}

$fuelText = [
    'Petrol' => 'text-orange-400',
    'Diesel' => 'text-cyan-400',
    'LPG'    => 'text-purple-400',
];

$fSupplier = (int)($_GET['supplier_id'] ?? 0);
$fFrom     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$fTo       = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? (string)$_GET['to']   : '';

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY company_name ASC")->fetchAll();

/* ==========================================================
 *  Purchases report
 * ========================================================= */
$purchaseRows = [];
$purchaseTotals = ['value' => 0.0, 'liters' => 0.0, 'count' => 0];
if ($report === 'purchases') {
    $where = ["p.status <> 'cancelled'"]; $params = [];
    if ($fSupplier > 0) { $where[] = 'p.supplier_id = ?'; $params[] = $fSupplier; }
    if ($fFrom !== '')  { $where[] = 'p.delivery_date >= ?'; $params[] = $fFrom; }
    if ($fTo !== '')    { $where[] = 'p.delivery_date <= ?'; $params[] = $fTo; }
    $sql = "SELECT p.*, s.company_name FROM supplier_purchases p JOIN suppliers s ON s.id = p.supplier_id
            WHERE " . implode(' AND ', $where) . " ORDER BY p.delivery_date DESC, p.id DESC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $purchaseRows = $st->fetchAll();

    /* By fuel summary */
    $byFuel = $pdo->prepare(
        "SELECT p.fuel_type, COUNT(*) AS cnt, COALESCE(SUM(p.liters),0) AS liters, COALESCE(SUM(p.total_cost),0) AS value
         FROM supplier_purchases p
         WHERE " . implode(' AND ', $where) . "
         GROUP BY p.fuel_type ORDER BY value DESC"
    );
    $byFuel->execute($params);
    $purchaseByFuel = $byFuel->fetchAll();

    foreach ($purchaseRows as $r) {
        $purchaseTotals['value']  += (float)$r['total_cost'];
        $purchaseTotals['liters'] += (float)$r['liters'];
        $purchaseTotals['count']++;
    }
}

/* ==========================================================
 *  Delivery report
 * ========================================================= */
$deliveryRows = []; $deliveryLiters = 0.0;
if ($report === 'deliveries') {
    $where = ["p.status = 'confirmed'"]; $params = [];
    if ($fSupplier > 0) { $where[] = 'p.supplier_id = ?'; $params[] = $fSupplier; }
    if ($fFrom !== '')  { $where[] = 'p.delivery_date >= ?'; $params[] = $fFrom; }
    if ($fTo !== '')    { $where[] = 'p.delivery_date <= ?'; $params[] = $fTo; }
    $sql = "SELECT p.*, s.company_name FROM supplier_purchases p JOIN suppliers s ON s.id = p.supplier_id
            WHERE " . implode(' AND ', $where) . " ORDER BY p.delivery_date DESC, p.id DESC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $deliveryRows = $st->fetchAll();
    foreach ($deliveryRows as $r) { $deliveryLiters += (float)$r['liters']; }
}

/* ==========================================================
 *  Debt report
 * ========================================================= */
$debtRows = []; $debtTotals = ['purchases' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
if ($report === 'debts') {
    $debtRows = $pdo->query(
        "SELECT s.id, s.company_name, s.contact_person, s.phone, s.is_active,
                COALESCE(p.total,0) AS total_purchases,
                COALESCE(p.cnt,0)   AS purchase_count,
                COALESCE(pay.paid,0) AS total_paid
         FROM suppliers s
         LEFT JOIN (
             SELECT supplier_id, COALESCE(SUM(total_cost),0) AS total, COUNT(*) AS cnt
             FROM supplier_purchases WHERE status <> 'cancelled' GROUP BY supplier_id
         ) p ON p.supplier_id = s.id
         LEFT JOIN (
             SELECT supplier_id, COALESCE(SUM(amount),0) AS paid
             FROM supplier_payments GROUP BY supplier_id
         ) pay ON pay.supplier_id = s.id
         ORDER BY (COALESCE(p.total,0) - COALESCE(pay.paid,0)) DESC, s.company_name ASC"
    )->fetchAll();
    foreach ($debtRows as $r) {
        $debtTotals['purchases']   += (float)$r['total_purchases'];
        $debtTotals['paid']        += (float)$r['total_paid'];
        $debtTotals['outstanding'] += (float)$r['total_purchases'] - (float)$r['total_paid'];
    }
}

/* ==========================================================
 *  Supplier statement
 * ========================================================= */
$statementSupplier = null;
$statementEntries  = [];
$statementTotals   = ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
if ($report === 'statement' && $fSupplier > 0) {
    $s = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $s->execute([$fSupplier]);
    $statementSupplier = $s->fetch() ?: null;

    if ($statementSupplier) {
        $purch = $pdo->prepare("SELECT * FROM supplier_purchases WHERE supplier_id = ? AND status <> 'cancelled'");
        $purch->execute([$fSupplier]);
        foreach ($purch->fetchAll() as $p) {
            $statementEntries[] = [
                'date'  => $p['delivery_date'] ?: substr((string)$p['created_at'], 0, 10),
                'type'  => 'Purchase',
                'ref'   => $p['purchase_no'] ?: ('#' . $p['id']),
                'desc'  => $p['liters'] . ' L ' . $p['fuel_type'] . ($p['invoice_no'] ? ' • inv ' . $p['invoice_no'] : ''),
                'debit' => (float)$p['total_cost'],
                'credit'=> 0.0,
            ];
        }
        $pays = $pdo->prepare(
            "SELECT sp.*, p.purchase_no FROM supplier_payments sp
             LEFT JOIN supplier_purchases p ON p.id = sp.purchase_id
             WHERE sp.supplier_id = ?"
        );
        $pays->execute([$fSupplier]);
        foreach ($pays->fetchAll() as $p) {
            $statementEntries[] = [
                'date'  => $p['payment_date'] ?: substr((string)$p['created_at'], 0, 10),
                'type'  => 'Payment',
                'ref'   => $p['payment_no'] ?: ('#' . $p['id']),
                'desc'  => $p['method'] . ($p['reference'] ? ' • ' . $p['reference'] : '') . ($p['purchase_no'] ? ' • ' . $p['purchase_no'] : ''),
                'debit' => 0.0,
                'credit'=> (float)$p['amount'],
            ];
        }

        /* Date filter */
        if ($fFrom !== '' || $fTo !== '') {
            $statementEntries = array_values(array_filter($statementEntries, function ($e) use ($fFrom, $fTo) {
                if ($fFrom !== '' && $e['date'] < $fFrom) return false;
                if ($fTo !== ''   && $e['date'] > $fTo)   return false;
                return true;
            }));
        }

        usort($statementEntries, function ($a, $b) {
            if ($a['date'] === $b['date']) return strcmp($a['type'], $b['type']) === 0 ? 0 : ($a['type'] === 'Purchase' ? -1 : 1);
            return strcmp($a['date'], $b['date']);
        });

        foreach ($statementEntries as $e) {
            $statementTotals['debit']  += $e['debit'];
            $statementTotals['credit'] += $e['credit'];
        }
        $statementTotals['balance'] = round($statementTotals['debit'] - $statementTotals['credit'], 2);
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    #sidebar, header, footer, .no-print { display: none !important; }
    .lg\:ml-64 { margin-left: 0 !important; }
    body { background: #fff !important; }
    main { padding: 0 !important; }
}
</style>

<!-- MODULE TABS -->
<?php $smPage = basename($_SERVER['PHP_SELF']); ?>
<div class="flex flex-wrap items-center justify-between gap-3 no-print">
    <div class="inline-flex flex-wrap bg-slate-800 border border-slate-700 rounded-xl p-1">
        <a href="suppliers.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'suppliers.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid fa-truck-field mr-1.5"></i>Suppliers
        </a>
        <a href="purchases.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'purchases.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid fa-file-invoice-dollar mr-1.5"></i>Purchases
        </a>
        <a href="supplier_reports.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'supplier_reports.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid fa-chart-column mr-1.5"></i>Reports
        </a>
    </div>
    <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-700 border border-slate-600 text-slate-200 text-sm hover:text-white hover:border-cyan-500/40 transition">
        <i class="fa-solid fa-print mr-1"></i> Print
    </button>
</div>

<!-- REPORT PICKER -->
<div class="inline-flex flex-wrap bg-slate-800 border border-slate-700 rounded-xl p-1 no-print">
    <?php
    $tabs = [
        'purchases'  => ['fa-cart-flatbed',        'Fuel Purchases'],
        'deliveries' => ['fa-truck-ramp-box',      'Deliveries'],
        'debts'      => ['fa-hand-holding-dollar', 'Debt Report'],
        'statement'  => ['fa-file-lines',          'Supplier Statement'],
    ];
    foreach ($tabs as $key => $t): ?>
    <a href="supplier_reports.php?report=<?= $key ?><?= $fSupplier ? '&supplier_id=' . $fSupplier : '' ?>"
       class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $report === $key ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
        <i class="fa-solid <?= $t[0] ?> mr-1.5"></i><?= $t[1] ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- FILTERS -->
<form method="GET" class="bg-slate-800 border border-slate-700 rounded-2xl p-4 flex flex-wrap items-end gap-3 no-print">
    <input type="hidden" name="report" value="<?= htmlspecialchars($report) ?>">
    <div>
        <label class="text-slate-400 text-xs block mb-1">Supplier</label>
        <select name="supplier_id" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500 min-w-[200px]">
            <option value="0"><?= $report === 'statement' ? 'Select supplier…' : 'All suppliers' ?></option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $fSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['company_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="text-slate-400 text-xs block mb-1">From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500">
    </div>
    <div>
        <label class="text-slate-400 text-xs block mb-1">To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500">
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">
        <i class="fa-solid fa-filter mr-1"></i>Apply
    </button>
</form>

<?php /* ===================== PURCHASES REPORT ===================== */ ?>
<?php if ($report === 'purchases'): ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Purchase Value</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($purchaseTotals['value']) ?></p>
        <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-cart-flatbed mr-1"></i>Fuel expenditure</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Liters Purchased</p>
        <p class="text-white text-2xl font-bold mt-1"><?= fmt($purchaseTotals['liters']) ?> <span class="text-sm font-normal text-slate-400">L</span></p>
        <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i><?= $purchaseTotals['count'] ?> purchases</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Avg Cost / Liter</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($purchaseTotals['liters'] > 0 ? $purchaseTotals['value'] / $purchaseTotals['liters'] : 0) ?></p>
        <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-calculator mr-1"></i>Across all fuels</p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
    <div class="xl:col-span-2 bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-cart-flatbed text-amber-400 mr-2"></i>Fuel Purchase Report</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                        <th class="px-5 py-3 font-medium">Date</th>
                        <th class="px-5 py-3 font-medium">Purchase</th>
                        <th class="px-5 py-3 font-medium">Supplier</th>
                        <th class="px-5 py-3 font-medium">Fuel</th>
                        <th class="px-5 py-3 font-medium">Liters</th>
                        <th class="px-5 py-3 font-medium">Cost/L</th>
                        <th class="px-5 py-3 font-medium">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php if (empty($purchaseRows)): ?>
                    <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">No purchases in this range.</td></tr>
                    <?php else: foreach ($purchaseRows as $p): ?>
                    <tr class="hover:bg-slate-700/40 transition">
                        <td class="px-5 py-3 text-slate-400 text-xs"><?= $p['delivery_date'] ? date('M d, Y', strtotime($p['delivery_date'])) : '—' ?></td>
                        <td class="px-5 py-3 text-slate-300 text-xs"><?= htmlspecialchars($p['purchase_no'] ?: ('#' . $p['id'])) ?></td>
                        <td class="px-5 py-3 text-white"><?= htmlspecialchars($p['company_name']) ?></td>
                        <td class="px-5 py-3"><span class="<?= $fuelText[$p['fuel_type']] ?? 'text-slate-300' ?> font-medium"><?= htmlspecialchars($p['fuel_type']) ?></span></td>
                        <td class="px-5 py-3 text-slate-300"><?= fmt($p['liters']) ?></td>
                        <td class="px-5 py-3 text-slate-400">$<?= money($p['cost_per_liter']) ?></td>
                        <td class="px-5 py-3 text-white font-semibold">$<?= money($p['total_cost']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot class="border-t border-slate-700 bg-slate-700/20">
                    <tr class="text-slate-300 font-medium">
                        <td class="px-5 py-3" colspan="4">Totals</td>
                        <td class="px-5 py-3"><?= fmt($purchaseTotals['liters']) ?> L</td>
                        <td class="px-5 py-3"></td>
                        <td class="px-5 py-3 text-emerald-400">$<?= money($purchaseTotals['value']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
        <h3 class="text-white font-semibold text-sm mb-5"><i class="fa-solid fa-chart-pie text-cyan-400 mr-2"></i>Purchases by Fuel</h3>
        <?php if (empty($purchaseByFuel)): ?>
        <p class="text-slate-500 text-sm">No data.</p>
        <?php else:
            $maxV = 1.0; foreach ($purchaseByFuel as $b) { $maxV = max($maxV, (float)$b['value']); }
            foreach ($purchaseByFuel as $b):
                $pct = $maxV > 0 ? ((float)$b['value'] / $maxV) * 100 : 0;
        ?>
        <div class="mb-5 last:mb-0">
            <div class="flex items-center justify-between mb-1.5">
                <span class="<?= $fuelText[$b['fuel_type']] ?? 'text-slate-300' ?> font-medium text-sm"><?= htmlspecialchars($b['fuel_type']) ?></span>
                <span class="text-white font-semibold text-sm">$<?= money($b['value']) ?></span>
            </div>
            <div class="h-2.5 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-400" style="width:<?= round(min(100, $pct), 1) ?>%"></div>
            </div>
            <p class="text-slate-500 text-xs mt-1"><?= fmt($b['liters']) ?> L &mdash; <?= (int)$b['cnt'] ?> purchases</p>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- DELIVERY REPORT -->
<?php if ($report === 'deliveries'): ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Confirmed Deliveries</p>
        <p class="text-white text-2xl font-bold mt-1"><?= count($deliveryRows) ?></p>
        <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-circle-check mr-1"></i>Verified &amp; stocked</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Liters Delivered</p>
        <p class="text-white text-2xl font-bold mt-1"><?= fmt($deliveryLiters) ?> <span class="text-sm font-normal text-slate-400">L</span></p>
        <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i>Added to tanks</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Avg / Delivery</p>
        <p class="text-white text-2xl font-bold mt-1"><?= fmt(count($deliveryRows) > 0 ? $deliveryLiters / count($deliveryRows) : 0) ?> <span class="text-sm font-normal text-slate-400">L</span></p>
        <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-calculator mr-1"></i>Per confirmed load</p>
    </div>
</div>

<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-truck-ramp-box text-cyan-400 mr-2"></i>Delivery Report &mdash; Tanker &amp; References</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">Delivery Date</th>
                    <th class="px-5 py-3 font-medium">Supplier</th>
                    <th class="px-5 py-3 font-medium">Fuel</th>
                    <th class="px-5 py-3 font-medium">Liters</th>
                    <th class="px-5 py-3 font-medium">Tanker</th>
                    <th class="px-5 py-3 font-medium">Driver</th>
                    <th class="px-5 py-3 font-medium">Delivery Ref</th>
                    <th class="px-5 py-3 font-medium">Invoice</th>
                    <th class="px-5 py-3 font-medium">Verified By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($deliveryRows)): ?>
                <tr><td colspan="9" class="px-6 py-12 text-center text-slate-500">No confirmed deliveries in this range.</td></tr>
                <?php else: foreach ($deliveryRows as $d): ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= $d['delivery_date'] ? date('M d, Y', strtotime($d['delivery_date'])) : '—' ?></td>
                    <td class="px-5 py-3 text-white"><?= htmlspecialchars($d['company_name']) ?></td>
                    <td class="px-5 py-3"><span class="<?= $fuelText[$d['fuel_type']] ?? 'text-slate-300' ?> font-medium"><?= htmlspecialchars($d['fuel_type']) ?></span></td>
                    <td class="px-5 py-3 text-slate-300"><?= fmt($d['liters']) ?> L</td>
                    <td class="px-5 py-3 text-slate-300"><?= htmlspecialchars($d['tanker_no'] ?: '—') ?></td>
                    <td class="px-5 py-3 text-slate-300">
                        <?= htmlspecialchars($d['driver_name'] ?: '—') ?>
                        <?php if (!empty($d['driver_phone'])): ?><span class="text-slate-600 text-[11px] block"><?= htmlspecialchars($d['driver_phone']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($d['delivery_ref'] ?: '—') ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($d['invoice_no'] ?: '—') ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($d['verified_by'] ?: '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="border-t border-slate-700 bg-slate-700/20">
                <tr class="text-slate-300 font-medium">
                    <td class="px-5 py-3" colspan="3">Totals</td>
                    <td class="px-5 py-3"><?= fmt($deliveryLiters) ?> L</td>
                    <td class="px-5 py-3" colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- DEBT REPORT -->
<?php if ($report === 'debts'): ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Purchases</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($debtTotals['purchases']) ?></p>
        <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-cart-flatbed mr-1"></i>All suppliers</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Paid</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($debtTotals['paid']) ?></p>
        <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-money-bill-transfer mr-1"></i>Settled</p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Outstanding Debt</p>
        <p class="text-2xl font-bold mt-1 <?= $debtTotals['outstanding'] > 0 ? 'text-red-400' : 'text-white' ?>">$<?= money($debtTotals['outstanding']) ?></p>
        <p class="text-red-400 text-xs mt-2"><i class="fa-solid fa-hand-holding-dollar mr-1"></i>Owed to suppliers</p>
    </div>
</div>

<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-hand-holding-dollar text-red-400 mr-2"></i>Supplier Debt Report</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">Supplier</th>
                    <th class="px-6 py-3 font-medium">Contact</th>
                    <th class="px-6 py-3 font-medium">Purchases</th>
                    <th class="px-6 py-3 font-medium">Paid</th>
                    <th class="px-6 py-3 font-medium">Outstanding</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($debtRows)): ?>
                <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No suppliers registered.</td></tr>
                <?php else: foreach ($debtRows as $r):
                    $owed = round((float)$r['total_purchases'] - (float)$r['total_paid'], 2);
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-white font-medium"><?= htmlspecialchars($r['company_name']) ?></td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars($r['contact_person'] ?: ($r['phone'] ?: '—')) ?></td>
                    <td class="px-6 py-3 text-slate-300">$<?= money($r['total_purchases']) ?></td>
                    <td class="px-6 py-3 text-emerald-400">$<?= money($r['total_paid']) ?></td>
                    <td class="px-6 py-3 font-semibold <?= $owed > 0.004 ? 'text-red-400' : 'text-slate-400' ?>">$<?= money($owed) ?></td>
                    <td class="px-6 py-3">
                        <?php if ($owed > 0.004): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-400 border border-red-500/20"><i class="fa-solid fa-circle-exclamation"></i> Owes</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><i class="fa-solid fa-circle-check"></i> Settled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="border-t border-slate-700 bg-slate-700/20">
                <tr class="text-slate-300 font-medium">
                    <td class="px-6 py-3" colspan="2">Totals</td>
                    <td class="px-6 py-3">$<?= money($debtTotals['purchases']) ?></td>
                    <td class="px-6 py-3 text-emerald-400">$<?= money($debtTotals['paid']) ?></td>
                    <td class="px-6 py-3 text-red-400">$<?= money($debtTotals['outstanding']) ?></td>
                    <td class="px-6 py-3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- SUPPLIER STATEMENT -->
<?php if ($report === 'statement'): ?>
<?php if (!$statementSupplier): ?>
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-10 text-center text-slate-500">
    <i class="fa-solid fa-file-lines text-3xl mb-3 block text-slate-600"></i>
    Select a supplier above to generate a statement.
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Purchases</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($statementTotals['debit']) ?></p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Paid</p>
        <p class="text-white text-2xl font-bold mt-1">$<?= money($statementTotals['credit']) ?></p>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Balance Due</p>
        <p class="text-2xl font-bold mt-1 <?= $statementTotals['balance'] > 0.004 ? 'text-red-400' : 'text-white' ?>">$<?= money($statementTotals['balance']) ?></p>
    </div>
</div>

<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-700 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h3 class="text-white font-semibold"><i class="fa-solid fa-file-invoice text-amber-400 mr-2"></i>Supplier Statement</h3>
            <p class="text-slate-300 text-sm mt-2 font-medium"><?= htmlspecialchars($statementSupplier['company_name']) ?></p>
            <p class="text-slate-500 text-xs">
                <?= htmlspecialchars($statementSupplier['contact_person'] ?: '—') ?>
                <?= !empty($statementSupplier['phone']) ? ' • ' . htmlspecialchars($statementSupplier['phone']) : '' ?>
                <?= !empty($statementSupplier['email']) ? ' • ' . htmlspecialchars($statementSupplier['email']) : '' ?>
            </p>
            <?php if (!empty($statementSupplier['address'])): ?>
            <p class="text-slate-600 text-xs mt-0.5"><?= htmlspecialchars($statementSupplier['address']) ?></p>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <p class="text-slate-500 text-xs">Statement Date</p>
            <p class="text-white text-sm"><?= date('M d, Y H:i') ?></p>
            <?php if ($fFrom !== '' || $fTo !== ''): ?>
            <p class="text-slate-500 text-xs mt-1"><?= $fFrom !== '' ? htmlspecialchars($fFrom) : 'Start' ?> &rarr; <?= $fTo !== '' ? htmlspecialchars($fTo) : 'Today' ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">Date</th>
                    <th class="px-6 py-3 font-medium">Type</th>
                    <th class="px-6 py-3 font-medium">Reference</th>
                    <th class="px-6 py-3 font-medium">Description</th>
                    <th class="px-6 py-3 font-medium text-right">Debit</th>
                    <th class="px-6 py-3 font-medium text-right">Credit</th>
                    <th class="px-6 py-3 font-medium text-right">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($statementEntries)): ?>
                <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">No transactions for this supplier.</td></tr>
                <?php else: $run = 0.0; foreach ($statementEntries as $e):
                    $run = round($run + $e['debit'] - $e['credit'], 2);
                    $isPay = $e['type'] === 'Payment';
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= date('M d, Y', strtotime($e['date'])) ?></td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $isPay ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20' ?>">
                            <i class="fa-solid <?= $isPay ? 'fa-money-bill-transfer' : 'fa-cart-flatbed' ?>"></i> <?= $isPay ? 'Payment' : 'Purchase' ?>
                        </span>
                    </td>
                    <td class="px-6 py-3 text-slate-300 text-xs"><?= htmlspecialchars($e['ref']) ?></td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars($e['desc']) ?></td>
                    <td class="px-6 py-3 text-right text-red-300"><?= $e['debit'] > 0 ? '$' . money($e['debit']) : '—' ?></td>
                    <td class="px-6 py-3 text-right text-emerald-300"><?= $e['credit'] > 0 ? '$' . money($e['credit']) : '—' ?></td>
                    <td class="px-6 py-3 text-right text-white font-medium">$<?= money($run) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="border-t border-slate-700 bg-slate-700/20">
                <tr class="text-slate-300 font-medium">
                    <td class="px-6 py-3" colspan="4">Closing Balance</td>
                    <td class="px-6 py-3 text-right text-red-300">$<?= money($statementTotals['debit']) ?></td>
                    <td class="px-6 py-3 text-right text-emerald-300">$<?= money($statementTotals['credit']) ?></td>
                    <td class="px-6 py-3 text-right text-red-400">$<?= money($statementTotals['balance']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
