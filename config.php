<?php
/**
 * ================================================================
 *  Shared Configuration
 *  - Session bootstrap
 *  - PDO (MySQL) connection
 *  - Auto schema migration (money precision, status, price_history)
 *  - Helpers: money/decimal validation, flash messages, CSRF
 *  - RBAC: System Admin  vs  Station Operator
 *
 *  Included at the top of every page.
 * ================================================================
 */

session_start();

/** A tank is considered "low" when its stock falls below this share of capacity. */
const LOW_FUEL_THRESHOLD = 0.15;

/* Source label written to price_history.source for automated feed syncs.
   Older rows use 'Market'; both are accepted when filtering. */
const MARKET_SYNC_SOURCE = 'Market Rate Sync';

/* ---------------------------------------------------------------
 *  Database connection
 * ------------------------------------------------------------- */
$DB_HOST = '127.0.0.1';
$DB_NAME = 'fuel_station_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<div style="color:#f87171;padding:2rem;font-family:sans-serif;background:#0F172A;min-height:100vh;">
         <h2>Database Connection Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>');
}

/* ===============================================================
 *  Generic helpers
 * ============================================================= */

/** Format a number with thousands separators. */
function fmt($n, $decimals = 2)
{
    return number_format((float)$n, (int)$decimals, '.', ',');
}

/** Format money — always 2 decimals. */
function money($n)
{
    return number_format((float)$n, 2, '.', ',');
}

/**
 * Validate a positive decimal input (money / liters / rate).
 * Returns a rounded float, or null when the input is invalid.
 */
function parse_decimal($value, int $decimals = 2, bool $allowZero = false): ?float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || $value === null) {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $f = (float)$value;
    if (!is_finite($f) || $f < 0) {
        return null;
    }
    if (!$allowZero && $f <= 0) {
        return null;
    }
    return round($f, $decimals);
}

/** Redirect and terminate. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ---- Flash messages (survive one redirect) ---- */

function set_flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/* ---- CSRF protection ---- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $sess = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        set_flash('error', 'Security token expired. Please try again.');
        redirect(basename($_SERVER['PHP_SELF']));
    }
}

/* ===============================================================
 *  Role-Based Access Control
 *  System Admin  -> role = 'admin'
 *  Station Operator -> role = 'operator'
 * ============================================================= */

function current_role(): string
{
    return (($_SESSION['role'] ?? 'operator') === 'admin') ? 'admin' : 'operator';
}

function role_label(?string $role = null): string
{
    $role = $role ?? current_role();
    return $role === 'admin' ? 'System Admin' : 'Station Operator';
}

function is_admin(): bool
{
    return current_role() === 'admin';
}

/** Block the request for non-admins with the standard error message. */
function require_admin(): void
{
    if (!is_admin()) {
        set_flash('error', 'Access Denied: Admin privileges required.');
        redirect('index.php');
    }
}

/* ===============================================================
 *  Settings (key / value store)
 * ============================================================= */

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $s = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    );
    $s->execute([$key, $value, $value]);
}

/* ===============================================================
 *  Low-fuel stock alerts
 * ============================================================= */

/**
 * Every tank whose current stock is below LOW_FUEL_THRESHOLD of capacity.
 * Adds a computed `fill_pct` (0-100) to each row. Never throws.
 */
function low_fuel_tanks(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT * FROM fuel_inventory WHERE tank_capacity_liters > 0")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $low = [];
    foreach ($rows as $r) {
        $cap = (float)$r['tank_capacity_liters'];
        $cur = (float)$r['current_stock_liters'];
        if ($cap <= 0) {
            continue;
        }
        $ratio = $cur / $cap;
        if ($ratio < LOW_FUEL_THRESHOLD) {
            $r['fill_pct'] = round($ratio * 100, 1);
            $low[] = $r;
        }
    }
    return $low;
}

/* ===============================================================
 *  Shift management helpers
 * ============================================================= */

/** The currently-open shift belonging to the signed-in user (or null). */
function current_shift(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $s = $pdo->prepare(
            "SELECT * FROM shifts WHERE user_id = ? AND status = 'open'
             ORDER BY opened_at DESC, id DESC LIMIT 1"
        );
        $s->execute([$_SESSION['user_id']]);
        $row = $s->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Live sales totals for a shift, grouped by payment method. */
function shift_totals(PDO $pdo, int $shiftId): array
{
    $empty = ['tx' => 0, 'liters' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'credit' => 0.0, 'total' => 0.0];
    if ($shiftId <= 0) {
        return $empty;
    }
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) AS tx,
                    COALESCE(SUM(liters_sold),0) AS liters,
                    COALESCE(SUM(CASE WHEN payment_method = 'Cash'   THEN total_amount ELSE 0 END),0) AS cash,
                    COALESCE(SUM(CASE WHEN payment_method = 'Card'   THEN total_amount ELSE 0 END),0) AS card,
                    COALESCE(SUM(CASE WHEN payment_method = 'Credit' THEN total_amount ELSE 0 END),0) AS credit,
                    COALESCE(SUM(total_amount),0) AS total
             FROM sales WHERE shift_id = ?"
        );
        $s->execute([$shiftId]);
        $row = $s->fetch();
        return $row ?: $empty;
    } catch (Throwable $e) {
        return $empty;
    }
}

/* ===============================================================
 *  Suppliers, fuel purchases & payments
 * ============================================================= */

/** Every tank (drives supplier purchase fuel/fuel-type choices). */
function fuel_tanks_list(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT * FROM fuel_inventory ORDER BY fuel_type")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Per-supplier money summary.
 * purchases = non-cancelled purchase value, paid = recorded payments,
 * outstanding = purchases - paid (supplier debt).
 */
function supplier_summary(PDO $pdo, int $supplierId): array
{
    $out = ['purchases' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'count' => 0];
    if ($supplierId <= 0) {
        return $out;
    }
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(total_cost),0) AS t
             FROM supplier_purchases
             WHERE supplier_id = ? AND status <> 'cancelled'"
        );
        $s->execute([$supplierId]);
        $row = $s->fetch() ?: [];
        $out['count']     = (int)($row['c'] ?? 0);
        $out['purchases'] = (float)($row['t'] ?? 0);

        $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = ?");
        $p->execute([$supplierId]);
        $out['paid'] = (float)$p->fetchColumn();

        $out['outstanding'] = round($out['purchases'] - $out['paid'], 2);
    } catch (Throwable $e) {
        /* never break the page over a summary */
    }
    return $out;
}

