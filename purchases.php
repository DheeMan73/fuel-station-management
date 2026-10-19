<?php
/**
 * Fuel Purchases — record supplier deliveries, confirm them into the
 * tanks, and track payments.
 *
 * Stock is only added to a tank when a delivery is explicitly
 * verified & confirmed, and only ever once per purchase (duplicate
 * confirmations are guarded by the `stock_applied` flag + status).
 */
require_once __DIR__ . '/config.php';

/* RBAC: Purchases management is admin-only (hidden + hard-blocked for operators). */
require_admin();

$pageTitle    = 'Fuel Purchases';
$pageSubtitle = 'Record supplier deliveries, confirm stock and track payments';

$fuelGrad = [
    'Petrol' => 'from-orange-500 to-amber-500',
    'Diesel' => 'from-cyan-500 to-blue-500',
    'LPG'    => 'from-purple-500 to-pink-500',
];
$paymentMethods = ['Cash', 'Bank Transfer', 'Cheque', 'Other'];

/** Parse a Y-m-d date, returning null when empty/invalid. */
$parseDate = function ($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
};

/* ==========================================================
 *  Record a purchase
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_purchase') {
    verify_csrf();

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $tankId     = (int)($_POST['fuel_inventory_id'] ?? 0);
    $liters     = parse_decimal($_POST['liters'] ?? '', 2);
    $cost       = parse_decimal($_POST['cost_per_liter'] ?? '', 2);
    $invoice    = trim((string)($_POST['invoice_no'] ?? ''));
    $delivDate  = $parseDate($_POST['delivery_date'] ?? '') ?? date('Y-m-d');
    $payStatus  = trim((string)($_POST['pay_status'] ?? 'Unpaid'));
    $payMethod  = trim((string)($_POST['pay_method'] ?? 'Cash'));
    $amountPaid = parse_decimal($_POST['amount_paid'] ?? '', 2, true);
    $tankerNo   = trim((string)($_POST['tanker_no'] ?? ''));
    $driver     = trim((string)($_POST['driver_name'] ?? ''));
    $driverPh   = trim((string)($_POST['driver_phone'] ?? ''));
    $delivRef   = trim((string)($_POST['delivery_ref'] ?? ''));
    $notes      = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($payStatus, ['Unpaid', 'Partial', 'Paid'], true)) $payStatus = 'Unpaid';
    if (!in_array($payMethod, $paymentMethods, true))              $payMethod = 'Cash';
    if (mb_strlen($invoice)  > 60)  $invoice  = mb_substr($invoice, 0, 60);
    if (mb_strlen($tankerNo) > 40)  $tankerNo = mb_substr($tankerNo, 0, 40);
    if (mb_strlen($driver)   > 100) $driver   = mb_substr($driver, 0, 100);
    if (mb_strlen($driverPh) > 30)  $driverPh = mb_substr($driverPh, 0, 30);
    if (mb_strlen($delivRef) > 60)  $delivRef = mb_substr($delivRef, 0, 60);

    $tank = null;
    if ($tankId > 0) {
        $t = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ?");
        $t->execute([$tankId]);
        $tank = $t->fetch() ?: null;
    }
    $sup = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $sup->execute([$supplierId]);
    $supplier = $sup->fetch();

    if (!$supplier) {
        set_flash('error', 'Please choose a valid supplier.');
        redirect('purchases.php');
    }
    if (!$tank) {
        set_flash('error', 'Please choose the fuel type / tank being delivered.');
        redirect('purchases.php');
    }
    if ($liters === null || $cost === null) {
        set_flash('error', 'Enter a valid quantity (liters) and cost per liter.');
        redirect('purchases.php');
    }

    $total = round($liters * $cost, 2);

    /* Resolve the initial payment amount. */
    $initPaid = 0.0;
    if ($payStatus === 'Paid') {
        $initPaid = $total;
    } elseif ($payStatus === 'Partial') {
        if ($amountPaid === null || $amountPaid <= 0 || $amountPaid >= $total) {
            set_flash('error', 'A partial payment must be greater than 0 and less than the total cost.');
            redirect('purchases.php');
        }
        $initPaid = $amountPaid;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO supplier_purchases
                (supplier_id, fuel_inventory_id, fuel_type, liters, cost_per_liter, total_cost,
                 invoice_no, delivery_date, payment_status, amount_paid, tanker_no, driver_name,
                 driver_phone, delivery_ref, status, stock_applied, recorded_by, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', 0, ?, ?, ?, ?, 'pending', 0, ?, ?, NOW())"
        )->execute([
            $supplierId,
            $tankId,
            $tank['fuel_type'],
            number_format($liters, 2, '.', ''),
            number_format($cost, 2, '.', ''),
            $total,
            ($invoice !== '' ? $invoice : null),
            $delivDate,
            ($tankerNo !== '' ? $tankerNo : null),
            ($driver !== '' ? $driver : null),
            ($driverPh !== '' ? $driverPh : null),
            ($delivRef !== '' ? $delivRef : null),
            $_SESSION['full_name'] ?? 'System',
            ($notes !== '' ? $notes : null),
        ]);
        $purchaseId = (int)$pdo->lastInsertId();
        $purchaseNo = 'PUR-' . date('Y') . '-' . str_pad((string)$purchaseId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE supplier_purchases SET purchase_no = ? WHERE id = ?")->execute([$purchaseNo, $purchaseId]);

        /* Record the initial payment (if any) as a real ledger entry. */
        if ($initPaid > 0) {
            $pdo->prepare(
                "INSERT INTO supplier_payments
                    (supplier_id, purchase_id, amount, method, reference, payment_date, recorded_by, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $supplierId, $purchaseId, $initPaid, $payMethod,
                ($invoice !== '' ? $invoice : $purchaseNo),
                $delivDate,
                $_SESSION['full_name'] ?? 'System',
                'Initial payment on purchase ' . $purchaseNo,
            ]);
            $payId = (int)$pdo->lastInsertId();
            $payNo = 'PAY-' . date('Y') . '-' . str_pad((string)$payId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE supplier_payments SET payment_no = ? WHERE id = ?")->execute([$payNo, $payId]);
        }

        recalc_purchase_payment($pdo, $purchaseId);
        $pdo->commit();

        log_activity($pdo, 'purchase_add', sprintf(
            'Recorded purchase %s from "%s": %s L of %s at $%s/L = $%s (invoice %s).',
            $purchaseNo, $supplier['company_name'], fmt($liters), $tank['fuel_type'],
            money($cost), money($total), $invoice !== '' ? $invoice : '—'
        ), 'purchase', $purchaseId);

        set_flash('success', sprintf('Purchase %s recorded for $%s. Confirm the delivery to add stock.', $purchaseNo, money($total)));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', 'Could not record purchase: ' . $e->getMessage());
    }
    redirect('purchases.php');
}

/* ==========================================================
 *  Confirm a delivery  ->  add stock exactly once
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_purchase') {
    verify_csrf();

    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    if ($purchaseId <= 0) {
        set_flash('error', 'Invalid purchase selected.');
        redirect('purchases.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM supplier_purchases WHERE id = ? FOR UPDATE");
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch();

        if (!$purchase) {
            throw new RuntimeException('Purchase not found.');
        }
        if ($purchase['status'] !== 'pending' || (int)$purchase['stock_applied'] === 1) {
            throw new RuntimeException('This delivery was already ' . $purchase['status'] . ' — stock is never added twice.');
        }

        $tankId = (int)($purchase['fuel_inventory_id'] ?? 0);
        if ($tankId > 0) {
            $tankStmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? FOR UPDATE");
            $tankStmt->execute([$tankId]);
        } else {
            $tankStmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE fuel_type = ? FOR UPDATE");
            $tankStmt->execute([$purchase['fuel_type']]);
        }
        $tank = $tankStmt->fetch();
        if (!$tank) {
            throw new RuntimeException('No matching fuel tank found for ' . $purchase['fuel_type'] . '.');
        }

        $liters = (float)$purchase['liters'];
        $cur    = (float)$tank['current_stock_liters'];
        $cap    = (float)$tank['tank_capacity_liters'];

        if ($cap > 0 && ($cur + $liters) > ($cap + 0.0001)) {
            throw new RuntimeException(sprintf(
                'Tank is FULL! Cannot exceed max capacity. Available space: %s L.',
                fmt(max(0, $cap - $cur))
            ));
        }

        $newStock = $cur + $liters;
        $status   = $newStock >= ($cap - 0.0001) ? 'FULL' : (($cap > 0 && ($newStock / $cap) <= 0.15) ? 'LOW' : 'OK');

        $pdo->prepare("UPDATE fuel_inventory SET current_stock_liters = ?, status = ?, last_updated = NOW() WHERE id = ?")
            ->execute([$newStock, $status, (int)$tank['id']]);

        $pdo->prepare("UPDATE supplier_purchases SET status = 'confirmed', stock_applied = 1,
                              verified_by = ?, verified_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['full_name'] ?? 'System', $purchaseId]);

        $pdo->commit();

        log_activity($pdo, 'purchase_confirm', sprintf(
            'Confirmed delivery %s: added %s L of %s to tank #%d (invoice %s%s).',
            $purchase['purchase_no'] ?: ('#' . $purchaseId),
            fmt($liters), $tank['fuel_type'], (int)$tank['id'],
            $purchase['invoice_no'] ?: '—',
            !empty($purchase['delivery_ref']) ? ', ref ' . $purchase['delivery_ref'] : ''
        ), 'purchase', $purchaseId);

        set_flash('success', sprintf(
            'Delivery confirmed — %s L of %s added to tank #%d (new level %s L).',
            fmt($liters), $tank['fuel_type'], (int)$tank['id'], fmt($newStock)
        ));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', 'Confirmation failed: ' . $e->getMessage());
    }
    redirect('purchases.php');
}

/* ==========================================================
 *  Cancel a pending purchase (admin only)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_purchase') {
    verify_csrf();
    if (!is_admin()) {
        set_flash('error', 'Access Denied: Admin privileges required.');
        redirect('purchases.php');
    }

    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    if ($purchaseId > 0) {
        $s = $pdo->prepare("SELECT * FROM supplier_purchases WHERE id = ?");
        $s->execute([$purchaseId]);
        $purchase = $s->fetch();

        if (!$purchase) {
            set_flash('error', 'Purchase not found.');
        } elseif ($purchase['status'] !== 'pending') {
            set_flash('error', 'Only pending purchases can be cancelled (stock was already applied).');
        } else {
            $pdo->prepare("UPDATE supplier_purchases SET status = 'cancelled' WHERE id = ?")->execute([$purchaseId]);
            log_activity($pdo, 'purchase_cancel', sprintf(
                'Cancelled pending purchase %s ($%s).',
                $purchase['purchase_no'] ?: ('#' . $purchaseId), money($purchase['total_cost'])
            ), 'purchase', $purchaseId);
            set_flash('success', 'Purchase cancelled.');
        }
    }
    redirect('purchases.php');
}

/* ==========================================================
 *  Record a supplier payment
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
    verify_csrf();

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $amount     = parse_decimal($_POST['amount'] ?? '', 2);
    $method     = trim((string)($_POST['method'] ?? 'Cash'));
    $reference  = trim((string)($_POST['reference'] ?? ''));
    $payDate    = $parseDate($_POST['payment_date'] ?? '') ?? date('Y-m-d');
    $notes      = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($method, $paymentMethods, true)) $method = 'Cash';
    if (mb_strlen($reference) > 80)  $reference = mb_substr($reference, 0, 80);
    if (mb_strlen($notes)     > 255) $notes     = mb_substr($notes, 0, 255);

    if ($supplierId <= 0 || $amount === null) {
        set_flash('error', 'Choose a supplier and enter a valid payment amount.');
        redirect('purchases.php');
    }

    try {
        $pdo->beginTransaction();

        $sup = $pdo->prepare("SELECT company_name FROM suppliers WHERE id = ?");
        $sup->execute([$supplierId]);
        $company = (string)$sup->fetchColumn();
        if ($company === '') {
            throw new RuntimeException('Supplier not found.');
        }

        if ($purchaseId > 0) {
            $chk = $pdo->prepare("SELECT purchase_no FROM supplier_purchases WHERE id = ? AND supplier_id = ?");
            $chk->execute([$purchaseId, $supplierId]);
            if ($chk->fetchColumn() === false) {
                throw new RuntimeException('The selected purchase does not belong to that supplier.');
            }
        }

        $pdo->prepare(
            "INSERT INTO supplier_payments
                (supplier_id, purchase_id, amount, method, reference, payment_date, recorded_by, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $supplierId,
            ($purchaseId > 0 ? $purchaseId : null),
            number_format($amount, 2, '.', ''),
            $method,
            ($reference !== '' ? $reference : null),
            $payDate,
            $_SESSION['full_name'] ?? 'System',
            ($notes !== '' ? $notes : null),
        ]);
        $payId = (int)$pdo->lastInsertId();
        $payNo = 'PAY-' . date('Y') . '-' . str_pad((string)$payId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE supplier_payments SET payment_no = ? WHERE id = ?")->execute([$payNo, $payId]);

        if ($purchaseId > 0) {
            recalc_purchase_payment($pdo, $purchaseId);
        }
        $pdo->commit();

        log_activity($pdo, 'supplier_payment', sprintf(
            'Paid $%s to "%s" via %s%s.',
            money($amount), $company, $method,
            $purchaseId > 0 ? ' against purchase #' . $purchaseId : ''
        ), 'supplier', $supplierId);

        set_flash('success', sprintf('Payment of $%s recorded for "%s".', money($amount), $company));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', 'Payment failed: ' . $e->getMessage());
    }
    redirect('purchases.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$suppliers = $pdo->query("SELECT id, company_name, is_active FROM suppliers ORDER BY is_active DESC, company_name ASC")->fetchAll();
$tanks     = fuel_tanks_list($pdo);

/* ---- Filters ---- */
$fSupplier = (int)($_GET['supplier'] ?? 0);
$fStatus   = trim((string)($_GET['status'] ?? ''));
$fPayment  = trim((string)($_GET['payment'] ?? ''));
$fFrom     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$fTo       = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? (string)$_GET['to']   : '';

$where = []; $params = [];
if ($fSupplier > 0) { $where[] = 'p.supplier_id = ?'; $params[] = $fSupplier; }
if (in_array($fStatus, ['pending', 'confirmed', 'cancelled'], true)) { $where[] = 'p.status = ?'; $params[] = $fStatus; }
if (in_array($fPayment, ['Unpaid', 'Partial', 'Paid'], true))       { $where[] = 'p.payment_status = ?'; $params[] = $fPayment; }
if ($fFrom !== '') { $where[] = 'p.delivery_date >= ?'; $params[] = $fFrom; }
if ($fTo !== '')   { $where[] = 'p.delivery_date <= ?'; $params[] = $fTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$listStmt = $pdo->prepare(
    "SELECT p.*, s.company_name
     FROM supplier_purchases p
     JOIN suppliers s ON s.id = p.supplier_id
     $whereSql
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 300"
);
$listStmt->execute($params);
$purchases = $listStmt->fetchAll();

/* ---- Payment history (for the detail modal + payments table) ---- */
$allPayments = $pdo->query(
    "SELECT sp.*, s.company_name, p.purchase_no
     FROM supplier_payments sp
     JOIN suppliers s ON s.id = sp.supplier_id
     LEFT JOIN supplier_purchases p ON p.id = sp.purchase_id
     ORDER BY sp.payment_date DESC, sp.id DESC
     LIMIT 100"
)->fetchAll();

$payByPurchase = [];
foreach ($allPayments as $pay) {
    if (!empty($pay['purchase_id'])) {
        $payByPurchase[(int)$pay['purchase_id']][] = $pay;
    }
}

/* ---- KPIs ---- */
$debt        = supplier_debt_overview($pdo);
$totalLiters = (float)$pdo->query("SELECT COALESCE(SUM(liters),0) FROM supplier_purchases WHERE status <> 'cancelled'")->fetchColumn();
$pendingCnt  = (int)$pdo->query("SELECT COUNT(*) FROM supplier_purchases WHERE status = 'pending'")->fetchColumn();

/* ---- Purchases available to pay against ---- */
$openPurchases = $pdo->query(
    "SELECT p.id, p.purchase_no, p.supplier_id, p.total_cost, p.amount_paid, p.payment_status, s.company_name
     FROM supplier_purchases p JOIN suppliers s ON s.id = p.supplier_id
     WHERE p.status <> 'cancelled'
     ORDER BY p.created_at DESC LIMIT 200"
)->fetchAll();

/* ---- Detail-modal payload ---- */
$purchList = [];
foreach ($purchases as $p) {
    $ps = purchase_status_meta($p['status']);
    $purchList[(int)$p['id']] = [
        'no'          => $p['purchase_no'] ?: ('#' . $p['id']),
        'company'     => $p['company_name'],
        'fuel'        => $p['fuel_type'],
        'liters'      => fmt($p['liters']),
        'cost'        => money($p['cost_per_liter']),
        'total'       => money($p['total_cost']),
        'invoice'     => $p['invoice_no'] ?: '—',
        'delivery'    => $p['delivery_date'] ? date('M d, Y', strtotime($p['delivery_date'])) : '—',
        'tanker'      => $p['tanker_no'] ?: '—',
        'driver'      => $p['driver_name'] ?: '—',
        'driverPhone' => $p['driver_phone'] ?: '—',
        'ref'         => $p['delivery_ref'] ?: '—',
        'status'      => $ps['label'],
        'payStatus'   => $p['payment_status'],
        'amountPaid'  => money($p['amount_paid']),
        'recordedBy'  => $p['recorded_by'] ?: '—',
        'verifiedBy'  => $p['verified_by'] ?: '—',
        'notes'       => $p['notes'] ?: '',
        'payments'    => array_map(function ($x) {
            return [
                'no'     => $x['payment_no'] ?: ('#' . $x['id']),
                'amount' => money($x['amount']),
                'method' => $x['method'],
                'date'   => $x['payment_date'] ? date('M d, Y', strtotime($x['payment_date'])) : '—',
                'ref'    => $x['reference'] ?: '—',
            ];
        }, $payByPurchase[(int)$p['id']] ?? []),
    ];
}

include __DIR__ . '/includes/header.php';
?>

<!-- MODULE TABS -->
<?php $smPage = basename($_SERVER['PHP_SELF']); ?>
<div class="inline-flex bg-slate-800 border border-slate-700 rounded-xl p-1">
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

<!-- STATS -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Purchased</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($debt['purchases']) ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i><?= fmt($totalLiters) ?> L</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-cart-flatbed text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Paid</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($debt['paid']) ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-money-bill-transfer mr-1"></i>Settled</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-money-bill-transfer text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Outstanding</p>
            <p class="text-2xl font-bold mt-1 <?= $debt['outstanding'] > 0 ? 'text-red-400' : 'text-white' ?>">$<?= money($debt['outstanding']) ?></p>
            <p class="text-red-400 text-xs mt-2"><i class="fa-solid fa-file-invoice mr-1"></i><?= $debt['unpaid_invoices'] ?> open invoice<?= $debt['unpaid_invoices'] === 1 ? '' : 's' ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500/20 to-red-500/5 flex items-center justify-center">
            <i class="fa-solid fa-hand-holding-dollar text-red-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Pending Deliveries</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $pendingCnt ?></p>
            <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-hourglass-half mr-1"></i>Awaiting confirmation</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-hourglass-half text-amber-400 text-xl"></i>
        </div>
    </div>
</div>

<?php if (empty($suppliers)): ?>
<div class="rounded-2xl border border-amber-500/30 bg-amber-500/5 px-5 py-4 text-sm text-amber-300 flex items-center gap-3">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>No suppliers yet. <a href="suppliers.php" class="underline font-medium hover:text-amber-200">Register a supplier</a> before recording purchases.</span>
</div>
<?php endif; ?>

<!-- RECORD PURCHASE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-truck-ramp-box text-amber-400 mr-2"></i>Record Fuel Purchase</h3>
    <form method="POST" class="space-y-4" id="purchaseForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_purchase">

        <p class="text-slate-500 text-[11px] uppercase tracking-wider font-semibold">Purchase details</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Supplier <span class="text-red-400">*</span></label>
                <select name="supplier_id" required
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="" disabled selected>Select supplier&hellip;</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['company_name']) ?><?= (int)$s['is_active'] ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Fuel Type / Tank <span class="text-red-400">*</span></label>
                <select name="fuel_inventory_id" required id="p_tank"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="" disabled selected>Select fuel&hellip;</option>
                    <?php foreach ($tanks as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" data-cap="<?= (float)$t['tank_capacity_liters'] ?>" data-cur="<?= (float)$t['current_stock_liters'] ?>">
                        <?= htmlspecialchars($t['fuel_type']) ?> (Tank #<?= (int)$t['id'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="tankHint" class="text-slate-500 text-xs mt-1">Stock is added only after confirmation.</p>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Quantity (Liters) <span class="text-red-400">*</span></label>
                <input type="number" step="0.01" min="0.01" name="liters" id="p_liters" required
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. 5000">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Cost / Liter ($) <span class="text-red-400">*</span></label>
                <input type="number" step="0.01" min="0.01" name="cost_per_liter" id="p_cost" required
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. 1.42">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Total Cost</label>
                <div class="w-full bg-slate-900/60 border border-slate-700 rounded-xl px-4 py-2.5 text-amber-400 font-bold text-sm">$<span id="p_total">0.00</span></div>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Supplier Invoice #</label>
                <input type="text" name="invoice_no" maxlength="60"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. INV-SUP-8842">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Delivery Date</label>
                <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
        </div>

        <p class="text-slate-500 text-[11px] uppercase tracking-wider font-semibold pt-2">Tanker &amp; delivery reference</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Tanker / Truck No.</label>
                <input type="text" name="tanker_no" maxlength="40"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. T-2214">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Driver Name</label>
                <input type="text" name="driver_name" maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. Samir Aziz">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Driver Phone</label>
                <input type="text" name="driver_phone" maxlength="30"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. +961 70 000 000">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Delivery Reference</label>
                <input type="text" name="delivery_ref" maxlength="60"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="e.g. DO-2026-4471">
            </div>
        </div>

        <p class="text-slate-500 text-[11px] uppercase tracking-wider font-semibold pt-2">Payment</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Payment Status</label>
                <select name="pay_status" id="p_paystatus"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="Unpaid" selected>Unpaid (on account)</option>
                    <option value="Partial">Partial payment</option>
                    <option value="Paid">Paid in full</option>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Amount Paid ($)</label>
                <input type="number" step="0.01" min="0" name="amount_paid" id="p_amount" disabled
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition disabled:opacity-50" placeholder="Partial amount">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Method</label>
                <select name="pay_method" id="p_paymethod" disabled
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition disabled:opacity-50">
                    <?php foreach ($paymentMethods as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lg:col-span-1">
                <label class="text-slate-400 text-xs block mb-1">Notes</label>
                <input type="text" name="notes" maxlength="255"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition" placeholder="Optional remarks">
            </div>
        </div>

        <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
            <i class="fa-solid fa-plus mr-1"></i> Record Purchase
        </button>
    </form>
</div>

<!-- PURCHASES LOG -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <h3 class="text-white font-semibold text-sm">
            <i class="fa-solid fa-receipt text-cyan-400 mr-2"></i>Purchase History
            <span class="text-slate-500 font-normal text-xs ml-2">(<?= count($purchases) ?> shown)</span>
        </h3>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="supplier" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
                <option value="0">All suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $fSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
                <option value="">All statuses</option>
                <option value="pending"   <?= $fStatus === 'pending'   ? 'selected' : '' ?>>Pending</option>
                <option value="confirmed" <?= $fStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="cancelled" <?= $fStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <select name="payment" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
                <option value="">All payments</option>
                <?php foreach (['Unpaid', 'Partial', 'Paid'] as $ps): ?>
                <option value="<?= $ps ?>" <?= $fPayment === $ps ? 'selected' : '' ?>><?= $ps ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
            <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition"><i class="fa-solid fa-filter mr-1"></i>Filter</button>
            <?php if ($fSupplier || $fStatus !== '' || $fPayment !== '' || $fFrom !== '' || $fTo !== ''): ?>
            <a href="purchases.php" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">Purchase</th>
                    <th class="px-5 py-3 font-medium">Supplier</th>
                    <th class="px-5 py-3 font-medium">Fuel</th>
                    <th class="px-5 py-3 font-medium">Liters</th>
                    <th class="px-5 py-3 font-medium">Total</th>
                    <th class="px-5 py-3 font-medium">Delivery</th>
                    <th class="px-5 py-3 font-medium">Payment</th>
                    <th class="px-5 py-3 font-medium">Status</th>
                    <th class="px-5 py-3 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($purchases)): ?>
                <tr><td colspan="9" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-truck-ramp-box text-3xl mb-3 block text-slate-600"></i>No purchases recorded.
                </td></tr>
                <?php else: foreach ($purchases as $p):
                    $ds = purchase_status_meta($p['status']);
                    $pm = payment_status_meta($p['payment_status']);
                    $fc = $fuelGrad[$p['fuel_type']] ?? 'from-slate-500 to-slate-600';
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3">
                        <p class="text-slate-300 text-xs font-medium"><?= htmlspecialchars($p['purchase_no'] ?: ('#' . $p['id'])) ?></p>
                        <p class="text-slate-600 text-[11px]"><?= $p['invoice_no'] ? 'Sup. inv ' . htmlspecialchars($p['invoice_no']) : 'No invoice #' ?></p>
                    </td>
                    <td class="px-5 py-3 text-white"><?= htmlspecialchars($p['company_name']) ?></td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-white">
                            <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-br <?= $fc ?>"></span>
                            <?= htmlspecialchars($p['fuel_type']) ?>
                        </span>
                    </td>
                    <td class="px-5 py-3 text-slate-300"><?= fmt($p['liters']) ?> L</td>
                    <td class="px-5 py-3 text-white font-semibold">$<?= money($p['total_cost']) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= $p['delivery_date'] ? date('M d, Y', strtotime($p['delivery_date'])) : '—' ?></td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $pm['cls'] ?>">
                            <i class="fa-solid <?= $pm['icon'] ?>"></i> <?= $pm['label'] ?>
                        </span>
                        <p class="text-slate-600 text-[11px] mt-1">$<?= money($p['amount_paid']) ?> paid</p>
                    </td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?= $ds['cls'] ?>">
                            <i class="fa-solid <?= $ds['icon'] ?>"></i> <?= $ds['label'] ?>
                        </span>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" onclick="openPurchaseDetail(<?= (int)$p['id'] ?>)" title="View details"
                                    class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center text-slate-400 hover:text-cyan-400 transition">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <?php if ($p['status'] === 'pending'): ?>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('Confirm delivery <?= htmlspecialchars($p['purchase_no'] ?: ('#' . $p['id']), ENT_QUOTES) ?>? <?= fmt($p['liters']) ?> L will be added to the <?= htmlspecialchars($p['fuel_type'], ENT_QUOTES) ?> tank.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_purchase">
                                <input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" title="Verify &amp; confirm delivery"
                                        class="inline-flex items-center gap-1 px-2.5 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs hover:bg-emerald-500/20 transition">
                                    <i class="fa-solid fa-check"></i> Confirm
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($p['payment_status'] !== 'Paid' && $p['status'] !== 'cancelled'): ?>
                            <button type="button" onclick="openPayment(<?= (int)$p['supplier_id'] ?>, <?= (int)$p['id'] ?>)" title="Record a payment"
                                    class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center text-slate-400 hover:text-emerald-400 transition">
                                <i class="fa-solid fa-money-bill-transfer"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (is_admin() && $p['status'] === 'pending'): ?>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('Cancel purchase <?= htmlspecialchars($p['purchase_no'] ?: ('#' . $p['id']), ENT_QUOTES) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_purchase">
                                <input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" title="Cancel purchase"
                                        class="w-8 h-8 rounded-lg bg-red-500/10 border border-red-500/30 flex items-center justify-center text-red-300 hover:bg-red-500/20 transition">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PAYMENT HISTORY -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-money-bill-transfer text-emerald-400 mr-2"></i>Recent Supplier Payments</h3>
        <button type="button" onclick="openPayment(0, 0)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs hover:bg-emerald-500/20 transition">
            <i class="fa-solid fa-plus"></i> Record Payment
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">Payment #</th>
                    <th class="px-5 py-3 font-medium">Supplier</th>
                    <th class="px-5 py-3 font-medium">Purchase</th>
                    <th class="px-5 py-3 font-medium">Amount</th>
                    <th class="px-5 py-3 font-medium">Method</th>
                    <th class="px-5 py-3 font-medium">Reference</th>
                    <th class="px-5 py-3 font-medium">Date</th>
                    <th class="px-5 py-3 font-medium">Recorded By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($allPayments)): ?>
                <tr><td colspan="8" class="px-6 py-10 text-center text-slate-500">
                    <i class="fa-solid fa-money-bill-transfer text-2xl mb-2 block text-slate-600"></i>No payments recorded yet.
                </td></tr>
                <?php else: foreach ($allPayments as $pay): ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3 text-slate-400 text-xs font-medium"><?= htmlspecialchars($pay['payment_no'] ?: ('#' . $pay['id'])) ?></td>
                    <td class="px-5 py-3 text-white"><?= htmlspecialchars($pay['company_name']) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= $pay['purchase_no'] ? htmlspecialchars($pay['purchase_no']) : 'On account' ?></td>
                    <td class="px-5 py-3 text-emerald-400 font-semibold">$<?= money($pay['amount']) ?></td>
                    <td class="px-5 py-3 text-slate-300 text-xs"><?= htmlspecialchars($pay['method']) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($pay['reference'] ?: '—') ?></td>
                    <td class="px-5 py-3 text-slate-500 text-xs"><?= $pay['payment_date'] ? date('M d, Y', strtotime($pay['payment_date'])) : date('M d, Y', strtotime($pay['created_at'])) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($pay['recorded_by'] ?: '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div id="paymentModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-money-bill-transfer text-emerald-400 mr-2"></i>Record Supplier Payment</h3>
            <button onclick="closeModal('paymentModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Supplier <span class="text-red-400">*</span></label>
                <select name="supplier_id" id="pay_supplier" required
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="" disabled selected>Select supplier&hellip;</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Against Purchase (optional)</label>
                <select name="purchase_id" id="pay_purchase"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="0">On account (not linked)</option>
                    <?php foreach ($openPurchases as $op): ?>
                    <option value="<?= (int)$op['id'] ?>" data-supplier="<?= (int)$op['supplier_id'] ?>">
                        <?= htmlspecialchars($op['purchase_no'] ?: ('#' . $op['id'])) ?> — <?= htmlspecialchars($op['company_name']) ?> — $<?= money($op['total_cost']) ?> (<?= htmlspecialchars($op['payment_status']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Amount ($) <span class="text-red-400">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="amount" required
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500 transition" placeholder="e.g. 1500.00">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Method</label>
                    <select name="method" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500 transition">
                        <?php foreach ($paymentMethods as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Payment Date</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500 transition">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Reference</label>
                    <input type="text" name="reference" maxlength="80"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500 transition" placeholder="e.g. Cheque #4421">
                </div>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Notes</label>
                <input type="text" name="notes" maxlength="255"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500 transition">
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 text-white font-medium text-sm hover:from-emerald-400 hover:to-emerald-500 transition shadow-lg shadow-emerald-500/20">
                <i class="fa-solid fa-check mr-1"></i> Save Payment
            </button>
        </form>
    </div>
</div>

<!-- DETAIL MODAL -->
<div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-xl shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-truck-ramp-box text-cyan-400 mr-2"></i>Purchase <span id="d_no"></span></h3>
            <button onclick="closeModal('detailModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <div id="d_body" class="space-y-4 text-sm"></div>
    </div>
</div>

<script>
const PURCHASES = <?= json_encode($purchList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* ---- Record purchase: live total + payment field toggles ---- */
(function () {
    const liters = document.getElementById('p_liters');
    const cost   = document.getElementById('p_cost');
    const total  = document.getElementById('p_total');
    const tank   = document.getElementById('p_tank');
    const hint   = document.getElementById('tankHint');
    const status = document.getElementById('p_paystatus');
    const amount = document.getElementById('p_amount');
    const method = document.getElementById('p_paymethod');

    function recalc() {
        const l = parseFloat(liters.value || 0);
        const c = parseFloat(cost.value || 0);
        total.textContent = (isFinite(l) && isFinite(c) ? l * c : 0).toFixed(2);
        if (status.value !== 'Unpaid' && liters.value && cost.value) {
            amount.placeholder = (parseFloat(total.textContent)).toFixed(2);
        }
    }
    function syncTank() {
        const opt = tank.options[tank.selectedIndex];
        if (opt && opt.dataset.cap) {
            const space = Math.max(0, parseFloat(opt.dataset.cap) - parseFloat(opt.dataset.cur));
            hint.textContent = 'Available space: ' + space.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L (stock is added on confirmation).';
            hint.className = (parseFloat(liters.value || 0) > space) ? 'text-amber-400 text-xs mt-1' : 'text-slate-500 text-xs mt-1';
        }
    }
    function syncPay() {
        const partial = status.value === 'Partial';
        amount.disabled = !partial;
        method.disabled = status.value === 'Unpaid';
        if (!partial) amount.value = '';
    }
    liters.addEventListener('input', function(){ recalc(); syncTank(); });
    cost.addEventListener('input', recalc);
    tank.addEventListener('change', syncTank);
    status.addEventListener('change', syncPay);
    syncPay();
})();

/* ---- Payment modal ---- */
function openPayment(supplierId, purchaseId) {
    const sup = document.getElementById('pay_supplier');
    const pur = document.getElementById('pay_purchase');
    pur.value = purchaseId ? String(purchaseId) : '0';
    if (purchaseId) {
        const opt = pur.options[pur.selectedIndex];
        sup.value = opt && opt.dataset.supplier ? opt.dataset.supplier : (supplierId ? String(supplierId) : '');
    } else if (supplierId) {
        sup.value = String(supplierId);
    }
    showModal('paymentModal');
}
document.getElementById('pay_purchase').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.supplier) {
        document.getElementById('pay_supplier').value = opt.dataset.supplier;
    }
});

/* ---- Detail modal ---- */
function openPurchaseDetail(id) {
    const p = PURCHASES[id];
    if (!p) return;
    document.getElementById('d_no').textContent = p.no;

    const row = (label, value) =>
        '<div class="flex justify-between gap-4 py-2 border-b border-slate-700/60"><span class="text-slate-400">' + label +
        '</span><span class="text-white text-right">' + value + '</span></div>';

    let html = '';
    html += row('Supplier', p.company);
    html += row('Fuel Type', p.fuel);
    html += row('Quantity', p.liters + ' L');
    html += row('Cost / Liter', '$' + p.cost);
    html += row('Total Cost', '<span class="text-amber-400 font-semibold">$' + p.total + '</span>');
    html += row('Supplier Invoice', p.invoice);
    html += row('Delivery Date', p.delivery);
    html += row('Tanker / Truck', p.tanker);
    html += row('Driver', p.driver);
    html += row('Driver Phone', p.driverPhone);
    html += row('Delivery Reference', p.ref);
    html += row('Delivery Status', p.status);
    html += row('Payment Status', p.payStatus + ' ($' + p.amountPaid + ' paid)');
    html += row('Recorded By', p.recordedBy);
    html += row('Verified By', p.verifiedBy);
    if (p.notes) html += row('Notes', p.notes);

    if (p.payments && p.payments.length) {
        html += '<div class="pt-3"><p class="text-slate-400 text-xs uppercase tracking-wider mb-2">Payment History</p>';
        p.payments.forEach(function (x) {
            html += '<div class="flex items-center justify-between gap-3 py-2 border-b border-slate-700/60 text-xs">' +
                    '<span class="text-slate-300">' + x.no + ' <span class="text-slate-600">• ' + x.method + ' • ' + x.date + '</span></span>' +
                    '<span class="text-emerald-400 font-semibold">$' + x.amount + '</span></div>';
        });
        html += '</div>';
    }

    document.getElementById('d_body').innerHTML = html;
    showModal('detailModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
