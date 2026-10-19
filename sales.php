<?php
/**
 * Sales — record fuel transactions, deduct from the tank,
 *         and browse the sales log (with invoices).
 */
require_once __DIR__ . '/config.php';

$pageTitle    = 'Sales';
$pageSubtitle = 'Record transactions and monitor daily sales';

/* ==========================================================
 *  Handle new sale
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sale') {
    verify_csrf();

    $tankId  = (int)($_POST['fuel_inventory_id'] ?? 0);
    $liters  = parse_decimal($_POST['liters_sold'] ?? '', 2);
    $payment = trim($_POST['payment_method'] ?? 'Cash');
    $customer = trim($_POST['customer_name'] ?? '');
    $allowedPayments = ['Cash', 'Card', 'Credit'];

    if (!in_array($payment, $allowedPayments, true)) $payment = 'Cash';
    if (mb_strlen($customer) > 100) $customer = mb_substr($customer, 0, 100);

    if ($tankId <= 0 || $liters === null || $liters <= 0) {
        set_flash('error', 'Please choose a fuel type and enter a valid number of liters.');
        redirect('sales.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? FOR UPDATE");
        $stmt->execute([$tankId]);
        $tank = $stmt->fetch();

        if (!$tank) {
            throw new RuntimeException('Invalid fuel tank selected.');
        }

        $available = (float)$tank['current_stock_liters'];
        if ($liters > $available + 0.0001) {
            throw new RuntimeException(sprintf(
                'Insufficient stock for %s. Available: %s L.',
                $tank['fuel_type'], fmt($available)
            ));
        }

        $price = (float)$tank['price_per_liter'];
        $total = round($liters * $price, 2);

        // Attach the sale to the cashier's open shift (if any) for reconciliation.
        $shift   = current_shift($pdo);
        $shiftId = $shift ? (int)$shift['id'] : null;

        // 1) Insert the sale, then stamp a readable invoice number
        $ins = $pdo->prepare(
            "INSERT INTO sales (fuel_inventory_id, shift_id, liters_sold, price_per_liter, total_amount,
                                payment_method, customer_name, recorded_by, sold_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $ins->execute([
            $tankId,
            $shiftId,
            number_format($liters, 2, '.', ''),
            number_format((float)$tank['price_per_liter'], 2, '.', ''),
            $total,
            $payment,
            ($customer !== '' ? $customer : null),
            $_SESSION['full_name'] ?? 'System',
        ]);
        $saleId = (int)$pdo->lastInsertId();
        $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string)$saleId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE sales SET invoice_no = ? WHERE id = ?")->execute([$invoiceNo, $saleId]);

        /* Deduct liters + recompute the tank status */
        $newStock = (float)$tank['current_stock_liters'] - $liters;
        $cap      = max(0.01, (float)$tank['tank_capacity_liters']);
        $status   = $newStock >= $cap ? 'FULL' : (($newStock / $cap) <= 0.15 ? 'LOW' : 'OK');

        $pdo->prepare("UPDATE fuel_inventory SET current_stock_liters = ?, status = ? WHERE id = ?")
            ->execute([$newStock, $status, $tankId]);

        $pdo->commit();

        log_activity($pdo, 'sale', sprintf(
            'Sale %s — %s L of %s for $%s (%s)%s.',
            $invoiceNo, fmt($liters), $tank['fuel_type'], money($total), $payment,
            $shiftId ? ' • shift #' . $shiftId : ''
        ), 'sale', $saleId);

        set_flash('success', sprintf(
            'Sale %s recorded: %s L of %s for $%s.',
            $invoiceNo, fmt($liters), $tank['fuel_type'], money((float)$liters * (float)$tank['price_per_liter'])
        ));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Sale failed: ' . $e->getMessage());
    }
    redirect('sales.php');
}