/** Station-wide supplier debt overview (used by the dashboard & reports). */
function supplier_debt_overview(PDO $pdo): array
{
    $out = ['suppliers' => 0, 'purchases' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'open_invoices' => 0];
    try {
        $out['suppliers'] = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
        $out['purchases'] = (float)$pdo->query(
            "SELECT COALESCE(SUM(total_cost),0) FROM supplier_purchases WHERE status <> 'cancelled'"
        )->fetchColumn();
        $out['paid'] = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM supplier_payments")->fetchColumn();
        $out['outstanding'] = round($out['purchases'] - $out['paid'], 2);
        $out['unpaid_invoices'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM supplier_purchases
             WHERE status <> 'cancelled' AND payment_status <> 'Paid'"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* ignore */
    }
    return $out;
}

/** Recompute a purchase's amount_paid + payment_status from its payments. */
function recalc_purchase_payment(PDO $pdo, int $purchaseId): void
{
    if ($purchaseId <= 0) {
        return;
    }
    try {
        $p = $pdo->prepare("SELECT total_cost FROM supplier_purchases WHERE id = ?");
        $p->execute([$purchaseId]);
        $total = $p->fetchColumn();
        if ($total === false) {
            return;
        }
        $total = (float)$total;

        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE purchase_id = ?");
        $s->execute([$purchaseId]);
        $paid = (float)$s->fetchColumn();

        $status = $paid <= 0.004
            ? 'Unpaid'
            : ($paid >= $total - 0.004 ? 'Paid' : 'Partial');

        $pdo->prepare("UPDATE supplier_purchases SET amount_paid = ?, payment_status = ? WHERE id = ?")
            ->execute([min($paid, $total), $status, $purchaseId]);
    } catch (Throwable $e) {
        /* ignore */
    }
}

/** Presentation metadata for purchase delivery status. */
function purchase_status_meta(string $status): array
{
    $map = [
        'pending'   => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',  'icon' => 'fa-hourglass-half',   'label' => 'Pending'],
        'confirmed' => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-circle-check', 'label' => 'Confirmed'],
        'cancelled' => ['cls' => 'bg-slate-700 text-slate-400 border-slate-600',        'icon' => 'fa-ban',              'label' => 'Cancelled'],
    ];
    return $map[$status] ?? ['cls' => 'bg-slate-700 text-slate-300 border-slate-600', 'icon' => 'fa-circle-info', 'label' => ucfirst($status)];
}

/** Presentation metadata for purchase payment status. */
function payment_status_meta(string $status): array
{
    $map = [
        'Paid'    => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-circle-check',        'label' => 'Paid'],
        'Partial' => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-circle-half-stroke',  'label' => 'Partial'],
        'Unpaid'  => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-circle-exclamation',  'label' => 'Unpaid'],
    ];
    return $map[$status] ?? ['cls' => 'bg-slate-700 text-slate-300 border-slate-600', 'icon' => 'fa-circle-info', 'label' => $status];
}

/* ===============================================================
 *  Market prices (fuel price intelligence)
 *
 *  Observations are keyed by (fuel_type, city, price_date, source).
 *  A price is only ever presented as "current" while it is inside
 *  the freshness window (`market_stale_days`); anything older is
 *  shown as "stale". Demo rows are flagged and never silently
 *  presented as live market data.
 * ============================================================= */

/** Cities that have recorded market prices, with observation counts. */
function market_cities(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT city, COUNT(*) AS n, MAX(price_date) AS last_date
             FROM market_prices GROUP BY city ORDER BY city"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['city']] = ['n' => (int)$r['n'], 'last_date' => (string)$r['last_date']];
    }
    return $out;
}

/** Fuel types the station tracks (mirrors fuel_inventory). */
function market_fuel_types(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT fuel_type FROM fuel_inventory ORDER BY fuel_type")->fetchAll(PDO::FETCH_COLUMN);
        $rows = array_values(array_filter(array_map('strval', $rows)));
        return $rows ?: ['Petrol', 'Diesel', 'LPG'];
    } catch (Throwable $e) {
        return ['Petrol', 'Diesel', 'LPG'];
    }
}

/** Observations older than this many days are labelled "stale", never "current". */
function market_stale_days(PDO $pdo): int
{
    $v = (int)(get_setting($pdo, 'market_stale_days', '3') ?? 3);
    return $v > 0 ? $v : 3;
}

/** Freshness of an observation, measured from its effective price_date. */
function market_freshness(string $priceDate, int $staleDays): array
{
    $ts = strtotime(substr($priceDate, 0, 10) . ' 00:00:00');
    if ($ts === false) {
        return ['age_days' => null, 'is_fresh' => false, 'label' => 'Unknown'];
    }
    $age   = (int)floor((strtotime('today') - $ts) / 86400);
    $fresh = $age <= $staleDays;
    return ['age_days' => $age, 'is_fresh' => $fresh, 'label' => $fresh ? 'Current' : 'Stale'];
}

/**
 * Latest market observation per (fuel_type, city) with the previous price,
 * absolute / percentage change and freshness. Sorted by fuel then city.
 */
