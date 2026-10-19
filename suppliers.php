<?php
/**
 * Suppliers — register and manage fuel suppliers.
 *
 * Master data for the Suppliers module: company details, contact info,
 * and a live money summary (purchases / paid / outstanding debt).
 */
require_once __DIR__ . '/config.php';

/* RBAC: Suppliers management is admin-only (hidden + hard-blocked for operators). */
require_admin();

$pageTitle    = 'Suppliers';
$pageSubtitle = 'Register fuel suppliers and track what you owe them';

/* ==========================================================
 *  Handle actions
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_supplier' || $action === 'edit_supplier') {
        $id       = (int)($_POST['id'] ?? 0);
        $company  = trim((string)($_POST['company_name'] ?? ''));
        $contact  = trim((string)($_POST['contact_person'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $address  = trim((string)($_POST['address'] ?? ''));
        $notes    = trim((string)($_POST['notes'] ?? ''));

        if ($company === '') {
            set_flash('error', 'Company name is required.');
            redirect('suppliers.php');
        }
        if (mb_strlen($company) > 120) $company = mb_substr($company, 0, 120);
        if (mb_strlen($contact) > 100) $contact = mb_substr($contact, 0, 100);
        if (mb_strlen($phone) > 30)    $phone   = mb_substr($phone, 0, 30);
        if (mb_strlen($address) > 255) $address = mb_substr($address, 0, 255);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Please enter a valid email address (or leave it blank).');
            redirect('suppliers.php');
        }
        if (mb_strlen($email) > 150) $email = mb_substr($email, 0, 150);

        $vals = [
            $company,
            ($contact !== '' ? $contact : null),
            ($phone !== '' ? $phone : null),
            ($email !== '' ? $email : null),
            ($address !== '' ? $address : null),
            ($notes !== '' ? $notes : null),
        ];

        if ($action === 'add_supplier') {
            $pdo->prepare(
                "INSERT INTO suppliers (company_name, contact_person, phone, email, address, notes)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute($vals);
            $newId = (int)$pdo->lastInsertId();

            log_activity($pdo, 'supplier_add', sprintf('Registered supplier "%s".', $company), 'supplier', $newId);
            set_flash('success', "Supplier \"$company\" registered.");
        } else {
            if ($id <= 0) {
                set_flash('error', 'Invalid supplier.');
                redirect('suppliers.php');
            }
            $vals[] = $id;
            $pdo->prepare(
                "UPDATE suppliers SET company_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, notes = ?
                 WHERE id = ?"
            )->execute($vals);

            log_activity($pdo, 'supplier_edit', sprintf('Updated supplier "%s".', $company), 'supplier', $id);
            set_flash('success', 'Supplier updated successfully.');
        }
        redirect('suppliers.php');

    } elseif ($action === 'toggle_supplier') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT company_name, is_active FROM suppliers WHERE id = ?");
            $row->execute([$id]);
            $sup = $row->fetch();
            $pdo->prepare("UPDATE suppliers SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            if ($sup) {
                log_activity($pdo, 'supplier_toggle', sprintf(
                    '%s supplier "%s".',
                    $sup['is_active'] ? 'Deactivated' : 'Activated',
                    $sup['company_name']
                ), 'supplier', $id);
            }
            set_flash('success', 'Supplier status updated.');
        }
        redirect('suppliers.php');

    } elseif ($action === 'delete_supplier') {
        if (!is_admin()) {
            set_flash('error', 'Access Denied: Admin privileges required.');
            redirect('suppliers.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT company_name FROM suppliers WHERE id = ?");
            $row->execute([$id]);
            $name = (string)$row->fetchColumn();

            $sum = supplier_summary($pdo, $id);
            $pc  = $pdo->prepare("SELECT COUNT(*) FROM supplier_purchases WHERE supplier_id = ?");
            $pc->execute([$id]);
            if ((int)$pc->fetchColumn() > 0) {
                set_flash('error', 'Cannot delete "' . $name . '" — it has recorded purchases. Deactivate it instead.');
                redirect('suppliers.php');
            }

            try {
                $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
                log_activity($pdo, 'supplier_delete', sprintf('Deleted supplier "%s".', $name !== '' ? $name : '#' . $id), 'supplier', $id);
                set_flash('success', 'Supplier removed.');
            } catch (Throwable $e) {
                set_flash('error', 'Could not delete supplier: ' . $e->getMessage());
            }
        }
        redirect('suppliers.php');
    }
    redirect('suppliers.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$suppliers = $pdo->query(
    "SELECT s.*,
            COALESCE(p.total,0) AS total_purchases,
            COALESCE(p.cnt,0)   AS purchase_count,
            COALESCE(p.all_cnt,0) AS all_purchase_count,
            COALESCE(pay.paid,0) AS total_paid
     FROM suppliers s
     LEFT JOIN (
         SELECT supplier_id,
                SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END) AS total,
                SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END)          AS cnt,
                COUNT(*)                                                       AS all_cnt
         FROM supplier_purchases GROUP BY supplier_id
     ) p ON p.supplier_id = s.id
     LEFT JOIN (
         SELECT supplier_id, COALESCE(SUM(amount),0) AS paid
         FROM supplier_payments GROUP BY supplier_id
     ) pay ON pay.supplier_id = s.id
     ORDER BY s.is_active DESC, s.company_name ASC"
)->fetchAll();

$debt = supplier_debt_overview($pdo);
$activeCount = 0;
foreach ($suppliers as $s) {
    if ((int)$s['is_active'] === 1) $activeCount++;
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
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Suppliers</p>
            <p class="text-white text-2xl font-bold mt-1"><?= count($suppliers) ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-circle-check mr-1"></i><?= $activeCount ?> active</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-truck-field text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Purchases</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($debt['purchases']) ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-cart-flatbed mr-1"></i>Fuel bought all-time</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-cart-flatbed text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Paid to Suppliers</p>
            <p class="text-white text-2xl font-bold mt-1">$<?= money($debt['paid']) ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-money-bill-transfer mr-1"></i>Total settled</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-money-bill-transfer text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Outstanding Debt</p>
            <p class="text-2xl font-bold mt-1 <?= $debt['outstanding'] > 0 ? 'text-red-400' : 'text-white' ?>">$<?= money($debt['outstanding']) ?></p>
            <p class="text-red-400 text-xs mt-2"><i class="fa-solid fa-file-invoice mr-1"></i><?= $debt['unpaid_invoices'] ?> open invoice<?= $debt['unpaid_invoices'] === 1 ? '' : 's' ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500/20 to-red-500/5 flex items-center justify-center">
            <i class="fa-solid fa-hand-holding-dollar text-red-400 text-xl"></i>
        </div>
    </div>
</div>

<?php if (is_admin()): ?>
<!-- ADD FORM -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-truck-field text-amber-400 mr-2"></i>Register New Supplier</h3>
    <form method="POST" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_supplier">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Company Name <span class="text-red-400">*</span></label>
                <input type="text" name="company_name" required maxlength="120"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. Coastal Fuel Traders">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Contact Person</label>
                <input type="text" name="contact_person" maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. Nabil Sayegh">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Phone Number</label>
                <input type="text" name="phone" maxlength="30"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. +961 1 555 000">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Email</label>
                <input type="email" name="email" maxlength="150"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. sales@supplier.com">
            </div>
            <div class="lg:col-span-2">
                <label class="text-slate-400 text-xs block mb-1">Address</label>
                <input type="text" name="address" maxlength="255"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. Industrial Zone, Block C">
            </div>
            <div class="lg:col-span-3">
                <label class="text-slate-400 text-xs block mb-1">Notes</label>
                <textarea name="notes" rows="2"
                          class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                          placeholder="Payment terms, delivery schedule, remarks&hellip;"></textarea>
            </div>
        </div>
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
            <i class="fa-solid fa-plus mr-1"></i> Register Supplier
        </button>
    </form>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-address-book text-cyan-400 mr-2"></i>Supplier Directory</h3>
        <a href="supplier_reports.php?report=debts" class="text-xs text-amber-400 hover:text-amber-300">
            <i class="fa-solid fa-hand-holding-dollar mr-1"></i>Debt report
        </a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">Company</th>
                    <th class="px-6 py-3 font-medium">Contact</th>
                    <th class="px-6 py-3 font-medium">Phone</th>
                    <th class="px-6 py-3 font-medium">Purchases</th>
                    <th class="px-6 py-3 font-medium">Paid</th>
                    <th class="px-6 py-3 font-medium">Outstanding</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($suppliers)): ?>
                <tr><td colspan="8" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-truck-field text-3xl mb-3 block text-slate-600"></i>No suppliers registered yet.
                </td></tr>
                <?php else: foreach ($suppliers as $s):
                    $purchases = (float)$s['total_purchases'];
                    $paid      = (float)$s['total_paid'];
                    $owed      = round($purchases - $paid, 2);
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-500 flex items-center justify-center text-white text-xs font-bold shadow-lg flex-shrink-0">
                                <?= htmlspecialchars(strtoupper(substr($s['company_name'], 0, 2))) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-white font-medium truncate"><?= htmlspecialchars($s['company_name']) ?></p>
                                <?php if (!empty($s['email'])): ?>
                                <p class="text-slate-500 text-[11px] truncate"><?= htmlspecialchars($s['email']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-3 text-slate-300"><?= htmlspecialchars($s['contact_person'] ?: '—') ?></td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars($s['phone'] ?: '—') ?></td>
                    <td class="px-6 py-3 text-slate-300">$<?= money($purchases) ?> <span class="text-slate-500 text-xs">(<?= (int)$s['purchase_count'] ?>)</span></td>
                    <td class="px-6 py-3 text-emerald-400">$<?= money($paid) ?></td>
                    <td class="px-6 py-3 font-semibold <?= $owed > 0.004 ? 'text-red-400' : 'text-slate-400' ?>">$<?= money($owed) ?></td>
                    <td class="px-6 py-3">
                        <?php if ((int)$s['is_active']): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-400 border border-red-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span> Inactive
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <a href="supplier_reports.php?report=statement&supplier_id=<?= (int)$s['id'] ?>"
                               title="Supplier statement"
                               class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center text-slate-400 hover:text-cyan-400 transition">
                                <i class="fa-solid fa-file-lines"></i>
                            </a>
                            <button type="button"
                                    onclick="openSupplierEdit(<?= (int)$s['id'] ?>,
                                        <?= htmlspecialchars(json_encode($s['company_name']), ENT_QUOTES) ?>,
                                        <?= htmlspecialchars(json_encode($s['contact_person'] ?? ''), ENT_QUOTES) ?>,
                                        <?= htmlspecialchars(json_encode($s['phone'] ?? ''), ENT_QUOTES) ?>,
                                        <?= htmlspecialchars(json_encode($s['email'] ?? ''), ENT_QUOTES) ?>,
                                        <?= htmlspecialchars(json_encode($s['address'] ?? ''), ENT_QUOTES) ?>,
                                        <?= htmlspecialchars(json_encode($s['notes'] ?? ''), ENT_QUOTES) ?>)"
                                    class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center text-slate-400 hover:text-amber-400 transition"
                                    title="Edit supplier">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_supplier">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" title="Toggle active status"
                                        class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center text-slate-400 hover:text-cyan-400 transition">
                                    <i class="fa-solid fa-toggle-on"></i>
                                </button>
                            </form>
                            <?php if (is_admin() && (int)$s['all_purchase_count'] === 0): ?>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('Delete supplier <?= htmlspecialchars($s['company_name'], ENT_QUOTES) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_supplier">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" title="Delete supplier"
                                        class="w-8 h-8 rounded-lg bg-red-500/10 border border-red-500/30 flex items-center justify-center text-red-300 hover:bg-red-500/20 transition">
                                    <i class="fa-solid fa-trash"></i>
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

<!-- EDIT MODAL -->
<div id="supplierEditModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-pen text-amber-400 mr-2"></i>Edit Supplier</h3>
            <button onclick="closeModal('supplierEditModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_supplier">
            <input type="hidden" name="id" id="se_id">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Company Name <span class="text-red-400">*</span></label>
                <input type="text" name="company_name" id="se_company" required maxlength="120"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Contact Person</label>
                    <input type="text" name="contact_person" id="se_contact" maxlength="100"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Phone</label>
                    <input type="text" name="phone" id="se_phone" maxlength="30"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                </div>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Email</label>
                <input type="email" name="email" id="se_email" maxlength="150"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Address</label>
                <input type="text" name="address" id="se_address" maxlength="255"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Notes</label>
                <textarea name="notes" id="se_notes" rows="2"
                          class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"></textarea>
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
                <i class="fa-solid fa-check mr-1"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<script>
function openSupplierEdit(id, company, contact, phone, email, address, notes) {
    document.getElementById('se_id').value = id;
    document.getElementById('se_company').value = company;
    document.getElementById('se_contact').value = contact;
    document.getElementById('se_phone').value = phone;
    document.getElementById('se_email').value = email;
    document.getElementById('se_address').value = address;
    document.getElementById('se_notes').value = notes;
    showModal('supplierEditModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