/* ==========================================================
 *  Void a sale (System Admin only)
 *  Reverses the dispensed liters back into the tank and removes
 *  the transaction, recording the action in the audit log.
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_sale') {
    verify_csrf();

    if (!is_admin()) {
        set_flash('error', 'Access Denied: Admin privileges required.');
        redirect('sales.php');
    }

    $saleId = (int)($_POST['sale_id'] ?? 0);
    if ($saleId <= 0) {
        set_flash('error', 'Invalid sale selected.');
        redirect('sales.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT s.*, fi.fuel_type
             FROM sales s JOIN fuel_inventory fi ON fi.id = s.fuel_inventory_id
             WHERE s.id = ? FOR UPDATE"
        );
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();

        if (!$sale) {
            throw new RuntimeException('Sale not found — it may already have been voided.');
        }

        $tankId = (int)$sale['fuel_inventory_id'];
        $liters = (float)$sale['liters_sold'];

        $tankStmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? FOR UPDATE");
        $tankStmt->execute([$tankId]);
        $tank = $tankStmt->fetch();
        if (!$tank) {
            throw new RuntimeException('The source tank no longer exists.');
        }

        $cap      = (float)$tank['tank_capacity_liters'];
        $restored = (float)$tank['current_stock_liters'] + $liters;
        if ($cap > 0 && $restored > $cap) {
            $restored = $cap; // never exceed physical capacity
        }
        $status = 'OK';
        if ($cap > 0) {
            $ratio  = $restored / $cap;
            $status = ($restored >= $cap - 0.0001) ? 'FULL' : ($ratio <= 0.15 ? 'LOW' : 'OK');
        }

        $pdo->prepare("UPDATE fuel_inventory SET current_stock_liters = ?, status = ? WHERE id = ?")
            ->execute([$restored, $status, $tankId]);

        $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);

        $pdo->commit();

        $invoice = $sale['invoice_no'] ?: ('#' . $saleId);
        log_activity(
            $pdo,
            'sale_void',
            sprintf('Voided sale %s — %s L of %s ($%s) returned to the tank.',
                $invoice, fmt($liters), $sale['fuel_type'], money($sale['total_amount'])),
            'sale',
            $saleId
        );
        set_flash('success', sprintf(
            'Sale %s voided — %s L of %s restored to the tank.',
            $invoice, fmt($liters), $sale['fuel_type']
        ));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Void failed: ' . $e->getMessage());
    }
    redirect('sales.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$tanks = $pdo->query("SELECT * FROM fuel_inventory ORDER BY fuel_type")->fetchAll();

$filterDate = trim($_GET['date'] ?? '');
$hasFilter  = ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate) === 1);

if ($hasFilter) {
    $stmt = $pdo->prepare(
        "SELECT s.*, fi.fuel_type
         FROM sales s JOIN fuel_inventory fi ON fi.id = s.fuel_inventory_id
         WHERE DATE(s.sold_at) = ?
         ORDER BY s.sold_at DESC, s.id DESC"
    );
    $stmt->execute([$filterDate]);
    $sales = $stmt->fetchAll();
} else {
    $sales = $pdo->query(
        "SELECT s.*, fi.fuel_type
         FROM sales s JOIN fuel_inventory fi ON fi.id = s.fuel_inventory_id
         ORDER BY s.sold_at DESC, s.id DESC
         LIMIT 100"
    )->fetchAll();
}

/* ---- Stats ---- */
$todayRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$todayLiters  = (float)$pdo->query("SELECT COALESCE(SUM(liters_sold),0) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$todayTx      = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$allRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn();
$allLiters    = (float)$pdo->query("SELECT COALESCE(SUM(liters_sold),0) FROM sales")->fetchColumn();
$allTx        = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();

$todayFmt = date('D, M d Y');

include __DIR__ . '/includes/header.php';
?>

<!-- STATS -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Today's Cash</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($todayRevenue) ?></p>
            <p class="text-slate-500 text-xs mt-2"><?= $todayFmt ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-sack-dollar text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Liters Sold Today</p>
            <p class="text-white text-2xl font-bold mt-1"><?= fmt($todayLiters) ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i><?= $todayTx ?> transactions</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-gas-pump text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">All-Time Revenue</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($allRevenue) ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-sack-dollar mr-1"></i><?= $allTx ?> sales</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-chart-line text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Avg. Sale</p>
            <?php $avg = $allTx > 0 ? $allRevenue / $allTx : 0; ?>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($avg) ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-droplet mr-1"></i><?= fmt($allLiters) ?> L all-time</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500/20 to-purple-500/5 flex items-center justify-center">
            <i class="fa-solid fa-calculator text-purple-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- SHIFT STATUS STRIP -->
<?php $myShift = current_shift($pdo); ?>
<div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border <?= $myShift ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5' ?> px-5 py-3">
    <p class="text-sm <?= $myShift ? 'text-emerald-300' : 'text-amber-300' ?>">
        <i class="fa-solid <?= $myShift ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mr-2"></i>
        <?php if ($myShift): ?>
            Shift <strong>#<?= (int)$myShift['id'] ?></strong> is open since <?= date('H:i', strtotime($myShift['opened_at'])) ?> — sales are being tracked for reconciliation.
        <?php else: ?>
            You have no open shift. Sales won't be linked to a shift for cash reconciliation.
        <?php endif; ?>
    </p>
    <a href="shifts.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-900/50 border border-slate-600 text-xs text-slate-200 hover:text-white transition">
        <i class="fa-solid fa-clock-rotate-left"></i> <?= $myShift ? 'Manage Shift' : 'Open a Shift' ?>
    </a>
</div>

<!-- RECORD SALE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-cart-plus text-amber-400 mr-2"></i>Record New Sale</h3>
    <form method="POST" class="space-y-4" id="saleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_sale">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Fuel Type</label>
                <select name="fuel_inventory_id" required id="saleFuelSelect"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                               focus:outline-none focus:border-amber-500 transition">
                    <option value="" disabled selected>Select fuel…</option>
                    <?php foreach ($tanks as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"
                            data-price="<?= htmlspecialchars((string)round((float)$t['price_per_liter'], 2)) ?>"
                            data-stock="<?= (float)$t['current_stock_liters'] ?>">
                        <?= htmlspecialchars($t['fuel_type']) ?> — $<?= money($t['price_per_liter']) ?>/L — Stock: <?= fmt($t['current_stock_liters']) ?> L
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Liters to Sell</label>
                <input type="number" name="liters_sold" step="0.01" min="0.01" required id="saleLiters"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-amber-500 transition" placeholder="e.g. 30">
                <p id="stockHint" class="text-slate-500 text-xs mt-1">Select a fuel to see available stock.</p>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Payment Method</label>
                <select name="payment_method" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                                                          focus:outline-none focus:border-amber-500 transition">
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="Credit">Credit</option>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Customer (optional)</label>
                <input type="text" name="customer_name" maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-amber-500 transition" placeholder="e.g. Walk-in">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm
                                         hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
                <i class="fa-solid fa-check mr-1"></i> Record Sale
            </button>
            <div class="text-sm text-slate-400">Total: <span class="text-amber-400 font-bold text-lg">$<span id="saleTotal">0.00</span></span></div>
        </div>
    </form>
</div>

<!-- SALES LOG -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <h3 class="text-white font-semibold text-sm">
            <i class="fa-solid fa-clock-rotate-left text-cyan-400 mr-2"></i>Sales Log
            <?php if ($hasFilter): ?>
                <span class="text-slate-500 font-normal">— filtered by <?= htmlspecialchars($filterDate) ?></span>
            <?php endif; ?>
            <span class="text-slate-500 font-normal text-xs ml-2">(<?= count($sales) ?> shown)</span>
        </h3>
        <form method="GET" class="flex items-center gap-2">
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>"
                   class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">
                <i class="fa-solid fa-filter mr-1"></i>Filter
            </button>
            <?php if ($hasFilter): ?>
            <a href="sales.php" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">Invoice</th>
                    <th class="px-5 py-3 font-medium">Fuel</th>
                    <th class="px-5 py-3 font-medium">Liters</th>
                    <th class="px-5 py-3 font-medium">Price/L</th>
                    <th class="px-5 py-3 font-medium">Total</th>
                    <th class="px-5 py-3 font-medium">Payment</th>
                    <th class="px-5 py-3 font-medium">Customer</th>
                    <th class="px-5 py-3 font-medium">Served By</th>
                    <th class="px-5 py-3 font-medium">Date</th>
                    <th class="px-5 py-3 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($sales)): ?>
                <tr><td colspan="10" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-receipt text-3xl mb-3 block text-slate-600"></i>No sales found.
                </td></tr>
                <?php else: foreach ($sales as $s):
                    $fc = ['Petrol' => 'text-orange-400', 'Diesel' => 'text-cyan-400', 'LPG' => 'text-purple-400'][$s['fuel_type']] ?? 'text-slate-300';
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3 text-slate-400 text-xs font-medium"><?= htmlspecialchars($s['invoice_no'] ?: ('#' . $s['id'])) ?></td>
                    <td class="px-5 py-3"><span class="<?= $fc ?> font-medium"><?= htmlspecialchars($s['fuel_type']) ?></span></td>
                    <td class="px-5 py-3 text-slate-300"><?= fmt($s['liters_sold']) ?> L</td>
                    <td class="px-5 py-3 text-slate-400">$<?= money($s['price_per_liter'] ?? 0) ?></td>
                    <td class="px-5 py-3 text-emerald-400 font-semibold">$<?= money($s['total_amount']) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($s['payment_method'] ?? 'Cash') ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($s['customer_name'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars($s['recorded_by'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-slate-500 text-xs"><?= date('M d, Y H:i', strtotime($s['sold_at'])) ?></td>
                    <td class="px-5 py-3 text-right">
                        <div class="inline-flex items-center gap-2">
                            <a href="invoice.php?id=<?= (int)$s['id'] ?>" target="_blank"
                               class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-700/60 border border-slate-600
                                      text-slate-300 text-xs hover:text-white hover:border-cyan-500/40 transition">
                                <i class="fa-solid fa-print text-cyan-400"></i> Invoice
                            </a>
                            <?php if (is_admin()): ?>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('Void <?= htmlspecialchars($s['invoice_no'] ?: ('#' . $s['id'])) ?>? <?= fmt($s['liters_sold']) ?> L will be returned to the tank.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="void_sale">
                                <input type="hidden" name="sale_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" title="Void this sale (admin)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-red-500/10 border border-red-500/30
                                               text-red-300 text-xs hover:bg-red-500/20 transition">
                                    <i class="fa-solid fa-ban"></i> Void
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

<script>
(function () {
    const sel   = document.getElementById('saleFuelSelect');
    const liters = document.getElementById('saleLiters');
    const total = document.getElementById('saleTotal');
    const hint  = document.getElementById('stockHint');

    function current() {
        const opt = sel.options[sel.selectedIndex];
        return { price: parseFloat(opt?.dataset.price || 0), stock: parseFloat(opt?.dataset.stock || 0) };
    }
    function recalc() {
        const { price, stock } = current();
        const l = parseFloat(liters.value || 0);
        total.textContent = (isFinite(l) ? l * price : 0).toFixed(2);
        if (sel.value) {
            liters.max = stock;
            hint.textContent = 'Available: ' + stock.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
            hint.className = (l > stock) ? 'text-red-400 text-xs mt-1' : 'text-slate-500 text-xs mt-1';
        }
    }
    sel.addEventListener('change', recalc);
    liters.addEventListener('input', recalc);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
