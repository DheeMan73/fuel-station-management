<?php
/**
 * Market Sync — System Admin only.
 *
 * Pulls fuel prices from an external JSON feed and applies any
 * differences to the active prices, logging each change in
 * price_history with source = 'Market'.
 *
 * Accepted feed shapes (either works):
 *   { "rates": { "Petrol": 1.95, "Diesel": 1.72, "LPG": 1.05 } }
 *   { "Petrol": 1.95, "Diesel": 1.72, "LPG": 1.05 }
 *
 * Usage:
 *   POST action=sync       -> sync + redirect back to last_price.php
 *   POST action=save_url   -> persist the feed URL
 *   GET  ?format=json      -> JSON API response (read-only status)
 */
require_once __DIR__ . '/config.php';

require_admin();

/* ---------------------------------------------------------------
 *  Helpers
 * ------------------------------------------------------------- */

/**
 * Fetch raw content from a URL (http/https) or a local path.
 */
function market_fetch(string $src): string
{
    $src = trim($src);
    if ($src === '') {
        throw new RuntimeException('Market feed location is empty.');
    }

    if (preg_match('#^https?://#i', $src)) {
        if (function_exists('curl_init')) {
            $ch = curl_init($src);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => 'FuelStationManagement/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $err !== '') {
                throw new RuntimeException('Feed request failed: ' . ($err ?: 'unknown error'));
            }
            if ($code >= 400) {
                throw new RuntimeException('Feed returned HTTP ' . $code);
            }
            return (string)$body;
        }

        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('Remote feeds need cURL or allow_url_fopen enabled.');
        }
        $body = @file_get_contents($src);
        if ($body === false) {
            throw new RuntimeException('Could not read remote feed.');
        }
        return (string)$body;
    }

    /* Local path relative to the app root */
    $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $src), DIRECTORY_SEPARATOR);
    $real = realpath($path);
    $base = realpath(__DIR__);

    if ($real === false || !is_file($real)) {
        throw new RuntimeException('Local feed file not found: ' . $src);
    }
    if ($base !== false && strpos($real, $base) !== 0) {
        throw new RuntimeException('Feed path must stay inside the app directory.');
    }
    return (string)file_get_contents($real);
}

/**
 * Parse a feed body into a [fuel_type => price] map.
 */
function market_parse(string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Feed is not valid JSON.');
    }

    $rates = $data['rates'] ?? $data['data'] ?? $data;
    if (!is_array($rates)) {
        throw new RuntimeException('Feed has no rate table.');
    }

    $out = [];
    foreach ($rates as $fuel => $value) {
        if (!is_string($fuel) && !is_numeric($fuel)) {
            continue;
        }
        $fuel = trim((string)$fuel);
        if ($fuel === '' || !is_numeric($value)) {
            continue;
        }
        $price = (float)$value;
        if (!is_finite($price) || $price <= 0) {
            continue;
        }
        $out[$fuel] = round($price, 2);
    }

    if (empty($out)) {
        throw new RuntimeException('Feed contained no usable rates.');
    }
    return $out;
}

/**
 * Apply a rate map to fuel_inventory + log to price_history.
 * Returns a human-readable summary.
 */
