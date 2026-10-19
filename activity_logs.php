<?php
/**
 * Activity Logs — System Admin only.
 * Full audit trail of critical actions (logins, price changes,
 * refills, sales, voids, employee changes, shifts).
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'Activity Logs';
$pageSubtitle = 'Full audit trail of critical system actions';

/* ==========================================================
 *  Filters
 * ========================================================= */
$fAction = trim((string)($_GET['action'] ?? ''));
$fDate   = trim((string)($_GET['date'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];

if ($fAction !== '') {
    $where[]  = 'action = ?';
    $params[] = $fAction;
}
if ($fDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate) === 1) {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $fDate;
}
if ($q !== '') {
    $where[]  = '(description LIKE ? OR user_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---- Pagination ---- */
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listStmt = $pdo->prepare("SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$logs = $listStmt->fetchAll();

/* ---- KPIs ---- */
$kpi = [
    'total'  => (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
    'today'  => (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    'logins' => (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='login' AND DATE(created_at)=CURDATE()")->fetchColumn(),
    'alerts' => (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('login_failed','tank_refill_blocked','sale_void')")->fetchColumn(),
];

$actionOptions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/includes/header.php';
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Events</p>
            <p class="text-white text-2xl font-bold mt-1"><?= number_format($kpi['total']) ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-list mr-1"></i>All-time audit entries</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-shield-halved text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Today</p>
            <p class="text-white text-2xl font-bold mt-1"><?= number_format($kpi['today']) ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-calendar-day mr-1"></i>Since midnight</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-calendar-day text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Logins Today</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $kpi['logins'] ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-right-to-bracket mr-1"></i>Successful sign-ins</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-shield text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Security / Voids</p>
            <p class="text-white text-2xl font-bold mt-1"><?= number_format($kpi['alerts']) ?></p>
            <p class="text-red-400 text-xs mt-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Failed logins, blocked refills, voids</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500/20 to-red-500/5 flex items-center justify-center">
            <i class="fa-solid fa-triangle-exclamation text-red-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- LOG TABLE -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <h3 class="text-white font-semibold text-sm">
            <i class="fa-solid fa-shield-halved text-amber-400 mr-2"></i>Activity Trail
            <span class="text-slate-500 font-normal text-xs ml-2">(<?= number_format($totalRows) ?> entries)</span>
        </h3>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="action" class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
                <option value="">All actions</option>
                <?php foreach ($actionOptions as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $fAction ? 'selected' : '' ?>>
                    <?= htmlspecialchars(activity_meta($opt)['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?= htmlspecialchars($fDate) ?>"
                   class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search&hellip;"
                   class="bg-slate-700 border border-slate-600 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-amber-500">
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">
                <i class="fa-solid fa-filter mr-1"></i>Filter
            </button>
            <?php if ($fAction !== '' || $fDate !== '' || $q !== ''): ?>
            <a href="activity_logs.php" class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-slate-300 text-xs hover:text-white transition">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">When</th>
                    <th class="px-5 py-3 font-medium">User</th>
                    <th class="px-5 py-3 font-medium">Action</th>
                    <th class="px-5 py-3 font-medium">Description</th>
                    <th class="px-5 py-3 font-medium">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-shield-halved text-3xl mb-3 block text-slate-600"></i>No activity recorded for this filter.
                </td></tr>
                <?php else: foreach ($logs as $log):
                    $m = activity_meta($log['action']);
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3 text-slate-500 text-xs whitespace-nowrap"><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <span class="text-white font-medium text-sm"><?= htmlspecialchars($log['user_name']) ?></span>
                        <span class="block text-slate-500 text-[11px]"><?= htmlspecialchars(role_label($log['role'] ?? null)) ?></span>
                    </td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border <?= $m['cls'] ?>">
                            <i class="fa-solid <?= $m['icon'] ?>"></i> <?= htmlspecialchars($m['label']) ?>
                        </span>
                    </td>
                    <td class="px-5 py-3 text-slate-300 text-xs max-w-md"><?= htmlspecialchars($log['description'] ?? '') ?></td>
                    <td class="px-5 py-3 text-slate-500 text-xs"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-slate-700 flex items-center justify-between">
        <span class="text-slate-500 text-xs">Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="flex items-center gap-2">
            <?php
                $qs = $_GET;
                $mk = function (int $p) use ($qs) { $qs['page'] = $p; return '?' . http_build_query($qs); };
            ?>
            <a href="<?= $page > 1 ? htmlspecialchars($mk($page - 1)) : '#' ?>"
               class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-xs <?= $page > 1 ? 'text-slate-200 hover:text-white' : 'text-slate-600 pointer-events-none' ?> transition">
                <i class="fa-solid fa-chevron-left"></i> Prev
            </a>
            <a href="<?= $page < $totalPages ? htmlspecialchars($mk($page + 1)) : '#' ?>"
               class="px-3 py-1.5 rounded-lg bg-slate-700 border border-slate-600 text-xs <?= $page < $totalPages ? 'text-slate-200 hover:text-white' : 'text-slate-600 pointer-events-none' ?> transition">
                Next <i class="fa-solid fa-chevron-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
