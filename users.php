<?php
/**
 * User Management — System Admin only.
 *
 * Create / edit / activate / delete *login accounts* and assign their role
 * (System Admin vs Station Operator). This is separate from `employees.php`,
 * which stores staff records that do not log in.
 *
 * RBAC: this whole page is admin-only — operators are blocked by
 * require_admin() below, in addition to being hidden from the sidebar.
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'User Management';
$pageSubtitle = 'Create login accounts and assign roles';

/** The two roles the system understands. */
$roleOptions = [
    'admin'    => 'System Admin',
    'operator' => 'Station Operator',
];

$selfId = (int)($_SESSION['user_id'] ?? 0);

/** How many *active* admins exist, optionally excluding one user. */
$activeAdminCount = static function (PDO $pdo, int $excludeId = 0): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $st->execute([$excludeId]);
    return (int)$st->fetchColumn();
};

/* ==========================================================
 *  Handle actions
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    /* ---- Create ---- */
    if ($action === 'add_user') {
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $role  = (string)($_POST['role'] ?? 'operator');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if (!isset($roleOptions[$role]))          { $role = 'operator'; }
        if ($name === '')                         { set_flash('error', 'Full name is required.'); redirect('users.php'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { set_flash('error', 'Please enter a valid email address.'); redirect('users.php'); }
        if (strlen($pass) < 6)                    { set_flash('error', 'Password must be at least 6 characters.'); redirect('users.php'); }

        $name  = mb_substr($name, 0, 100);
        $email = mb_substr($email, 0, 150);

        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ((int)$dup->fetchColumn() > 0) {
            set_flash('error', 'That email address is already registered.');
            redirect('users.php');
        }

        try {
            $pdo->prepare(
                "INSERT INTO users (full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)"
            )->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $active]);
            $newId = (int)$pdo->lastInsertId();

            log_activity($pdo, 'user_add', sprintf('Created user "%s" (%s) as %s.', $name, $email, $roleOptions[$role]), 'user', $newId);
            set_flash('success', "User \"$name\" created as {$roleOptions[$role]}.");
        } catch (Throwable $e) {
            set_flash('error', 'Could not create the user: ' . $e->getMessage());
        }
        redirect('users.php');
    }

    /* ---- Update ---- */
    if ($action === 'edit_user') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim((string)($_POST['full_name'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        $role   = (string)($_POST['role'] ?? 'operator');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $pass   = (string)($_POST['password'] ?? '');

        if (!isset($roleOptions[$role])) { $role = 'operator'; }
        if ($id <= 0 || $name === '') { set_flash('error', 'Invalid user details.'); redirect('users.php'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { set_flash('error', 'Please enter a valid email address.'); redirect('users.php'); }
        if ($pass !== '' && strlen($pass) < 6) { set_flash('error', 'New password must be at least 6 characters.'); redirect('users.php'); }

        $name  = mb_substr($name, 0, 100);
        $email = mb_substr($email, 0, 150);

        $cur = $pdo->prepare("SELECT full_name, email, role, is_active FROM users WHERE id = ?");
        $cur->execute([$id]);
        $before = $cur->fetch();
        if (!$before) { set_flash('error', 'User not found.'); redirect('users.php'); }

        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $id]);
        if ((int)$dup->fetchColumn() > 0) {
            set_flash('error', 'Another user already uses that email address.');
            redirect('users.php');
        }

        /* Do not lock everyone out: you can't demote / deactivate yourself
           or the last remaining active administrator. */
        $wasActiveAdmin = ($before['role'] === 'admin' && (int)$before['is_active'] === 1);
        $willBeActiveAdmin = ($role === 'admin' && $active === 1);
        if ($wasActiveAdmin && !$willBeActiveAdmin && $activeAdminCount($pdo, $id) === 0) {
            set_flash('error', 'Cannot demote or deactivate the last active administrator.');
            redirect('users.php');
        }
        if ($id === $selfId && !$willBeActiveAdmin) {
            set_flash('error', 'You cannot remove your own admin access.');
            redirect('users.php');
        }

        try {
            if ($pass !== '') {
                $pdo->prepare(
                    "UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ?, password = ? WHERE id = ?"
                )->execute([$name, $email, $role, $active, password_hash($pass, PASSWORD_DEFAULT), $id]);
            } else {
                $pdo->prepare(
                    "UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?"
                )->execute([$name, $email, $role, $active, $id]);
            }

            $roleChanged = ($before['role'] !== $role);
            log_activity(
                $pdo,
                $roleChanged ? 'user_role' : 'user_edit',
                sprintf(
                    'Updated user "%s" (%s)%s%s.',
                    $name, $email,
                    $roleChanged ? (' — role: ' . $roleOptions[$before['role']] . ' → ' . $roleOptions[$role]) : '',
                    $pass !== '' ? ' — password reset' : ''
                ),
                'user', $id
            );
            set_flash('success', 'User updated successfully.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not update the user: ' . $e->getMessage());
        }
        redirect('users.php');
    }

    /* ---- Quick role change ---- */
    if ($action === 'set_role') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? 'operator');
        if (!isset($roleOptions[$role])) { $role = 'operator'; }

        $cur = $pdo->prepare("SELECT full_name, role, is_active FROM users WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch();
        if ($row) {
            if ($id === $selfId && $role !== 'admin') {
                set_flash('error', 'You cannot remove your own admin access.');
                redirect('users.php');
            }
            if ($row['role'] === 'admin' && (int)$row['is_active'] === 1 && $role !== 'admin'
                && $activeAdminCount($pdo, $id) === 0) {
                set_flash('error', 'Cannot demote the last active administrator.');
                redirect('users.php');
            }
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
            log_activity($pdo, 'user_role', sprintf('Changed role of "%s" to %s.', $row['full_name'], $roleOptions[$role]), 'user', $id);
            set_flash('success', 'Role updated.');
        }
        redirect('users.php');
    }

    /* ---- Activate / deactivate ---- */
    if ($action === 'toggle_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $selfId) {
            set_flash('error', 'You cannot deactivate your own account.');
            redirect('users.php');
        }
        $cur = $pdo->prepare("SELECT full_name, role, is_active FROM users WHERE id = ?");
        $cur->execute([$id]);
        $u = $cur->fetch();
        if ($u) {
            if ($u['role'] === 'admin' && (int)$u['is_active'] === 1 && $activeAdminCount($pdo, $id) === 0) {
                set_flash('error', 'Cannot deactivate the last active administrator.');
                redirect('users.php');
            }
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'user_toggle', sprintf('%s user "%s".', $u['is_active'] ? 'Deactivated' : 'Activated', $u['full_name']), 'user', $id);
            set_flash('success', 'User status updated.');
        }
        redirect('users.php');
    }

    /* ---- Delete ---- */
    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $selfId) {
            set_flash('error', 'You cannot delete your own account.');
            redirect('users.php');
        }
        $cur = $pdo->prepare("SELECT full_name, role, is_active FROM users WHERE id = ?");
        $cur->execute([$id]);
        $u = $cur->fetch();
        if ($u) {
            if ($u['role'] === 'admin' && (int)$u['is_active'] === 1 && $activeAdminCount($pdo, $id) === 0) {
                set_flash('error', 'Cannot delete the last active administrator.');
                redirect('users.php');
            }
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'user_delete', sprintf('Deleted user "%s".', $u['full_name']), 'user', $id);
            set_flash('success', 'User deleted.');
        }
        redirect('users.php');
    }

    redirect('users.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$users = $pdo->query("SELECT * FROM users ORDER BY is_active DESC, role DESC, full_name ASC")->fetchAll();

$totalUsers    = count($users);
$adminCount    = 0;
$operatorCount = 0;
$activeCount   = 0;
foreach ($users as $u) {
    if ($u['role'] === 'admin') { $adminCount++; } else { $operatorCount++; }
    if ((int)$u['is_active'] === 1) { $activeCount++; }
}

$roleBadge = [
    'admin'    => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',    'icon' => 'fa-user-shield', 'label' => 'System Admin'],
    'operator' => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',       'icon' => 'fa-user-gear',   'label' => 'Station Operator'],
];

include __DIR__ . '/includes/header.php';
?>

<!-- STATS -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Login Accounts</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $totalUsers ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-users mr-1"></i><?= $activeCount ?> active</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-users text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">System Admins</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $adminCount ?></p>
            <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-user-shield mr-1"></i>Full access</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-shield text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Station Operators</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $operatorCount ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-user-gear mr-1"></i>Sales &amp; shifts only</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-gear text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Your Role</p>
            <p class="text-white text-lg font-bold mt-1"><?= htmlspecialchars(role_label()) ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-key mr-1"></i><?= htmlspecialchars((string)($_SESSION['email'] ?? '')) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-id-badge text-emerald-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- ROLE REFERENCE -->
<div class="bg-slate-800/60 border border-slate-700 rounded-2xl px-5 py-4 flex items-start gap-3">
    <i class="fa-solid fa-circle-info text-cyan-400 mt-0.5"></i>
    <p class="text-slate-400 text-xs leading-relaxed">
        <span class="text-amber-400 font-medium">System Admin</span> — full access: user management, suppliers &amp; purchases,
        financial reports, settings and all modules.
        <span class="text-slate-600 mx-1">|</span>
        <span class="text-cyan-400 font-medium">Station Operator</span> — Sales/POS, customer (credit) sales and shift closing only;
        the admin menus are hidden and their pages are blocked even if opened directly.
    </p>
</div>

<!-- ADD FORM -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-4"><i class="fa-solid fa-user-plus text-amber-400 mr-2"></i>Create Login Account</h3>
    <form method="POST" class="space-y-4" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Full Name <span class="text-red-400">*</span></label>
                <input type="text" name="full_name" required maxlength="100"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="e.g. Rana Haddad">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Email (login) <span class="text-red-400">*</span></label>
                <input type="email" name="email" required maxlength="150"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="name@station.com">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Password <span class="text-red-400">*</span></label>
                <input type="password" name="password" required minlength="6"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition"
                       placeholder="min. 6 characters">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Role</label>
                <select name="role" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    <option value="operator">Station Operator</option>
                    <option value="admin">System Admin</option>
                </select>
            </div>
        </div>
        <div class="flex items-center gap-6">
            <label class="inline-flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" name="is_active" value="1" checked class="accent-amber-500"> Active (can sign in)
            </label>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-medium text-sm hover:from-amber-400 hover:to-amber-500 transition shadow-lg shadow-amber-500/20">
                <i class="fa-solid fa-plus mr-1"></i> Create User
            </button>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-address-card text-cyan-400 mr-2"></i>User Accounts</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-6 py-3 font-medium">User</th>
                    <th class="px-6 py-3 font-medium">Email</th>
                    <th class="px-6 py-3 font-medium">Role</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Last Login</th>
                    <th class="px-6 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($users)): ?>
                <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-users text-3xl mb-3 block text-slate-600"></i>No users found.
                </td></tr>
                <?php else: foreach ($users as $u):
                    $rb     = $roleBadge[$u['role']] ?? $roleBadge['operator'];
                    $isSelf = ((int)$u['id'] === $selfId);
                    $active = ((int)$u['is_active'] === 1);
                ?>
                <tr class="hover:bg-slate-700/40 transition <?= $active ? '' : 'opacity-60' ?>">
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br <?= $u['role'] === 'admin' ? 'from-amber-500 to-orange-500' : 'from-cyan-500 to-blue-500' ?> flex items-center justify-center text-white text-xs font-bold shadow-lg">
                                <?= htmlspecialchars(strtoupper(mb_substr((string)$u['full_name'], 0, 1))) ?>
                            </div>
                            <div>
                                <span class="text-white font-medium"><?= htmlspecialchars((string)$u['full_name']) ?></span>
                                <?php if ($isSelf): ?><span class="ml-1 text-[10px] text-slate-500">(you)</span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-3 text-slate-400"><?= htmlspecialchars((string)$u['email']) ?></td>
                    <td class="px-6 py-3">
                        <form method="POST" class="inline-flex items-center gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <select name="role" onchange="this.form.submit()"
                                    class="bg-slate-700 border border-slate-600 rounded-lg px-2 py-1 text-xs text-white focus:outline-none focus:border-amber-500 <?= $isSelf ? 'opacity-70' : '' ?>">
                                <option value="admin"    <?= $u['role'] === 'admin'    ? 'selected' : '' ?>>System Admin</option>
                                <option value="operator" <?= $u['role'] === 'operator' ? 'selected' : '' ?>>Station Operator</option>
                            </select>
                        </form>
                    </td>
                    <td class="px-6 py-3">
                        <?php if ($active): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border bg-emerald-500/10 text-emerald-400 border-emerald-500/20">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border bg-slate-700 text-slate-300 border-slate-600">
                                <i class="fa-solid fa-user-slash"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-slate-400 text-xs">
                        <?= !empty($u['last_login']) ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$u['last_login']))) : '<span class="text-slate-600">never</span>' ?>
                    </td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <button type="button" class="text-slate-400 hover:text-amber-400 text-xs" title="Edit"
                                    onclick='openUser(<?= json_encode([
                                        'id' => (int)$u['id'],
                                        'full_name' => (string)$u['full_name'],
                                        'email' => (string)$u['email'],
                                        'role' => (string)$u['role'],
                                        'is_active' => (int)$u['is_active'],
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>

                            <?php if (!$isSelf): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_user">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="text-<?= $active ? 'emerald' : 'slate' ?>-400 hover:text-white text-xs" title="<?= $active ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fa-solid fa-power-off"></i>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this user account? This cannot be undone.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="text-slate-500 hover:text-red-400 text-xs" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php else: ?>
                            <span class="text-slate-600 text-xs" title="You cannot deactivate or delete your own account"><i class="fa-solid fa-lock"></i></span>
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
<div id="userEditModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-user-pen text-amber-400 mr-2"></i>Edit User</h3>
            <button onclick="closeModal('userEditModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="id" id="ue_id" value="0">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Full Name</label>
                    <input type="text" name="full_name" id="ue_name" required maxlength="100"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Email (login)</label>
                    <input type="email" name="email" id="ue_email" required maxlength="150"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Role</label>
                    <select name="role" id="ue_role" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                        <option value="admin">System Admin</option>
                        <option value="operator">Station Operator</option>
                    </select>
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">New Password <span class="text-slate-500">(leave blank to keep)</span></label>
                    <input type="password" name="password" minlength="6"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" name="is_active" id="ue_active" value="1" class="accent-amber-500"> Account active (can log in)
            </label>
            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal('userEditModal')" class="px-4 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-medium transition">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white text-sm font-medium hover:from-amber-400 hover:to-amber-500 transition">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openUser(u) {
    document.getElementById('ue_id').value     = u.id || 0;
    document.getElementById('ue_name').value   = u.full_name || '';
    document.getElementById('ue_email').value  = u.email || '';
    document.getElementById('ue_role').value   = u.role || 'operator';
    document.getElementById('ue_active').checked = !!u.is_active;
    showModal('userEditModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