function market_apply(PDO $pdo, array $rates, string $changedBy, ?int $changedById = null, ?int $syncRunId = null): array
{
    $report = ['updated' => [], 'unchanged' => [], 'skipped' => [], 'increases' => 0, 'decreases' => 0, 'changes' => []];

    foreach ($rates as $fuelType => $newPrice) {
        $stmt = $pdo->prepare("SELECT id, price_per_liter FROM fuel_inventory WHERE fuel_type = ?");
        $stmt->execute([$fuelType]);
        $row = $stmt->fetch();

        if (!$row) {
            $report['skipped'][] = $fuelType;
            continue;
        }

        $old = (float)$row['price_per_liter'];
        $new = (float)$newPrice;

        if (abs($old - $new) < 0.005) {
            $report['unchanged'][] = $fuelType;
            continue;
        }

        $pdo->prepare("UPDATE fuel_inventory SET price_per_liter = ?, last_updated = NOW() WHERE id = ?")
            ->execute([$new, $row['id']]);

        $diff = round($new - $old, 2);
        ($diff > 0) ? $report['increases']++ : $report['decreases']++;

        $pdo->prepare(
            "INSERT INTO price_history (fuel_type, product_id, old_price, new_price, difference, changed_by, changed_by_id, source, sync_run_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$fuelType, (int)$row['id'], $old, $new, $diff, $changedBy, $changedById, MARKET_SYNC_SOURCE, $syncRunId]);

        $report['updated'][]   = sprintf('%s $%s -> $%s', $fuelType, number_format($old, 2), number_format($new, 2));
        $report['changes'][]   = ['fuel' => $fuelType, 'old' => $old, 'new' => $new, 'diff' => $diff];
    }

    return $report;
}

/**
 * Record one sync run in market_sync_runs so the UI can show a
 * per-run summary (total changes / increases / decreases).
 * Returns the run id, or null if the run table is unavailable.
 */
function market_sync_run_start(PDO $pdo, string $feed): ?int
{
    try {
        $pdo->prepare("INSERT INTO market_sync_runs (feed, triggered_by, triggered_by_id, started_at) VALUES (?, ?, ?, NOW())")
            ->execute([$feed, $_SESSION['full_name'] ?? null, (int)($_SESSION['user_id'] ?? 0) ?: null]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function market_sync_run_finish(PDO $pdo, ?int $runId, array $report): void
{
    if (!$runId) {
        return;
    }
    try {
        $detail = implode('; ', array_map(
            static fn (array $c): string => sprintf('%s %s$%s', $c['fuel'], $c['diff'] > 0 ? '+' : '-', number_format(abs($c['diff']), 2)),
            $report['changes'] ?? []
        ));
        $pdo->prepare("UPDATE market_sync_runs
                       SET status = 'ok', total_changes = ?, increases = ?, decreases = ?, unchanged = ?, skipped = ?, detail = ?, finished_at = NOW()
                       WHERE id = ?")
            ->execute([
                count($report['updated'] ?? []),
                (int)($report['increases'] ?? 0),
                (int)($report['decreases'] ?? 0),
                count($report['unchanged'] ?? []),
                count($report['skipped'] ?? []),
                $detail !== '' ? mb_substr($detail, 0, 500) : null,
                $runId,
            ]);
    } catch (Throwable $e) {
        /* summary bookkeeping must never break the sync itself */
    }
}

function market_sync_run_fail(PDO $pdo, ?int $runId, string $error): void
{
    if (!$runId) {
        return;
    }
    try {
        $pdo->prepare("UPDATE market_sync_runs SET status = 'error', error_message = ?, finished_at = NOW() WHERE id = ?")
            ->execute([mb_substr($error, 0, 255), $runId]);
    } catch (Throwable $e) {
        /* ignore */
    }
}

/* ==========================================================
 *  Actions
 * ========================================================= */
$wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_url') {
        $url = trim((string)($_POST['market_feed_url'] ?? ''));
        if ($url === '') {
            set_flash('error', 'Feed URL cannot be empty.');
        } else {
            set_setting($pdo, 'market_feed_url', $url);
            log_activity($pdo, 'market_sync', 'Market feed location updated to: ' . $url, 'setting', null);
            set_flash('success', 'Market feed saved: ' . $url);
        }
        redirect('last_price.php');
    }

    if ($action === 'sync') {
        $url = (string)get_setting($pdo, 'market_feed_url', 'market_feed.json');
        $runId = market_sync_run_start($pdo, $url);
        try {
            $rates = market_parse(market_fetch($url));

            $pdo->beginTransaction();
            $report = market_apply(
                $pdo,
                $rates,
                $_SESSION['full_name'] ?? 'System Admin',
                (int)($_SESSION['user_id'] ?? 0) ?: null,
                $runId
            );
            $pdo->commit();
            market_sync_run_finish($pdo, $runId, $report);

            $inc = (int)($report['increases'] ?? 0);
            $dec = (int)($report['decreases'] ?? 0);
            $n   = count($report['updated']);

            if ($n > 0) {
                log_activity($pdo, 'market_sync', sprintf(
                    'Market sync applied %d price change(s): %d increase(s), %d decrease(s) — %s.',
                    $n, $inc, $dec, implode('; ', $report['updated'])
                ));
                set_flash('success', sprintf(
                    'Market sync: %d change(s) — %d increase, %d decrease%s.',
                    $n, $inc, $dec,
                    count($report['skipped']) ? (' ' . count($report['skipped']) . ' fuel(s) skipped') : ''
                ));
            } else {
                log_activity($pdo, 'market_sync', 'Market sync ran from "' . $url . '" — no price changes needed.');
                set_flash('success', 'Market sync complete — no price changes needed.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            market_sync_run_fail($pdo, $runId, $e->getMessage());
            set_flash('error', 'Market sync failed: ' . $e->getMessage());
        }
        redirect('last_price.php');
    }

    set_flash('error', 'Unknown market sync action.');
    redirect('last_price.php');
}

/* ==========================================================
 *  GET — JSON status / dry-run
 * ========================================================= */
$feedUrl = get_setting($pdo, 'market_feed_url', 'market_feed.json');
$payload = [
    'ok'       => true,
    'feed'     => $feedUrl,
    'prices'   => [],
];

foreach ($pdo->query("SELECT fuel_type, price_per_liter FROM fuel_inventory ORDER BY fuel_type") as $row) {
    $payload['prices'][$row['fuel_type']] = (float)$row['price_per_liter'];
}

/* Optional preview: ?preview=1 pulls the feed without writing. */
if (isset($_GET['preview'])) {
    try {
        $rates = market_parse(market_fetch((string)$feedUrl));
        $payload['feed_rates'] = $rates;
        $payload['pending'] = [];
        foreach ($rates as $fuel => $price) {
            if (isset($payload['prices'][$fuel]) && abs($payload['prices'][$fuel] - $price) >= 0.005) {
                $payload['pending'][$fuel] = ['from' => $payload['prices'][$fuel], 'to' => $price];
            }
        }
    } catch (Throwable $e) {
        $payload['ok'] = false;
        $payload['error'] = $e->getMessage();
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