function market_current_rows(PDO $pdo, ?string $fuel = null, ?string $city = null, bool $includeDemo = true): array
{
    $where = [];
    $args  = [];
    if ($fuel !== null && $fuel !== '') { $where[] = 'fuel_type = ?'; $args[] = $fuel; }
    if ($city !== null && $city !== '') { $where[] = 'city = ?';      $args[] = $city; }
    if (!$includeDemo)                  { $where[] = 'is_demo = 0'; }

    try {
        $st = $pdo->prepare(
            "SELECT * FROM market_prices"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY fuel_type ASC, city ASC, price_date DESC, created_at DESC, id DESC"
        );
        $st->execute($args);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['fuel_type'] . "\0" . $r['city']][] = $r;
    }

    $staleDays = market_stale_days($pdo);
    $out = [];
    foreach ($groups as $list) {
        $latest = $list[0];
        $prev   = $list[1] ?? null;
        $cur    = (float)$latest['price_per_liter'];
        $pv     = $prev ? (float)$prev['price_per_liter'] : null;
        $diff   = $pv === null ? null : round($cur - $pv, 2);
        $pct    = ($pv === null || $pv == 0.0) ? null : round((($cur - $pv) / $pv) * 100, 2);
        $fr     = market_freshness((string)$latest['price_date'], $staleDays);

        $out[] = $latest + [
            'prev_price'   => $pv,
            'prev_date'    => $prev['price_date'] ?? null,
            'change'       => $diff,
            'change_pct'   => $pct,
            'age_days'     => $fr['age_days'],
            'is_fresh'     => $fr['is_fresh'],
            'fresh_label'  => $fr['label'],
            'observations' => count($list),
        ];
    }
    usort($out, static function ($a, $b) {
        return strcmp($a['fuel_type'] . $a['city'], $b['fuel_type'] . $b['city']);
    });
    return $out;
}

