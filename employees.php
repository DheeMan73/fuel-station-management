<?php
/**
 * Employees — System Admin only.
 * Create / update / activate / delete station employees.
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'Employees';
$pageSubtitle = 'Manage your station staff';

$roleOptions = ['Attendant', 'Cashier', 'Manager', 'Supervisor', 'Mechanic'];

/* ==========================================================
 *  Handle actions
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $role  = trim((string)($_POST['role'] ?? 'Attendant'));
        $phone = trim((string)($_POST['phone'] ?? ''));

        if ($name === '') {
            set_flash('error', 'Employee name is required.');
        } elseif (mb_strlen($name) > 100) {
            set_flash('error', 'Employee name is too long (max 100).');
        } else {
            if (!in_array($role, $roleOptions, true)) $role = 'Attendant';
            $phone = mb_substr($phone, 0, 20);
            $pdo->prepare("INSERT INTO employees (full_name, role, phone, is_active) VALUES (?, ?, ?, 1)")
                ->execute([$name, $role, ($phone !== '' ? $phone : null)]);
            $newId = (int)$pdo->lastInsertId();

            log_activity($pdo, 'employee_add', sprintf('Added employee "%s" as %s.', $name, $role), 'employee', $newId);
            set_flash('success', "Employee \"$name\" added successfully.");
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $role  = trim((string)($_POST['role'] ?? 'Attendant'));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $name === '') {
            set_flash('error', 'Invalid employee details.');
        } else {
            if (!in_array($role, $roleOptions, true)) $role = 'Attendant';
            $phone = mb_substr($phone, 0, 20);
            $pdo->prepare("UPDATE employees SET full_name = ?, role = ?, phone = ?, is_active = ? WHERE id = ?")
                ->execute([$name, $role, ($phone !== '' ? $phone : null), $active, $id]);

            log_activity($pdo, 'employee_edit', sprintf('Updated employee "%s" (%s).', $name, $role), 'employee', $id);
            set_flash('success', 'Employee updated successfully.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT full_name, is_active FROM employees WHERE id = ?");
            $row->execute([$id]);
            $emp = $row->fetch();

            $pdo->prepare("UPDATE employees SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

            if ($emp) {
                log_activity($pdo, 'employee_toggle', sprintf(
                    '%s employee "%s".',
                    $emp['is_active'] ? 'Deactivated' : 'Activated',
                    $emp['full_name']
                ), 'employee', $id);
            }
            set_flash('success', 'Employee status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
            $row->execute([$id]);
            $name = (string)$row->fetchColumn();

            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);

            log_activity($pdo, 'employee_delete', sprintf('Deleted employee "%s".', $name !== '' ? $name : '#' . $id), 'employee', $id);
            set_flash('success', 'Employee removed.');
        }
    }
    redirect('employees.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$employees     = $pdo->query("SELECT * FROM employees ORDER BY is_active DESC, full_name ASC")->fetchAll();
$activeCount   = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 1")->fetchColumn();
$inactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 0")->fetchColumn();

$avatarColors = [
    'Manager'    => 'from-amber-500 to-orange-500',
    'Cashier'    => 'from-emerald-500 to-teal-500',
    'Attendant'  => 'from-cyan-500 to-blue-500',
    'Supervisor' => 'from-purple-500 to-pink-500',
    'Mechanic'   => 'from-red-500 to-rose-500',
];

include __DIR__ . '/includes/header.php';
?>

<!-- STATS -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Employees</p>
            <p class="text-white text-2xl font-bold mt-1"><?= count($employees) ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-users mr-1"></i>All staff</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-users text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Active</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $activeCount ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-circle-check mr-1"></i>Currently working</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-check text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Inactive</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $inactiveCount ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-user-slash mr-1"></i>Off duty</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500/20 to-red-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-slash text-red-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- ADD FORM -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-user-plus text-amber-400 mr-2"></i>Add New Employee</h3>
    <form method="POST" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Full Name</label>
                <input type="text" name="full_name" required maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-amber-500 transition" placeholder="e.g. Ahmad Khalil">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Role</label>
                <select name="role" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                                           focus:outline-none focus:border-amber-500 transition">
                    <?php foreach ($roleOptions as $r): ?>
                    <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Phone</label>
                <input type="text" name="phone" maxlength="20"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-amber-500 transition" placeholder="+961-71-000000">
            </div>
        </div>
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm
                                     hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
            <i class="fa-solid fa-plus mr-1"></i> Add Employee
        </button>
    </form>
</div>

<!-- TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-address-book text-cyan-400 mr-2"></i>Employee Directory</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">ID</th>
                    <th class="px-6 py-3 font-medium">Name</th>
                    <th class="px-6 py-3 font-medium">Role</th>
                    <th class="px-6 py-3 font-medium">Phone</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Joined</th>
                    <th class="px-6 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($employees)): ?>
                <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-users text-3xl mb-3 block text-slate-600"></i>No employees found.
                </td></tr>
                <?php else: foreach ($employees as $emp):
                    $avColor = $avatarColors[$emp['role']] ?? 'from-slate-500 to-slate-600';
                    $initials = strtoupper(mb_substr($emp['full_name'], 0, 1));
                    $words = explode(' ', $emp['full_name']);
                    if (count($words) >= 2) $initials .= strtoupper(mb_substr($words[1], 0, 1));
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-6 py-3 text-slate-500">#<?= (int)$emp['id'] ?></td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br <?= $avColor ?> flex items-center justify-center text-white text-xs font-bold shadow-lg">
                                <?= htmlspecialchars($initials) ?>
                            </div>
                            <span class="text-white font-medium"><?= htmlspecialchars($emp['full_name']) ?></span>
                        </div>
                    </td>
                    <td class="px-6 py-3">
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-700 text-slate-300 border border-slate-600">
                            <?= htmlspecialchars($emp['role']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-3 text-slate-400 text-xs"><?= htmlspecialchars($emp['phone'] ?: '—') ?></td>
                    <td class="px-6 py-3">
                        <?php if ($emp['is_active']): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-400 border border-red-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span> Inactive
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-slate-500 text-xs"><?= date('M d, Y', strtotime($emp['created_at'])) ?></td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    onclick="openEditModal(<?= (int)$emp['id'] ?>, <?= htmlspecialchars(json_encode($emp['full_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($emp['role']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($emp['phone'] ?? ''), ENT_QUOTES) ?>, <?= (int)$emp['is_active'] ?>)"
                                    class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center
                                           text-slate-400 hover:text-amber-400 hover:border-amber-500/40 transition" title="Edit">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Toggle this employee\'s status?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                <button type="submit" class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center
                                                           text-slate-400 hover:text-cyan-400 hover:border-cyan-500/40 transition" title="Toggle Status">
                                    <i class="fa-solid fa-toggle-on text-xs"></i>
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this employee permanently?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                <button type="submit" class="w-8 h-8 rounded-lg bg-slate-700/60 border border-slate-600 flex items-center justify-center
                                                           text-slate-400 hover:text-red-400 hover:border-red-500/40 transition" title="Delete">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-user-pen text-amber-400 mr-2"></i>Edit Employee</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Full Name</label>
                <input type="text" name="full_name" id="edit_name" required maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Role</label>
                <select name="role" id="edit_role"
                        class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <?php foreach ($roleOptions as $r): ?>
                    <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Phone</label>
                <input type="text" name="phone" id="edit_phone" maxlength="20"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
            </div>
            <label class="flex items-center gap-2 text-slate-400 text-sm cursor-pointer">
                <input type="checkbox" name="is_active" id="edit_active" class="w-4 h-4 rounded bg-slate-700 border-slate-600 text-amber-500 focus:ring-amber-500">
                Active (working)
            </label>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm
                                         hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
                <i class="fa-solid fa-check mr-1"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, role, phone, active) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_active').checked = (Number(active) === 1);
    showModal('editModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
