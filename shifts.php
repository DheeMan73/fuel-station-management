<?php
/**
 * Shifts — Shift management & cashier reconciliation.
 *
 * Station Operators (and admins) open a shift with a starting cash float,
 * record sales during it, then close it by counting the drawer. The system
 * computes the expected cash balance and the variance for reconciliation.
 */
require_once __DIR__ . '/config.php';

$pageTitle    = 'Shifts';
$pageSubtitle = 'Open / close shifts and reconcile the cash drawer';

/* ==========================================================
 *  Actions
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    /* ---- Open a shift ---- */
    if ($action === 'open_shift') {
        $opening = parse_decimal($_POST['opening_cash'] ?? '0', 2, true);
        if ($opening === null) {
            set_flash('error', 'Enter a valid opening cash amount (0 or more).');
            redirect('shifts.php');
        }
        if (current_shift($pdo) !== null) {
            set_flash('error', 'You already have an open shift. Close it before opening a new one.');
            redirect('shifts.php');
        }

        $pdo->prepare(
            "INSERT INTO shifts (user_id, user_name, role, opening_cash, status, opened_at)
             VALUES (?, ?, ?, ?, 'open', NOW())"
        )->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['full_name'] ?? 'Operator',
            current_role(),
            $opening,
        ]);
        $shiftId = (int)$pdo->lastInsertId();

        log_activity($pdo, 'shift_open', sprintf('Opened shift #%d with starting cash $%s.', $shiftId, money($opening)), 'shift', $shiftId);
        set_flash('success', 'Shift opened with opening cash $' . money($opening) . '.');
        redirect('shifts.php');
    }

    /* ---- Close a shift ---- */
    if ($action === 'close_shift') {
        $closing = parse_decimal($_POST['closing_cash'] ?? '', 2, true);
        $notes   = trim((string)($_POST['notes'] ?? ''));
        if (mb_strlen($notes) > 255) {
            $notes = mb_substr($notes, 0, 255);
        }
        if ($closing === null) {
            set_flash('error', 'Enter the counted closing cash amount (0 or more).');
            redirect('shifts.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT * FROM shifts WHERE user_id = ? AND status = 'open'
                 ORDER BY opened_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$_SESSION['user_id']]);
            $shift = $stmt->fetch();

            if (!$shift) {
                throw new RuntimeException('You have no open shift to close.');
            }

            $shiftId = (int)$shift['id'];
            $totals  = shift_totals($pdo, $shiftId);

            $opening  = (float)$shift['opening_cash'];
            $cash     = (float)$totals['cash'];
            $card     = (float)$totals['card'];
            $credit   = (float)$totals['credit'];
            $total    = (float)$totals['total'];
            $expected = round($opening + $cash, 2);
            $variance = round($closing - $expected, 2);

            $pdo->prepare(
                "UPDATE shifts SET
                    cash_sales = ?, card_sales = ?, credit_sales = ?, total_sales = ?, tx_count = ?,
                    expected_cash = ?, closing_cash = ?, variance = ?, notes = ?, status = 'closed', closed_at = NOW()
                 WHERE id = ?"
            )->execute([
                $cash, $card, $credit, $total, (int)$totals['tx'],
                $expected, $closing, $variance,
                ($notes !== '' ? $notes : null),
                $shiftId,
            ]);

            $pdo->commit();

            $msg = sprintf(
                'Shift closed. Expected cash $%s, counted $%s (%s$%s).',
                money($expected), money($closing),
                $variance < 0 ? '-' : ($variance > 0 ? '+' : ''),
                money(abs($variance))
            );
            log_activity($pdo, 'shift_close', $msg, 'shift', $shiftId);
            set_flash($variance === 0.0 ? 'success' : 'error', $msg);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Could not close shift: ' . $e->getMessage());
        }
        redirect('shifts.php');
    }

    set_flash('error', 'Unknown shift action.');
    redirect('shifts.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$openShift  = current_shift($pdo);
$openTotals = $openShift ? shift_totals($pdo, (int)$openShift['id']) : null;

if (is_admin()) {
    $shifts = $pdo->query("SELECT * FROM shifts ORDER BY opened_at DESC, id DESC LIMIT 100")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE user_id = ? ORDER BY opened_at DESC, id DESC LIMIT 100");
    $stmt->execute([$_SESSION['user_id']]);
    $shifts = $stmt->fetchAll();
}

$openCount = (int)$pdo->query("SELECT COUNT(*) FROM shifts WHERE status = 'open'")->fetchColumn();

/** Human-readable duration between two timestamps. */
function shift_duration(?string $from, ?string $to = null): string
{
    if (!$from) return '—';
    $start = strtotime($from);
    $end   = $to ? strtotime($to) : time();
    $mins  = max(0, (int)round(($end - $start) / 60));
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($openShift && $openTotals): ?>
<!-- OPEN SHIFT STATUS -->
<div class="bg-slate-800 border border-emerald-500/30 rounded-2xl p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-500/15 flex items-center justify-center">
                <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span>
            </div>
            <div>
                <p class="text-emerald-400 text-xs font-semibold uppercase tracking-wider">Shift Open</p>
                <h3 class="text-white font-bold text-lg"><?= htmlspecialchars($openShift['user_name']) ?></h3>
                <p class="text-slate-500 text-xs">
                    Started <?= date('M d, Y H:i', strtotime($openShift['opened_at'])) ?>
                    • <?= shift_duration($openShift['opened_at']) ?> elapsed
                </p>
            </div>
        </div>
        <button onclick="openCloseModal()"
                class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-500 to-purple-600 text-white text-sm font-medium
                       hover:from-purple-400 hover:to-purple-500 transition shadow-lg shadow-purple-500/20">
            <i class="fa-solid fa-flag-checkered mr-1"></i> Close Shift
        </button>
    </div>

    <?php
        $opening   = (float)$openShift['opening_cash'];
        $cash      = (float)$openTotals['cash'];
        $card      = (float)$openTotals['card'];
        $credit    = (float)$openTotals['credit'];
        $total     = (float)$openTotals['total'];
        $expected  = $opening + $cash;
    ?>
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mt-6">
        <div class="bg-slate-700/40 rounded-xl px-4 py-3">
            <p class="text-slate-400 text-xs">Opening Cash</p>
            <p class="text-white font-bold mt-1">$<?= money($opening) ?></p>
        </div>
        <div class="bg-slate-700/40 rounded-xl px-4 py-3">
            <p class="text-slate-400 text-xs">Cash Sales</p>
            <p class="text-emerald-400 font-bold mt-1">$<?= money($cash) ?></p>
        </div>
        <div class="bg-slate-700/40 rounded-xl px-4 py-3">
            <p class="text-slate-400 text-xs">Card Sales</p>
            <p class="text-cyan-400 font-bold mt-1">$<?= money($card) ?></p>
        </div>
        <div class="bg-slate-700/40 rounded-xl px-4 py-3">
            <p class="text-slate-400 text-xs">Total Sales</p>
            <p class="text-white font-bold mt-1">$<?= money($total) ?></p>
        </div>
        <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-3">
            <p class="text-amber-400 text-xs font-medium">Expected in Drawer</p>
            <p class="text-amber-300 font-bold mt-1">$<?= money($expected) ?></p>
        </div>
    </div>
    <p class="text-slate-500 text-xs mt-3">
        <i class="fa-solid fa-receipt mr-1"></i><?= (int)$openTotals['tx'] ?> transactions •
        <?= fmt($openTotals['liters']) ?> L dispensed<?= $credit > 0 ? ' • Credit $' . money($credit) : '' ?>
    </p>
</div>
<?php else: ?>
<!-- OPEN SHIFT FORM -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-6">
    <h3 class="text-white font-semibold text-sm mb-1"><i class="fa-solid fa-play text-emerald-400 mr-2"></i>Start a New Shift</h3>
    <p class="text-slate-500 text-xs mb-4">Declare your starting cash so the drawer can be reconciled when you close.</p>
    <form method="POST" class="flex flex-wrap items-end gap-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="open_shift">
        <div>
            <label class="text-slate-400 text-xs block mb-1">Opening Cash ($)</label>
            <input type="number" name="opening_cash" step="0.01" min="0" value="0.00" required
                   class="w-56 bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                          focus:outline-none focus:border-emerald-500 transition">
        </div>
        <button type="submit"
                class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 text-white text-sm font-medium
                       hover:from-emerald-400 hover:to-emerald-500 transition shadow-lg shadow-emerald-500/20">
            <i class="fa-solid fa-play mr-1"></i> Open Shift
        </button>
    </form>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Open Shifts Now</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $openCount ?></p>
            <p class="text-emerald-400 text-xs mt-2"><i class="fa-solid fa-user-clock mr-1"></i>Station-wide</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-user-clock text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider"><?= is_admin() ? 'Shifts Logged' : 'My Shifts' ?></p>
            <p class="text-white text-2xl font-bold mt-1"><?= count($shifts) ?></p>
            <p class="text-cyan-400 text-xs mt-2"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Recent history</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-clock-rotate-left text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">My Shift Status</p>
            <p class="text-2xl font-bold mt-1 <?= $openShift ? 'text-emerald-400' : 'text-slate-400' ?>">
                <?= $openShift ? 'OPEN' : 'CLOSED' ?>
            </p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-id-badge mr-1"></i><?= htmlspecialchars(role_label()) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500/20 to-purple-500/5 flex items-center justify-center">
            <i class="fa-solid fa-id-badge text-purple-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- HISTORY -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-list-check text-amber-400 mr-2"></i>Shift History</h3>
        <span class="text-slate-500 text-xs"><?= is_admin() ? 'All staff' : 'Your shifts' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-700">
                    <th class="px-5 py-3 font-medium">#</th>
                    <th class="px-5 py-3 font-medium">Cashier</th>
                    <th class="px-5 py-3 font-medium">Opened</th>
                    <th class="px-5 py-3 font-medium">Duration</th>
                    <th class="px-5 py-3 font-medium text-right">Opening</th>
                    <th class="px-5 py-3 font-medium text-right">Cash</th>
                    <th class="px-5 py-3 font-medium text-right">Card</th>
                    <th class="px-5 py-3 font-medium text-right">Expected</th>
                    <th class="px-5 py-3 font-medium text-right">Counted</th>
                    <th class="px-5 py-3 font-medium text-right">Variance</th>
                    <th class="px-5 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($shifts)): ?>
                <tr><td colspan="11" class="px-6 py-12 text-center text-slate-500">
                    <i class="fa-solid fa-clock-rotate-left text-3xl mb-3 block text-slate-600"></i>No shifts recorded yet.
                </td></tr>
                <?php else: foreach ($shifts as $s):
                    $isOpen   = ($s['status'] === 'open');
                    $variance = $s['variance'] !== null ? (float)$s['variance'] : null;
                ?>
                <tr class="hover:bg-slate-700/40 transition">
                    <td class="px-5 py-3 text-slate-500">#<?= (int)$s['id'] ?></td>
                    <td class="px-5 py-3">
                        <span class="text-white font-medium"><?= htmlspecialchars($s['user_name']) ?></span>
                        <span class="block text-slate-500 text-[11px]"><?= htmlspecialchars(role_label($s['role'] ?? null)) ?></span>
                    </td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= date('M d, Y H:i', strtotime($s['opened_at'])) ?></td>
                    <td class="px-5 py-3 text-slate-400 text-xs"><?= shift_duration($s['opened_at'], $s['closed_at']) ?></td>
                    <td class="px-5 py-3 text-right text-slate-300">$<?= money($s['opening_cash']) ?></td>
                    <td class="px-5 py-3 text-right text-emerald-400">$<?= money($s['cash_sales']) ?></td>
                    <td class="px-5 py-3 text-right text-cyan-400">$<?= money($s['card_sales']) ?></td>
                    <td class="px-5 py-3 text-right text-amber-300"><?= $s['expected_cash'] !== null ? '$' . money($s['expected_cash']) : '—' ?></td>
                    <td class="px-5 py-3 text-right text-slate-200"><?= $s['closing_cash'] !== null ? '$' . money($s['closing_cash']) : '—' ?></td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($variance === null): ?>
                            <span class="text-slate-500">—</span>
                        <?php else: ?>
                            <span class="font-semibold <?= $variance < 0 ? 'text-red-400' : ($variance > 0 ? 'text-emerald-400' : 'text-slate-300') ?>">
                                <?= $variance < 0 ? '-' : ($variance > 0 ? '+' : '') ?>$<?= money(abs($variance)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <?php if ($isOpen): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Open
                        </span>
                        <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-700 text-slate-300 border border-slate-600">Closed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($openShift && $openTotals): ?>
<!-- CLOSE SHIFT MODAL -->
<div id="closeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-white font-semibold"><i class="fa-solid fa-flag-checkered text-purple-400 mr-2"></i>Close Shift</h3>
            <button onclick="closeModal('closeModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <?php
            $opening  = (float)$openShift['opening_cash'];
            $cash     = (float)$openTotals['cash'];
            $expected = $opening + $cash;
        ?>
        <div class="bg-slate-700/50 rounded-xl px-4 py-3 mb-4 space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Opening cash</span><span class="text-white">$<?= money($opening) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">+ Cash sales</span><span class="text-emerald-400">$<?= money($cash) ?></span></div>
            <div class="flex justify-between border-t border-slate-600 pt-2"><span class="text-amber-400 font-medium">Expected in drawer</span><span class="text-amber-300 font-bold">$<?= money($expected) ?></span></div>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close_shift">
            <div>
                <label class="text-slate-400 text-xs block mb-1">Counted Cash in Drawer ($)</label>
                <input type="number" name="closing_cash" step="0.01" min="0" required
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-purple-500 transition" placeholder="0.00">
            </div>
            <div>
                <label class="text-slate-400 text-xs block mb-1">Notes (optional)</label>
                <input type="text" name="notes" maxlength="255"
                       class="w-full bg-slate-700 border border-slate-600 rounded-xl px-4 py-2.5 text-white text-sm
                              focus:outline-none focus:border-purple-500 transition" placeholder="e.g. 5.00 tip left in drawer">
            </div>
            <button type="submit"
                    class="w-full py-2.5 rounded-xl bg-gradient-to-r from-purple-500 to-purple-600 text-white font-medium text-sm
                           hover:from-purple-400 hover:to-purple-500 transition shadow-lg shadow-purple-500/20">
                <i class="fa-solid fa-check mr-1"></i> Confirm &amp; Close Shift
            </button>
        </form>
    </div>
</div>
<script>
function openCloseModal() { showModal('closeModal'); }
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