/** Daily average price points for one fuel over the last N days (ascending). */
function market_series(PDO $pdo, string $fuel, ?string $city, int $days, bool $includeDemo = true): array
{
    $days  = max(1, min(800, $days));
    $where = ["fuel_type = ?", "price_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)"];
    $args  = [$fuel];
    if ($city !== null && $city !== '') { $where[] = 'city = ?'; $args[] = $city; }
    if (!$includeDemo)                  { $where[] = 'is_demo = 0'; }

    try {
        $st = $pdo->prepare(
            "SELECT price_date, ROUND(AVG(price_per_liter), 2) AS price, COUNT(*) AS n
             FROM market_prices
             WHERE " . implode(' AND ', $where) . "
             GROUP BY price_date
             ORDER BY price_date ASC"
        );
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Per-fuel comparison: latest market price (optionally for one city), the
 * station's selling price, and the latest / 30-day average supplier cost.
 */
function market_compare(PDO $pdo, ?string $city = null, bool $includeDemo = true): array
{
    $staleDays = market_stale_days($pdo);
    $out = [];

    foreach (fuel_tanks_list($pdo) as $t) {
        $fuel = (string)$t['fuel_type'];
        $sell = (float)$t['price_per_liter'];

        $where = ['fuel_type = ?'];
        $args  = [$fuel];
        if ($city !== null && $city !== '') { $where[] = 'city = ?'; $args[] = $city; }
        if (!$includeDemo)                  { $where[] = 'is_demo = 0'; }

        $mkt = null; $cost = null; $avg30 = null;
        try {
            $m = $pdo->prepare(
                "SELECT * FROM market_prices WHERE " . implode(' AND ', $where)
                . " ORDER BY price_date DESC, created_at DESC, id DESC LIMIT 1"
            );
            $m->execute($args);
            $mkt = $m->fetch() ?: null;

            $c = $pdo->prepare(
                "SELECT cost_per_liter, delivery_date, created_at
                 FROM supplier_purchases
                 WHERE fuel_type = ? AND status = 'confirmed'
                 ORDER BY COALESCE(delivery_date, DATE(created_at)) DESC, id DESC LIMIT 1"
            );
            $c->execute([$fuel]);
            $cost = $c->fetch() ?: null;

            $a = $pdo->prepare(
                "SELECT ROUND(AVG(cost_per_liter), 2) FROM supplier_purchases
                 WHERE fuel_type = ? AND status = 'confirmed'
                   AND COALESCE(delivery_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
            );
            $a->execute([$fuel]);
            $v = $a->fetchColumn();
            $avg30 = ($v === false || $v === null) ? null : (float)$v;
        } catch (Throwable $e) {
            /* comparison is best-effort */
        }

        $mktPrice = $mkt ? (float)$mkt['price_per_liter'] : null;
        $costL    = $cost ? (float)$cost['cost_per_liter'] : null;

        $out[] = [
            'fuel_type'      => $fuel,
            'selling_price'  => $sell,
            'market_price'   => $mktPrice,
            'market_city'    => $mkt['city'] ?? null,
            'market_date'    => $mkt['price_date'] ?? null,
            'market_source'  => $mkt['source'] ?? null,
            'market_demo'    => $mkt ? (int)$mkt['is_demo'] : 0,
            'market_fresh'   => $mkt ? market_freshness((string)$mkt['price_date'], $staleDays)['is_fresh'] : false,
            'cost_price'     => $costL,
            'cost_date'      => $cost['delivery_date'] ?? null,
            'cost_avg30'     => $avg30,
            'vs_market'      => $mktPrice === null ? null : round($sell - $mktPrice, 2),
            'vs_cost'        => $costL === null ? null : round($sell - $costL, 2),
            'market_vs_cost' => ($mktPrice === null || $costL === null) ? null : round($mktPrice - $costL, 2),
        ];
    }
    return $out;
}

/** Alert rule configuration. */
function market_alert_settings(PDO $pdo): array
{
    $dir = (string)get_setting($pdo, 'market_alert_direction', 'both');
    if (!in_array($dir, ['both', 'up', 'down'], true)) {
        $dir = 'both';
    }
    $pct = (float)get_setting($pdo, 'market_alert_pct', '3');
    return [
        'enabled'   => get_setting($pdo, 'market_alert_enabled', '1') === '1',
        'pct'       => $pct > 0 ? $pct : 3.0,
        'direction' => $dir,
    ];
}

/**
 * The most recent observation for a fuel+city strictly before the given date
 * (or the same date but a different row). Used for change / alert maths.
 */
function market_previous_row(PDO $pdo, string $fuel, string $city, string $date, int $excludeId = 0): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT * FROM market_prices
             WHERE fuel_type = ? AND city = ?
               AND (price_date < ? OR (price_date = ? AND id <> ?))
             ORDER BY price_date DESC, created_at DESC, id DESC LIMIT 1"
        );
        $st->execute([$fuel, $city, substr($date, 0, 10), substr($date, 0, 10), $excludeId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Insert or update one market observation and, when the change crosses the
 * configured threshold, record an alert. Best-effort — never throws.
 * Returns ['ok','action','id','change','change_pct','previous','alert'].
 */
function market_record(PDO $pdo, array $obs): array
{
    $fuel  = trim((string)($obs['fuel_type'] ?? ''));
    $city  = trim((string)($obs['city'] ?? ''));
    $price = (float)($obs['price_per_liter'] ?? 0);
    $date  = substr(trim((string)($obs['price_date'] ?? date('Y-m-d'))), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $date = date('Y-m-d');
    }
    $src = trim((string)($obs['source'] ?? 'Manual'));
    if ($src === '') $src = 'Manual';
    $src = mb_substr($src, 0, 80);

    if ($fuel === '' || $city === '' || !is_finite($price) || $price <= 0) {
        return ['ok' => false, 'error' => 'A valid fuel type, city and positive price are required.'];
    }

    $region = trim((string)($obs['region'] ?? ''));
    $ref    = trim((string)($obs['source_ref'] ?? ''));
    $notes  = trim((string)($obs['notes'] ?? ''));
    $by     = trim((string)($obs['recorded_by'] ?? ''));
    if ($by === '') $by = (string)($_SESSION['full_name'] ?? 'System');
    $cur    = trim((string)($obs['currency'] ?? 'USD'));
    if ($cur === '') $cur = 'USD';
    $demo   = !empty($obs['is_demo']) ? 1 : 0;
    $priceStr = number_format(round($price, 2), 2, '.', '');

    try {
        $find = $pdo->prepare(
            "SELECT id FROM market_prices WHERE fuel_type = ? AND city = ? AND price_date = ? AND source = ?"
        );
        $find->execute([$fuel, $city, $date, $src]);
        $existingId = (int)($find->fetchColumn() ?: 0);

        $prev = market_previous_row($pdo, $fuel, $city, $date, $existingId);

        $pdo->prepare(
            "INSERT INTO market_prices
                (fuel_type, city, region, price_per_liter, currency, price_date, source, source_ref,
                 is_demo, recorded_by, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                price_per_liter = VALUES(price_per_liter),
                region          = VALUES(region),
                currency        = VALUES(currency),
                source_ref      = VALUES(source_ref),
                is_demo         = VALUES(is_demo),
                recorded_by     = VALUES(recorded_by),
                notes           = VALUES(notes),
                updated_at      = NOW()"
        )->execute([
            $fuel, $city,
            ($region !== '' ? $region : null),
            $priceStr, $cur, $date, $src,
            ($ref !== '' ? $ref : null),
            $demo, $by,
            ($notes !== '' ? $notes : null),
        ]);

        $id = $existingId > 0 ? $existingId : (int)$pdo->lastInsertId();

        $change = null; $pct = null; $alert = null;
        if ($prev) {
            $pv     = (float)$prev['price_per_liter'];
            $change = round($price - $pv, 2);
            if ($pv != 0.0) {
                $pct = round(($change / $pv) * 100, 2);
            }
            $rule = market_alert_settings($pdo);
            if ($rule['enabled'] && $pct !== null && abs($pct) >= $rule['pct']) {
                $dir   = $pct > 0 ? 'up' : 'down';
                $fires = ($rule['direction'] === 'both' || $rule['direction'] === $dir);
                if ($fires) {
                    $msg = sprintf('%s in %s %s %.2f%% to $%s (previous $%s on %s).',
                        $fuel, $city, $pct > 0 ? 'rose' : 'fell', abs($pct),
                        money($price), money($pv), (string)$prev['price_date']);
                    $pdo->prepare(
                        "INSERT INTO market_alerts
                            (fuel_type, city, old_price, new_price, change_pct, direction, threshold_pct, message, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                    )->execute([$fuel, $city, $pv, $priceStr, $pct, $dir, $rule['pct'], mb_substr($msg, 0, 255)]);
                    $alert = ['id' => (int)$pdo->lastInsertId(), 'change_pct' => $pct, 'direction' => $dir, 'message' => $msg];
                }
            }
        }

        return [
            'ok'         => true,
            'action'     => $existingId > 0 ? 'updated' : 'inserted',
            'id'         => $id,
            'change'     => $change,
            'change_pct' => $pct,
            'previous'   => $prev ? (float)$prev['price_per_liter'] : null,
            'alert'      => $alert,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Populate a clearly-labelled DEMO dataset. Never real market prices.
 * Skips when any non-demo observation already exists.
 */
function market_seed_demo(PDO $pdo, int $days = 400, bool $force = false): int
{
    try {
        if (!$force && (int)$pdo->query("SELECT COUNT(*) FROM market_prices WHERE is_demo = 0")->fetchColumn() > 0) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $cities = ['Beirut', 'Tripoli', 'Sidon'];
    $bases  = ['Petrol' => 1.78, 'Diesel' => 1.61, 'LPG' => 0.94];
    $source = 'Demo dataset (sample)';

    $ins = $pdo->prepare(
        "INSERT INTO market_prices
            (fuel_type, city, price_per_liter, currency, price_date, source, is_demo, recorded_by, notes)
         VALUES (?, ?, ?, 'USD', ?, ?, 1, 'System', 'Demo sample — not a live market price.')
         ON DUPLICATE KEY UPDATE price_per_liter = VALUES(price_per_liter), is_demo = 1"
    );

    $today = strtotime('today');
    $count = 0;
    $pdo->beginTransaction();
    try {
        $fi = 0;
        foreach ($bases as $fuel => $base) {
            $ci = 0;
            foreach ($cities as $city) {
                for ($d = $days; $d >= 0; $d--) {
                    $date = date('Y-m-d', $today - $d * 86400);
                    $seed = $ci * 131 + $fi * 17 + $d * 7;
                    $wave = sin($seed / 23.0) * 0.055 + sin($seed / 7.0) * 0.025;
                    $rand = abs(fmod(sin($seed * 12.9898) * 43758.5453, 1.0));
                    $noise = ($rand - 0.5) * 0.012;
                    $price = max(0.05, round($base * (1 + $wave) + $noise, 2));
                    $ins->execute([$fuel, $city, number_format($price, 2, '.', ''), $date, $source]);
                    $count++;
                }
                $ci++;
            }
            $fi++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 0;
    }
    return $count;
}

/* ===============================================================
 *  Audit trail / activity logging
 * ============================================================= */

/**
 * Append an entry to the activity log. Best-effort: never breaks the app.
 */
function log_activity(PDO $pdo, string $action, string $description,
                      ?string $entityType = null, ?int $entityId = null): void
{
    try {
        $pdo->prepare(
            "INSERT INTO activity_logs
                (user_id, user_name, role, action, description, entity_type, entity_id, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $_SESSION['user_id'] ?? null,
            mb_substr((string)($_SESSION['full_name'] ?? 'Guest'), 0, 100),
            current_role(),
            mb_substr($action, 0, 50),
            $description,
            $entityType,
            $entityId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        /* logging must never interrupt the request */
    }
}

/** Presentation metadata (badge classes, icon, accent, label) for each activity action. */
function activity_meta(string $action): array
{
    $map = [
        'login'               => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-right-to-bracket',    'tone' => 'text-emerald-400', 'label' => 'Login'],
        'logout'              => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-right-from-bracket',  'tone' => 'text-slate-400',   'label' => 'Logout'],
        'login_failed'        => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-triangle-exclamation', 'tone' => 'text-red-400',     'label' => 'Failed Login'],
        'sale'                => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-cart-shopping',       'tone' => 'text-emerald-400', 'label' => 'Sale'],
        'sale_void'           => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-receipt',             'tone' => 'text-red-400',     'label' => 'Voided Sale'],
        'tank_refill'         => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-truck-ramp-box',      'tone' => 'text-cyan-400',    'label' => 'Tank Refill'],
        'tank_refill_blocked' => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-ban',                 'tone' => 'text-red-400',     'label' => 'Blocked Refill'],
        'price_change'        => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-tag',                 'tone' => 'text-amber-400',   'label' => 'Price Change'],
        'market_sync'         => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-cloud-arrow-down',    'tone' => 'text-cyan-400',    'label' => 'Market Sync'],
        'shift_open'          => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-play',                'tone' => 'text-emerald-400', 'label' => 'Shift Opened'],
        'shift_close'         => ['cls' => 'bg-purple-500/10 text-purple-400 border-purple-500/20',    'icon' => 'fa-flag-checkered',      'tone' => 'text-purple-400',  'label' => 'Shift Closed'],
        'employee_add'        => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-user-plus',           'tone' => 'text-cyan-400',    'label' => 'Employee Added'],
        'employee_edit'       => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-user-pen',            'tone' => 'text-amber-400',   'label' => 'Employee Edited'],
        'employee_toggle'     => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-toggle-on',           'tone' => 'text-slate-300',   'label' => 'Employee Status'],
        'employee_delete'     => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-user-minus',          'tone' => 'text-red-400',     'label' => 'Employee Deleted'],
        'supplier_add'        => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-truck-field',         'tone' => 'text-cyan-400',    'label' => 'Supplier Added'],
        'supplier_edit'       => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-truck-field',         'tone' => 'text-amber-400',   'label' => 'Supplier Edited'],
        'supplier_toggle'     => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-toggle-on',           'tone' => 'text-slate-300',   'label' => 'Supplier Status'],
        'supplier_delete'     => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-truck-field',         'tone' => 'text-red-400',     'label' => 'Supplier Deleted'],
        'purchase_add'        => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-file-invoice-dollar', 'tone' => 'text-cyan-400',    'label' => 'Purchase Recorded'],
        'purchase_confirm'    => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-truck-ramp-box',      'tone' => 'text-emerald-400', 'label' => 'Delivery Confirmed'],
        'purchase_cancel'     => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-ban',                 'tone' => 'text-red-400',     'label' => 'Purchase Cancelled'],
        'supplier_payment'    => ['cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'icon' => 'fa-money-bill-transfer', 'tone' => 'text-emerald-400', 'label' => 'Supplier Payment'],
        'market_add'          => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-arrow-trend-up',      'tone' => 'text-cyan-400',    'label' => 'Market Price Added'],
        'market_import'       => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-file-csv',            'tone' => 'text-cyan-400',    'label' => 'Market Import'],
        'market_alert'        => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-bell',                'tone' => 'text-amber-400',   'label' => 'Market Alert'],
        'market_alert_rule'   => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-sliders',             'tone' => 'text-slate-300',   'label' => 'Alert Settings'],
        'market_source'       => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-satellite-dish',      'tone' => 'text-cyan-400',    'label' => 'Market Source'],
        'market_demo'         => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-flask',               'tone' => 'text-slate-300',   'label' => 'Demo Data'],
        'user_add'            => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-user-shield',         'tone' => 'text-cyan-400',    'label' => 'User Created'],
        'user_edit'           => ['cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',       'icon' => 'fa-user-pen',            'tone' => 'text-amber-400',   'label' => 'User Updated'],
        'user_role'           => ['cls' => 'bg-purple-500/10 text-purple-400 border-purple-500/20',    'icon' => 'fa-user-shield',         'tone' => 'text-purple-400',  'label' => 'Role Changed'],
        'user_toggle'         => ['cls' => 'bg-slate-700 text-slate-300 border-slate-600',             'icon' => 'fa-toggle-on',           'tone' => 'text-slate-300',   'label' => 'User Status'],
        'user_delete'         => ['cls' => 'bg-red-500/10 text-red-400 border-red-500/20',             'icon' => 'fa-user-minus',          'tone' => 'text-red-400',     'label' => 'User Deleted'],
        'price_history_export' => ['cls' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',          'icon' => 'fa-file-csv',            'tone' => 'text-cyan-400',    'label' => 'Price History Export'],
    ];
    return $map[$action] ?? [
        'cls'   => 'bg-slate-700 text-slate-300 border-slate-600',
        'icon'  => 'fa-circle-info',
        'tone'  => 'text-slate-400',
        'label' => ucwords(str_replace('_', ' ', $action)),
    ];
}

/* ===============================================================
 *  Auto schema migration
 *  Safe & idempotent — runs once per request, only if the
 *  schema version marker differs from the target.
 * ============================================================= */

function ensure_schema(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    /* Always make sure the core support tables exist. */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key`   VARCHAR(64) NOT NULL,
            `setting_value` TEXT        NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `price_history` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fuel_type`    VARCHAR(20)   NOT NULL,
            `product_id`   INT UNSIGNED  NULL,
            `old_price`    DECIMAL(10,2) NOT NULL,
            `new_price`    DECIMAL(10,2) NOT NULL,
            `difference`   DECIMAL(10,2) NOT NULL,
            `changed_by`   VARCHAR(100)  NOT NULL DEFAULT 'System Admin',
            `changed_by_id` INT UNSIGNED NULL,
            `source`       VARCHAR(20)   NOT NULL DEFAULT 'Manual',
            `sync_run_id`  INT UNSIGNED  NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_fuel_type` (`fuel_type`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_ph_product` (`product_id`),
            KEY `idx_ph_user` (`changed_by_id`),
            KEY `idx_ph_sync_run` (`sync_run_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `shifts` (
            `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `user_id`        INT UNSIGNED  NULL,
            `user_name`      VARCHAR(100)  NOT NULL DEFAULT 'Operator',
            `role`           VARCHAR(20)   NOT NULL DEFAULT 'operator',
            `opening_cash`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cash_sales`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `card_sales`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `credit_sales`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_sales`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tx_count`       INT UNSIGNED  NOT NULL DEFAULT 0,
            `expected_cash`  DECIMAL(10,2) NULL,
            `closing_cash`   DECIMAL(10,2) NULL,
            `variance`       DECIMAL(10,2) NULL,
            `status`         VARCHAR(10)   NOT NULL DEFAULT 'open',
            `notes`          VARCHAR(255)  NULL,
            `opened_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `closed_at`      TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_shift_user` (`user_id`),
            KEY `idx_shift_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `user_id`      INT UNSIGNED  NULL,
            `user_name`    VARCHAR(100)  NOT NULL DEFAULT 'Guest',
            `role`         VARCHAR(20)   NOT NULL DEFAULT 'operator',
            `action`       VARCHAR(50)   NOT NULL,
            `description`  TEXT          NULL,
            `entity_type`  VARCHAR(40)   NULL,
            `entity_id`    INT UNSIGNED  NULL,
            `ip_address`   VARCHAR(45)   NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_log_created` (`created_at`),
            KEY `idx_log_action` (`action`),
            KEY `idx_log_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* --- Suppliers management --- */
        $pdo->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_name`   VARCHAR(120) NOT NULL,
            `contact_person` VARCHAR(100) NULL,
            `phone`          VARCHAR(30)  NULL,
            `email`          VARCHAR(150) NULL,
            `address`        VARCHAR(255) NULL,
            `notes`          TEXT         NULL,
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_supplier_name` (`company_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `supplier_purchases` (
            `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `purchase_no`       VARCHAR(30)   NULL,
            `supplier_id`       INT UNSIGNED  NOT NULL,
            `fuel_inventory_id` INT UNSIGNED  NULL,
            `fuel_type`         VARCHAR(20)   NOT NULL,
            `liters`            DECIMAL(12,2) NOT NULL,
            `cost_per_liter`    DECIMAL(10,2) NOT NULL,
            `total_cost`        DECIMAL(10,2) NOT NULL,
            `invoice_no`        VARCHAR(60)   NULL,
            `delivery_date`     DATE          NULL,
            `payment_status`    VARCHAR(10)   NOT NULL DEFAULT 'Unpaid',
            `amount_paid`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tanker_no`         VARCHAR(40)   NULL,
            `driver_name`       VARCHAR(100)  NULL,
            `driver_phone`      VARCHAR(30)   NULL,
            `delivery_ref`      VARCHAR(60)   NULL,
            `status`            VARCHAR(12)   NOT NULL DEFAULT 'pending',
            `stock_applied`     TINYINT(1)    NOT NULL DEFAULT 0,
            `verified_by`       VARCHAR(100)  NULL,
            `verified_at`       TIMESTAMP     NULL DEFAULT NULL,
            `recorded_by`       VARCHAR(100)  NULL,
            `notes`             TEXT          NULL,
            `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_purchase_no` (`purchase_no`),
            KEY `idx_purchase_supplier` (`supplier_id`),
            KEY `idx_purchase_fuel` (`fuel_inventory_id`),
            KEY `idx_purchase_status` (`status`),
            KEY `idx_purchase_delivery` (`delivery_date`),
            CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`)
                REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT `fk_purchase_fuel` FOREIGN KEY (`fuel_inventory_id`)
                REFERENCES `fuel_inventory` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `supplier_payments` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `payment_no`   VARCHAR(30)   NULL,
            `supplier_id`  INT UNSIGNED  NOT NULL,
            `purchase_id`  INT UNSIGNED  NULL,
            `amount`       DECIMAL(10,2) NOT NULL,
            `method`       VARCHAR(20)   NOT NULL DEFAULT 'Cash',
            `reference`    VARCHAR(80)   NULL,
            `payment_date` DATE          NULL,
            `recorded_by`  VARCHAR(100)  NULL,
            `notes`        VARCHAR(255)  NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_payment_no` (`payment_no`),
            KEY `idx_payment_supplier` (`supplier_id`),
            KEY `idx_payment_purchase` (`purchase_id`),
            KEY `idx_payment_date` (`payment_date`),
            CONSTRAINT `fk_payment_supplier` FOREIGN KEY (`supplier_id`)
                REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT `fk_payment_purchase` FOREIGN KEY (`purchase_id`)
                REFERENCES `supplier_purchases` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* --- Market price intelligence --- */
        $pdo->exec("CREATE TABLE IF NOT EXISTS `market_prices` (
            `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fuel_type`       VARCHAR(20)   NOT NULL,
            `city`            VARCHAR(80)   NOT NULL,
            `region`          VARCHAR(80)   NULL,
            `price_per_liter` DECIMAL(10,2) NOT NULL,
            `currency`        VARCHAR(8)    NOT NULL DEFAULT 'USD',
            `price_date`      DATE          NOT NULL,
            `source`          VARCHAR(80)   NOT NULL DEFAULT 'Manual',
            `source_ref`      VARCHAR(255)  NULL,
            `is_demo`         TINYINT(1)    NOT NULL DEFAULT 0,
            `recorded_by`     VARCHAR(100)  NULL,
            `notes`           VARCHAR(255)  NULL,
            `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_market_obs` (`fuel_type`, `city`, `price_date`, `source`),
            KEY `idx_market_fuel` (`fuel_type`),
            KEY `idx_market_city` (`city`),
            KEY `idx_market_date` (`price_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `market_alerts` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fuel_type`     VARCHAR(20)   NOT NULL,
            `city`          VARCHAR(80)   NOT NULL,
            `old_price`     DECIMAL(10,2) NOT NULL,
            `new_price`     DECIMAL(10,2) NOT NULL,
            `change_pct`    DECIMAL(8,2)  NOT NULL,
            `direction`     VARCHAR(10)   NOT NULL DEFAULT 'up',
            `threshold_pct` DECIMAL(6,2)  NOT NULL,
            `message`       VARCHAR(255)  NULL,
            `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_alert_created` (`created_at`),
            KEY `idx_alert_fuel` (`fuel_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `market_sources` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`         VARCHAR(80)   NOT NULL,
            `kind`         VARCHAR(10)   NOT NULL DEFAULT 'api',
            `endpoint`     VARCHAR(255)  NULL,
            `city`         VARCHAR(80)   NULL,
            `json_path`    VARCHAR(120)  NULL,
            `fuel_map`     TEXT          NULL,
            `currency`     VARCHAR(8)    NOT NULL DEFAULT 'USD',
            `doc_url`      VARCHAR(255)  NULL,
            `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
            `last_sync_at` TIMESTAMP     NULL DEFAULT NULL,
            `last_status`  VARCHAR(255)  NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_market_source_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* One row per market-rate sync run — the summary the UI shows
           (total changes, increases, decreases, unchanged, skipped). */
        $pdo->exec("CREATE TABLE IF NOT EXISTS `market_sync_runs` (
            `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `feed`           VARCHAR(255)  NULL,
            `status`         VARCHAR(12)   NOT NULL DEFAULT 'ok',
            `total_changes`  INT UNSIGNED  NOT NULL DEFAULT 0,
            `increases`      INT UNSIGNED  NOT NULL DEFAULT 0,
            `decreases`      INT UNSIGNED  NOT NULL DEFAULT 0,
            `unchanged`      INT UNSIGNED  NOT NULL DEFAULT 0,
            `skipped`        INT UNSIGNED  NOT NULL DEFAULT 0,
            `detail`         VARCHAR(500)  NULL,
            `error_message`  VARCHAR(255)  NULL,
            `triggered_by`   VARCHAR(100)  NULL,
            `triggered_by_id` INT UNSIGNED NULL,
            `started_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at`    TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY idx_sync_run_started (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // best effort
    }

    $TARGET_VERSION = '2026.10.18.3';

    try {
        $versionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'");
        $versionStmt->execute();
        $currentVersion = $versionStmt->fetchColumn();

        if ($currentVersion !== $TARGET_VERSION) {
            $hasCol = function (string $table, string $col) use ($pdo): bool {
                $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $q->execute([$table, $col]);
                return (bool)$q->fetchColumn();
            };
            $hasIdx = function (string $table, string $idx) use ($pdo): bool {
                $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
                $q->execute([$table, $idx]);
                return (bool)$q->fetchColumn();
            };
            $hasTable = function (string $table) use ($pdo): bool {
                $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $q->execute([$table]);
                return (bool)$q->fetchColumn();
            };

            /* --- price_history: changed_at -> created_at --- */
            if ($hasCol('price_history', 'changed_at') && !$hasCol('price_history', 'created_at')) {
                $pdo->exec("ALTER TABLE price_history CHANGE COLUMN changed_at created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
            if (!$hasCol('price_history', 'created_at')) {
                $pdo->exec("ALTER TABLE price_history ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
            if (!$hasCol('price_history', 'source')) {
                $pdo->exec("ALTER TABLE price_history ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'Manual' AFTER changed_by");
            }

            /* --- price_history: add product + user references (additive only) --- */
            if ($hasTable('price_history')) {
                if (!$hasCol('price_history', 'product_id')) {
                    $pdo->exec("ALTER TABLE price_history ADD COLUMN product_id INT UNSIGNED NULL AFTER fuel_type");
                }
                if (!$hasCol('price_history', 'changed_by_id')) {
                    $pdo->exec("ALTER TABLE price_history ADD COLUMN changed_by_id INT UNSIGNED NULL AFTER changed_by");
                }
                if (!$hasIdx('price_history', 'idx_ph_product')) {
                    $pdo->exec("ALTER TABLE price_history ADD KEY idx_ph_product (product_id)");
                }
                if (!$hasIdx('price_history', 'idx_ph_user')) {
                    $pdo->exec("ALTER TABLE price_history ADD KEY idx_ph_user (changed_by_id)");
                }
                if (!$hasCol('price_history', 'sync_run_id')) {
                    $pdo->exec("ALTER TABLE price_history ADD COLUMN sync_run_id INT UNSIGNED NULL AFTER source");
                    $pdo->exec("ALTER TABLE price_history ADD KEY idx_ph_sync_run (sync_run_id)");
                }
                /* Backfill existing rows from the live fuel + user tables. */
                if ($hasTable('fuel_inventory')) {
                    $pdo->exec("UPDATE price_history ph
                                JOIN fuel_inventory fi ON fi.fuel_type = ph.fuel_type
                                SET ph.product_id = fi.id
                                WHERE ph.product_id IS NULL");
                }
                if ($hasTable('users')) {
                    $pdo->exec("UPDATE price_history ph
                                JOIN users u ON u.full_name = ph.changed_by
                                SET ph.changed_by_id = u.id
                                WHERE ph.changed_by_id IS NULL");
                }
            }

            /* --- Money columns -> DECIMAL(10,2) --- */
            if ($hasCol('fuel_inventory', 'price_per_liter')) {
                $pdo->exec("ALTER TABLE fuel_inventory MODIFY price_per_liter DECIMAL(10,2) NOT NULL");
            }
            if ($hasCol('price_history', 'old_price')) {
                $pdo->exec("ALTER TABLE price_history
                            MODIFY old_price  DECIMAL(10,2) NOT NULL,
                            MODIFY new_price  DECIMAL(10,2) NOT NULL,
                            MODIFY difference DECIMAL(10,2) NOT NULL");
            }
            if ($hasCol('sales', 'total_amount')) {
                $pdo->exec("ALTER TABLE sales MODIFY total_amount DECIMAL(10,2) NOT NULL");
            }

            /* --- fuel_inventory.status --- */
            if ($hasTable('fuel_inventory') && !$hasCol('fuel_inventory', 'status')) {
                $pdo->exec("ALTER TABLE fuel_inventory ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'OK' AFTER price_per_liter");
            }

            /* --- sales extra columns --- */
            if ($hasTable('sales')) {
                if (!$hasCol('sales', 'invoice_no'))     $pdo->exec("ALTER TABLE sales ADD COLUMN invoice_no VARCHAR(30) NULL AFTER id");
                if (!$hasCol('sales', 'price_per_liter')) $pdo->exec("ALTER TABLE sales ADD COLUMN price_per_liter DECIMAL(10,2) NULL AFTER liters_sold");
                if (!$hasCol('sales', 'payment_method'))  $pdo->exec("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'Cash' AFTER total_amount");
                if (!$hasCol('sales', 'customer_name'))   $pdo->exec("ALTER TABLE sales ADD COLUMN customer_name VARCHAR(100) NULL AFTER payment_method");
                if (!$hasCol('sales', 'recorded_by'))     $pdo->exec("ALTER TABLE sales ADD COLUMN recorded_by VARCHAR(100) NULL AFTER customer_name");

                /* Backfill the new columns for existing rows */
                $pdo->exec("UPDATE sales SET price_per_liter = ROUND(total_amount / NULLIF(liters_sold, 0), 2)
                            WHERE price_per_liter IS NULL AND liters_sold > 0");
                $pdo->exec("UPDATE sales SET invoice_no = CONCAT('INV-', YEAR(sold_at), '-', LPAD(id,5,'0'))
                            WHERE invoice_no IS NULL OR invoice_no = ''");
                if (!$hasIdx('sales', 'uq_invoice_no')) {
                    $pdo->exec("ALTER TABLE sales ADD UNIQUE KEY uq_invoice_no (invoice_no)");
                }
            }

            /* --- sales: link each sale to the cashier's shift --- */
            if ($hasTable('sales') && !$hasCol('sales', 'shift_id')) {
                $pdo->exec("ALTER TABLE sales ADD COLUMN shift_id INT UNSIGNED NULL AFTER fuel_inventory_id");
                $pdo->exec("ALTER TABLE sales ADD KEY idx_sales_shift (shift_id)");
                try {
                    $pdo->exec("ALTER TABLE sales ADD CONSTRAINT fk_sales_shift FOREIGN KEY (shift_id)
                                REFERENCES shifts (id) ON UPDATE CASCADE ON DELETE SET NULL");
                } catch (Throwable $e) {
                    /* shifts table may be absent on partial setups */
                }
            }

            /* --- Recompute every tank's status --- */
            if ($hasTable('fuel_inventory') && $hasCol('fuel_inventory', 'status')) {
                $pdo->exec("UPDATE fuel_inventory SET status = CASE
                    WHEN current_stock_liters >= tank_capacity_liters THEN 'FULL'
                    WHEN tank_capacity_liters > 0 AND (current_stock_liters / tank_capacity_liters) <= 0.15 THEN 'LOW'
                    ELSE 'OK' END");
            }

            /* --- Default settings --- */
            if ($hasTable('settings')) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('market_feed_url', ?)
                               ON DUPLICATE KEY UPDATE setting_key = setting_key")
                    ->execute(['market_feed.json']);
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('station_name', ?)
                               ON DUPLICATE KEY UPDATE setting_key = setting_key")
                    ->execute(['BlueWave Fuel Station']);
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('station_address', ?)
                               ON DUPLICATE KEY UPDATE setting_key = setting_key")
                    ->execute(['123 Coastal Highway, Beirut']);
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('station_phone', ?)
                               ON DUPLICATE KEY UPDATE setting_key = setting_key")
                    ->execute(['+961 1 234 567']);
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('receipt_footer', ?)
                               ON DUPLICATE KEY UPDATE setting_key = setting_key")
                    ->execute(['Thank you for fueling with us!']);

                /* Market price module defaults (insert-if-absent). */
                foreach ([
                    'market_alert_enabled'   => '1',
                    'market_alert_pct'       => '3.00',
                    'market_alert_direction' => 'both',
                    'market_stale_days'      => '3',
                    'market_default_city'    => 'Beirut',
                ] as $mk => $mv) {
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                   ON DUPLICATE KEY UPDATE setting_key = setting_key")
                        ->execute([$mk, $mv]);
                }
            }

            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                           ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$TARGET_VERSION, $TARGET_VERSION]);
        }
    } catch (Throwable $e) {
        /* Migration is best-effort — never break the app because of it. */
    }

    /*
     * One-time, clearly-labelled demo dataset for the Market Prices module.
     * Runs only while the table is empty and the state marker is unset, so a
     * fresh install is demonstrable — it never overwrites real prices and the
     * rows are flagged `is_demo = 1` and badged DEMO in the UI.
     */
    try {
        if (get_setting($pdo, 'market_demo_state', null) === null) {
            $anyRows = (int)$pdo->query("SELECT COUNT(*) FROM market_prices")->fetchColumn();
            if ($anyRows === 0) {
                market_seed_demo($pdo);
            }
            set_setting($pdo, 'market_demo_state', 'seeded');
        }
    } catch (Throwable $e) {
        /* demo seeding is best-effort */
    }
}

ensure_schema($pdo);

/* ===============================================================
 *  Authentication guard
 * ============================================================= */

$thisPage    = basename($_SERVER['PHP_SELF']);
$publicPages = ['login.php', 'logout.php'];

if (!in_array($thisPage, $publicPages, true)) {

    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }

    // Refresh user data from DB on every request
    $stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser) {
        session_destroy();
        redirect('login.php');
    }

    $_SESSION['full_name'] = $currentUser['full_name'];
    $_SESSION['email']     = $currentUser['email'];
    $_SESSION['role']      = $currentUser['role'];
}
