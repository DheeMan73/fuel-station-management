<?php
/**
 * Market Prices — Import & Sources (System Admin only).
 *
 * Two ways to get market prices in besides manual entry:
 *   1. CSV import  — paste or upload a CSV of observations.
 *   2. Source sync — pull from a documented JSON endpoint. A source is a
 *      generic HTTP-JSON connector: you configure the URL, where the values
 *      live (JSON path) and how the provider's fuel names map to yours.
 *
 * There is no universal free official fuel-price API, so nothing is configured
 * automatically. The bundled `market_feed.json` is a DEMO SAMPLE, not live
 * data — it is never applied here unless you explicitly add it as a source.
 */
require_once __DIR__ . '/config.php';

require_admin();

$pageTitle    = 'Market Prices — Import & Sources';
$pageSubtitle = 'Bulk-load prices from CSV or a documented JSON source';

$fuelTypes = market_fuel_types($pdo);

/* ==========================================================
 *  Source-sync helpers (namespaced to avoid clashing with
 *  market_sync.php, which owns the station-selling-price feed)
 * ========================================================== */

/** Fetch a URL (http/https) or an app-relative local file. */
function market_api_fetch(string $endpoint, int $timeout = 15): string
{
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
        throw new RuntimeException('The source has no endpoint configured.');
    }

    if (preg_match('#^https?://#i', $endpoint)) {
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'FuelStationMarketBot/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $err !== '') {
                throw new RuntimeException('Request failed: ' . ($err ?: 'unknown error'));
            }
            if ($code >= 400) {
                throw new RuntimeException('Source returned HTTP ' . $code);
            }
            return (string)$body;
        }
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('Remote sources need cURL or allow_url_fopen enabled.');
        }
        $body = @file_get_contents($endpoint);
        if ($body === false) {
            throw new RuntimeException('Could not read the remote source.');
        }
        return (string)$body;
    }

    /* Local path relative to the app root (kept inside the app directory). */
    $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $endpoint), DIRECTORY_SEPARATOR);
    $real = realpath($path);
    $base = realpath(__DIR__);
    if ($real === false || !is_file($real)) {
        throw new RuntimeException('Local source file not found: ' . $endpoint);
    }
    if ($base !== false && strpos($real, $base) !== 0) {
        throw new RuntimeException('Source path must stay inside the app directory.');
    }
    return (string)file_get_contents($real);
}

/** Walk a decoded JSON structure by a dotted path ("data.prices.latest"). */
function market_json_path(array $data, string $path)
{
    $path = trim($path);
    if ($path === '') {
        return $data;
    }
    $node = $data;
    foreach (explode('.', $path) as $seg) {
        $seg = trim($seg);
        if ($seg === '') {
            continue;
        }
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
        } else {
            return null;
        }
    }
    return $node;
}

/**
 * Pull rates from one source and record them as market observations.
 * Returns ['ok','fetched','recorded','updated','alerts','note','error'].
 */
function market_source_sync(PDO $pdo, array $src): array
{
    $out = ['ok' => false, 'fetched' => 0, 'recorded' => [], 'updated' => [], 'alerts' => 0, 'note' => '', 'error' => ''];

    try {
        $body = market_api_fetch((string)($src['endpoint'] ?? ''));
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Response is not valid JSON.');
        }

        $node = market_json_path($data, (string)($src['json_path'] ?? ''));
        if ($node === null) {
            throw new RuntimeException('JSON path "' . $src['json_path'] . '" not found in the response.');
        }

        /* key => station fuel type */
        $fuelMap = [];
        if (!empty($src['fuel_map'])) {
            $decoded = json_decode((string)$src['fuel_map'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $fuelMap[strtolower(trim((string)$k))] = trim((string)$v);
                }
            }
        }

        /* Normalise into [stationFuel => price] */
        $rates = [];
        $push = static function ($key, $value) use (&$rates, $fuelMap, $fuelTypes) {
            if (!is_numeric($value)) {
                return;
            }
            $price = (float)$value;
            if (!is_finite($price) || $price <= 0) {
                return;
            }
            $key   = trim((string)$key);
            $fuel  = $fuelMap[strtolower($key)] ?? $key;
            /* When no explicit map is set, keep only known station fuels. */
            if (empty($fuelMap) && $fuelTypes && !in_array($fuel, $fuelTypes, true)) {
                return;
            }
            $rates[$fuel] = round($price, 2);
        };

        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    if (isset($v['price']))      { $push(isset($v['fuel']) ? $v['fuel'] : (isset($v['fuel_type']) ? $v['fuel_type'] : $k), $v['price']); }
                    elseif (isset($v['price_per_liter'])) { $push(isset($v['fuel']) ? $v['fuel'] : (isset($v['fuel_type']) ? $v['fuel_type'] : $k), $v['price_per_liter']); }
                } else {
                    $push($k, $v);
                }
            }
        }

        if (empty($rates)) {
            throw new RuntimeException('No usable fuel prices were found after applying the mapping.');
        }

        $city     = trim((string)($src['city'] ?? ''));
        if ($city === '') {
            $city = (string)get_setting($pdo, 'market_default_city', 'Beirut');
        }
        $currency = trim((string)($src['currency'] ?? 'USD')) ?: 'USD';
        $date     = date('Y-m-d');
        $name     = trim((string)($src['name'] ?? 'Source'));
        $out['fetched'] = count($rates);

        foreach ($rates as $fuel => $price) {
            $res = market_record($pdo, [
                'fuel_type'       => (string)$fuel,
                'city'            => $city,
                'price_per_liter' => $price,
                'currency'        => $currency,
                'price_date'      => $date,
                'source'          => $name,
                'source_ref'      => (string)($src['doc_url'] ?? ''),
                'is_demo'         => 0,
            ]);
            if (empty($res['ok'])) {
                continue;
            }
            if (($res['action'] ?? '') === 'updated') {
                $out['updated'][] = $fuel;
            } else {
                $out['recorded'][] = $fuel;
            }
            if (!empty($res['alert'])) {
                $out['alerts']++;
            }
        }

        $out['ok']   = true;
        $out['note'] = sprintf('%d rate(s) applied to %s.', count($rates), $city);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    try {
        $pdo->prepare("UPDATE market_sources SET last_sync_at = NOW(), last_status = ? WHERE id = ?")
            ->execute([
                mb_substr($out['ok']
                    ? sprintf('OK — %d applied (%d new, %d updated, %d alert(s))', $out['fetched'], count($out['recorded']), count($out['updated']), $out['alerts'])
                    : ('FAILED — ' . $out['error']), 0, 255),
                (int)$src['id'],
            ]);
    } catch (Throwable $e) {
        /* status is best-effort */
    }

    return $out;
}

/* ==========================================================
 *  CSV template download
 * ========================================================= */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="market_prices_template.csv"');
    echo "fuel_type,city,price_per_liter,price_date,region,source,source_ref,notes\n";
    echo 'Petrol,Beirut,1.95,' . date('Y-m-d') . ",Beirut,Ministry bulletin,,\n";
    echo 'Diesel,Beirut,1.72,' . date('Y-m-d') . ",Beirut,Ministry bulletin,,\n";
    exit;
}

/* ==========================================================
 *  Actions
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    /* ---- CSV import ---- */
    if ($action === 'import_csv') {
        $rows = [];

        /* 1) An uploaded file wins ... */
        $uploaded = $_FILES['csv_file'] ?? null;
        if (is_array($uploaded)
            && ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && isset($uploaded['tmp_name']) && is_uploaded_file($uploaded['tmp_name'])) {
            $fh = fopen($uploaded['tmp_name'], 'r');
            if ($fh) {
                while (($r = fgetcsv($fh)) !== false) {
                    $rows[] = $r;
                }
                fclose($fh);
            }
        /* 2) ... otherwise use the pasted textarea. */
        } elseif (trim((string)($_POST['csv_data'] ?? '')) !== '') {
            $text = str_replace("\r\n", "\n", (string)$_POST['csv_data']);
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            foreach (explode("\n", $text) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $rows[] = str_getcsv($line);
            }
        }

        if (empty($rows)) {
            set_flash('error', 'No CSV rows found. Paste the data, choose a file, or download the template.');
            redirect('market_import.php');
        }

        /* Detect an optional header row. */
        $map = null;
        $first = array_map(static fn($v) => strtolower(trim((string)$v)), $rows[0]);
        $known = ['fuel_type', 'fuel', 'city', 'price_per_liter', 'price', 'price_date', 'date', 'region', 'source', 'source_ref', 'notes', 'currency'];
        if (count(array_intersect($first, $known)) >= 2) {
            $map = [];
            foreach ($first as $i => $h) {
                $map[$h] = $i;
            }
            array_shift($rows);
        }

        $pick = static function (array $row, array $names, ?int $fallbackIdx) use ($map) {
            if ($map !== null) {
                foreach ($names as $n) {
                    if (isset($map[$n]) && isset($row[$map[$n]])) {
                        return trim((string)$row[$map[$n]]);
                    }
                }
                return '';
            }
            return ($fallbackIdx !== null && isset($row[$fallbackIdx])) ? trim((string)$row[$fallbackIdx]) : '';
        };

        $added = 0; $updated = 0; $skipped = 0; $alerts = 0; $errors = [];
        foreach ($rows as $n => $row) {
            if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $fuel   = $pick($row, ['fuel_type', 'fuel'], 0);
            $city   = $pick($row, ['city'], 1);
            $priceV = $pick($row, ['price_per_liter', 'price'], 2);
            $dateV  = $pick($row, ['price_date', 'date'], 3);
            $region = $pick($row, ['region'], 4);
            $source = $pick($row, ['source'], 5) ?: 'CSV import';
            $ref    = $pick($row, ['source_ref'], 6);
            $notes  = $pick($row, ['notes'], 7);
            $curr   = $pick($row, ['currency'], null) ?: 'USD';

            $price = parse_decimal($priceV, 2);
            if ($fuel === '' || $city === '' || $price === null) {
                $skipped++;
                if (count($errors) < 5) {
                    $errors[] = 'Row ' . ($n + 1) . ': fuel, city and a positive price are required.';
                }
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateV)) {
                $dateV = date('Y-m-d');
            }

            $res = market_record($pdo, [
                'fuel_type'       => $fuel,
                'city'            => $city,
                'region'          => $region,
                'price_per_liter' => $price,
                'currency'        => $curr,
                'price_date'      => $dateV,
                'source'          => $source,
                'source_ref'      => $ref,
                'notes'           => $notes,
                'is_demo'         => 0,
            ]);
            if (empty($res['ok'])) {
                $skipped++;
                continue;
            }
            if (($res['action'] ?? '') === 'updated') { $updated++; } else { $added++; }
            if (!empty($res['alert'])) { $alerts++; }
        }

        log_activity($pdo, 'market_import', sprintf('Imported market CSV: %d added, %d updated, %d skipped, %d alert(s).', $added, $updated, $skipped, $alerts), 'market_price', null);
        $msg = sprintf('CSV import complete — %d added, %d updated, %d skipped.', $added, $updated, $skipped);
        if ($alerts > 0) {
            $msg .= ' ' . $alerts . ' alert(s) triggered.';
        }
        set_flash($skipped > 0 && $added + $updated === 0 ? 'error' : 'success', $msg . ($errors ? ' ' . implode(' ', $errors) : ''));
        redirect('market_prices.php');
    }

    /* ---- Create / update a source ---- */
    if ($action === 'save_source') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        $kind     = (string)($_POST['kind'] ?? 'api');
        if (!in_array($kind, ['api', 'csv', 'manual'], true)) { $kind = 'api'; }
        $endpoint = mb_substr(trim((string)($_POST['endpoint'] ?? '')), 0, 255);
        $city     = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80);
        $jsonPath = mb_substr(trim((string)($_POST['json_path'] ?? '')), 0, 120);
        $fuelMap  = trim((string)($_POST['fuel_map'] ?? ''));
        $currency = mb_substr(trim((string)($_POST['currency'] ?? 'USD')) ?: 'USD', 0, 8);
        $docUrl   = mb_substr(trim((string)($_POST['doc_url'] ?? '')), 0, 255);
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            set_flash('error', 'Give the source a name.');
            redirect('market_import.php');
        }
        if ($fuelMap !== '') {
            $decoded = json_decode($fuelMap, true);
            if (!is_array($decoded)) {
                set_flash('error', 'Fuel map must be a JSON object, e.g. {"gasoline":"Petrol"}.');
                redirect('market_import.php');
            }
            $fuelMap = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        } else {
            $fuelMap = null;
        }

        $vals = [
            $name, $kind,
            ($endpoint !== '' ? $endpoint : null),
            ($city !== '' ? $city : null),
            ($jsonPath !== '' ? $jsonPath : null),
            $fuelMap, $currency,
            ($docUrl !== '' ? $docUrl : null),
        ];

        if ($id > 0) {
            $vals[] = $active;
            $vals[] = $id;
            $pdo->prepare(
                "UPDATE market_sources
                 SET name = ?, kind = ?, endpoint = ?, city = ?, json_path = ?, fuel_map = ?, currency = ?, doc_url = ?, is_active = ?
                 WHERE id = ?"
            )->execute($vals);
            log_activity($pdo, 'market_source', 'Updated market source "' . $name . '".', 'market_source', $id);
            set_flash('success', 'Source updated.');
        } else {
            $vals[] = $active;
            $pdo->prepare(
                "INSERT INTO market_sources (name, kind, endpoint, city, json_path, fuel_map, currency, doc_url, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute($vals);
            $newId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'market_source', 'Added market source "' . $name . '".', 'market_source', $newId);
            set_flash('success', 'Source added.');
        }
        redirect('market_import.php');
    }

    if ($action === 'toggle_source') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE market_sources SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'market_source', 'Toggled market source #' . $id . '.', 'market_source', $id);
            set_flash('success', 'Source status changed.');
        }
        redirect('market_import.php');
    }

    if ($action === 'delete_source') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM market_sources WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'market_source', 'Deleted market source #' . $id . '.', 'market_source', $id);
            set_flash('success', 'Source removed.');
        }
        redirect('market_import.php');
    }

    /* ---- Sync one source (or all) ---- */
    if ($action === 'sync_source' || $action === 'sync_all') {
        $sources = [];
        try {
            if ($action === 'sync_source') {
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM market_sources WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if ($row) { $sources[] = $row; }
            } else {
                $sources = $pdo->query("SELECT * FROM market_sources WHERE kind = 'api' AND is_active = 1")->fetchAll();
            }
        } catch (Throwable $e) {
            $sources = [];
        }

        if (empty($sources)) {
            set_flash('error', 'No active API source to sync. Add one below (with its documentation URL).');
            redirect('market_import.php');
        }

        $okCount = 0; $failCount = 0; $newCount = 0; $updCount = 0; $alertCount = 0; $notes = [];
        foreach ($sources as $src) {
            $res = market_source_sync($pdo, $src);
            if ($res['ok']) {
                $okCount++;
                $newCount  += count($res['recorded']);
                $updCount  += count($res['updated']);
                $alertCount += (int)$res['alerts'];
                $notes[] = $src['name'] . ': ' . $res['note'];
            } else {
                $failCount++;
                $notes[] = $src['name'] . ': ' . $res['error'];
            }
        }

        log_activity($pdo, 'market_sync', sprintf('Market source sync: %d ok, %d failed, %d applied, %d alert(s).', $okCount, $failCount, $newCount + $updCount, $alertCount), 'market_source', null);
        if ($okCount > 0) {
            set_flash('success', sprintf('Sync complete — %d source(s) OK, %d applied (%d new / %d updated), %d alert(s). %s', $okCount, $newCount + $updCount, $newCount, $updCount, $alertCount, implode(' | ', $notes)));
        } else {
            set_flash('error', 'Sync failed. ' . implode(' | ', $notes));
        }
        redirect('market_import.php');
    }

    redirect('market_import.php');
}

/* ==========================================================
 *  Data
 * ========================================================= */
$sources = [];
try {
    $sources = $pdo->query("SELECT * FROM market_sources ORDER BY is_active DESC, name ASC")->fetchAll();
} catch (Throwable $e) {
    $sources = [];
}

$demoCount = 0;
$totalObs  = 0;
try {
    $totalObs  = (int)$pdo->query("SELECT COUNT(*) FROM market_prices")->fetchColumn();
    $demoCount = (int)$pdo->query("SELECT COUNT(*) FROM market_prices WHERE is_demo = 1")->fetchColumn();
} catch (Throwable $e) {
    /* ignore */
}

$activeApiSources = 0;
foreach ($sources as $s) {
    if ($s['kind'] === 'api' && (int)$s['is_active'] === 1) { $activeApiSources++; }
}

$cities = market_cities($pdo);
$defaultCity = (string)get_setting($pdo, 'market_default_city', 'Beirut');
$smPage = basename($_SERVER['PHP_SELF']);
include __DIR__ . '/includes/header.php';
?>

<!-- MODULE TABS -->
<div class="inline-flex bg-slate-800 border border-slate-700 rounded-xl p-1">
    <a href="market_prices.php" class="px-4 py-2 rounded-lg text-sm font-medium transition text-slate-400 hover:text-white">
        <i class="fa-solid fa-arrow-trend-up mr-1.5"></i>Market Prices
    </a>
    <a href="market_import.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'market_import.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
        <i class="fa-solid fa-file-csv mr-1.5"></i>Import &amp; Sources
    </a>
    <a href="last_price.php" class="px-4 py-2 rounded-lg text-sm font-medium transition text-slate-400 hover:text-white">
        <i class="fa-solid fa-tags mr-1.5"></i>Active Prices
    </a>
</div>

<!-- SOURCE-OF-TRUTH NOTE -->
<div class="bg-slate-800/60 border border-slate-700 rounded-2xl px-5 py-4 flex items-start gap-3">
    <i class="fa-solid fa-circle-info text-cyan-400 mt-0.5"></i>
    <div class="text-xs text-slate-400 leading-relaxed">
        <p><strong class="text-slate-200">No automatic market source is configured by default.</strong> There is no universal free official fuel-price API, so this connector is generic: you provide a documented endpoint, where the values sit, and how the provider's fuel names map to yours.</p>
        <p class="mt-1">The file <code class="text-amber-400">market_feed.json</code> shipped with the app is a <strong class="text-fuchsia-300">demo sample</strong>, not a live source. Add real data via manual entry, CSV import, or your own configured endpoint.
        <?php if ($demoCount > 0): ?>There are currently <strong class="text-fuchsia-300"><?= $demoCount ?></strong> demo row(s) in the database.<?php endif; ?>
        </p>
    </div>
</div>

<!-- CSV IMPORT -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <div class="flex items-start justify-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-file-csv text-amber-400 mr-2"></i>Import from CSV</h3>
            <p class="text-slate-500 text-xs mt-0.5">Columns: <code class="text-slate-400">fuel_type, city, price_per_liter, price_date, region, source, source_ref, notes</code> (header row optional).</p>
        </div>
        <a href="market_import.php?template=1" class="px-3 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs font-medium transition">
            <i class="fa-solid fa-download mr-1.5"></i>Download template
        </a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">
        <div>
            <label class="text-slate-400 text-xs block mb-1">Upload a .csv file</label>
            <input type="file" name="csv_file" accept=".csv,text/csv"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-slate-200 text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-amber-500 file:text-slate-900 file:text-xs file:font-semibold">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">…or paste CSV rows</label>
            <textarea name="csv_data" rows="6" spellcheck="false"
                      class="w-full bg-slate-900 border border-slate-600 rounded-xl px-3 py-2 text-slate-200 text-xs font-mono focus:outline-none focus:border-amber-500"
                      placeholder="Petrol,Beirut,1.95,<?= date('Y-m-d') ?>,Beirut,Ministry bulletin,,"></textarea>
        </div>
        <button class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold transition">
            <i class="fa-solid fa-file-import mr-1.5"></i>Import CSV
        </button>
    </form>
</div>

<!-- SOURCES -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-satellite-dish text-cyan-400 mr-2"></i>Documented Sources</h3>
            <p class="text-slate-500 text-xs mt-0.5">
                <?= count($sources) ?> configured · <?= $activeApiSources ?> active API source(s).
                <?php if ($activeApiSources === 0): ?>
                    <span class="text-amber-400">No automatic source is configured — use manual entry or CSV.</span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($activeApiSources > 0): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_all">
            <button class="px-3 py-2 rounded-xl bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-200 text-xs font-semibold transition">
                <i class="fa-solid fa-rotate mr-1.5"></i>Sync all active
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-5 py-3">Source</th>
                    <th class="text-left px-5 py-3">Kind</th>
                    <th class="text-left px-5 py-3">Endpoint / Path</th>
                    <th class="text-left px-5 py-3">Last Sync</th>
                    <th class="text-left px-5 py-3">Status</th>
                    <th class="text-right px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($sources)): ?>
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">No sources configured yet. Add one below if your provider publishes a documented JSON feed.</td></tr>
                <?php else: foreach ($sources as $s): ?>
                <tr class="hover:bg-slate-700/30 transition <?= (int)$s['is_active'] === 1 ? '' : 'opacity-60' ?>">
                    <td class="px-5 py-3">
                        <span class="text-white font-medium"><?= htmlspecialchars((string)$s['name']) ?></span>
                        <?php if ($s['city']): ?><span class="block text-slate-500 text-[11px]"><?= htmlspecialchars((string)$s['city']) ?> · <?= htmlspecialchars((string)$s['currency']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-medium uppercase
                            <?= $s['kind'] === 'api' ? 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20' : ($s['kind'] === 'csv' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-slate-700 text-slate-300 border border-slate-600') ?>">
                            <?= htmlspecialchars((string)$s['kind']) ?>
                        </span>
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-400 max-w-[18rem]">
                        <span class="block truncate" title="<?= htmlspecialchars((string)$s['endpoint']) ?>"><?= htmlspecialchars((string)($s['endpoint'] ?? '—')) ?></span>
                        <?php if ($s['json_path']): ?><span class="block text-slate-500">path: <?= htmlspecialchars((string)$s['json_path']) ?></span><?php endif; ?>
                        <?php if ($s['doc_url']): ?><a href="<?= htmlspecialchars((string)$s['doc_url']) ?>" target="_blank" rel="noopener" class="text-cyan-400 hover:text-cyan-300">docs <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i></a><?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-400"><?= $s['last_sync_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$s['last_sync_at']))) : '<span class="text-slate-600">never</span>' ?></td>
                    <td class="px-5 py-3 text-xs max-w-[16rem]">
                        <?php if (empty($s['last_status'])): ?>
                            <span class="text-slate-600">—</span>
                        <?php else: $bad = str_starts_with((string)$s['last_status'], 'FAILED'); ?>
                            <span class="<?= $bad ? 'text-red-400' : 'text-emerald-400' ?>" title="<?= htmlspecialchars((string)$s['last_status']) ?>">
                                <i class="fa-solid <?= $bad ? 'fa-circle-exclamation' : 'fa-circle-check' ?> mr-1"></i><?= htmlspecialchars(mb_strimwidth((string)$s['last_status'], 0, 46, '…')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <?php if ($s['kind'] === 'api' && (int)$s['is_active'] === 1): ?>
                            <form method="POST"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="sync_source">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="text-cyan-400 hover:text-cyan-300 text-xs" title="Sync now"><i class="fa-solid fa-rotate"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="POST"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_source">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="text-<?= (int)$s['is_active'] === 1 ? 'emerald' : 'slate' ?>-400 hover:text-white text-xs" title="Enable / disable"><i class="fa-solid fa-power-off"></i></button>
                            </form>
                            <button type="button" class="text-slate-400 hover:text-amber-400 text-xs" title="Edit"
                                    onclick='openSource(<?= json_encode([
                                        'id' => (int)$s['id'], 'name' => (string)$s['name'], 'kind' => (string)$s['kind'],
                                        'endpoint' => (string)($s['endpoint'] ?? ''), 'city' => (string)($s['city'] ?? ''),
                                        'json_path' => (string)($s['json_path'] ?? ''), 'fuel_map' => (string)($s['fuel_map'] ?? ''),
                                        'currency' => (string)$s['currency'], 'doc_url' => (string)($s['doc_url'] ?? ''),
                                        'is_active' => (int)$s['is_active'],
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this source? Recorded prices are kept.')"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_source">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="text-slate-500 hover:text-red-400 text-xs" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SOURCE FORM -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <h3 class="text-white font-semibold text-sm mb-1"><i class="fa-solid fa-plus text-amber-400 mr-2"></i>Add / Edit a Source</h3>
    <p class="text-slate-500 text-xs mb-4">
        Only use this for a provider that <strong class="text-slate-300">documents</strong> an automated JSON feed. Paste its documentation URL so the integration is auditable.
    </p>
    <form method="POST" id="sourceForm" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_source">
        <input type="hidden" name="id" id="src_id" value="0">
        <div>
            <label class="text-slate-400 text-xs block mb-1">Name <span class="text-red-400">*</span></label>
            <input type="text" name="name" id="src_name" required maxlength="80" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="e.g. National Fuel Bulletin">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Kind</label>
            <select name="kind" id="src_kind" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                <option value="api">API (HTTP JSON)</option>
                <option value="csv">CSV (external)</option>
                <option value="manual">Manual / reference</option>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">City</label>
            <input type="text" name="city" id="src_city" maxlength="80" list="cityList" value="<?= htmlspecialchars($defaultCity) ?>" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div class="lg:col-span-2">
            <label class="text-slate-400 text-xs block mb-1">Endpoint (URL or app-relative file)</label>
            <input type="text" name="endpoint" id="src_endpoint" maxlength="255" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="https://api.provider.tld/v1/fuel-prices">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">JSON path <span class="text-slate-500">(dot notation)</span></label>
            <input type="text" name="json_path" id="src_json_path" maxlength="120" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="e.g. data.prices">
        </div>
        <div class="lg:col-span-2">
            <label class="text-slate-400 text-xs block mb-1">Fuel map <span class="text-slate-500">(JSON: provider key → your fuel)</span></label>
            <input type="text" name="fuel_map" id="src_fuel_map" class="w-full bg-slate-900 border border-slate-600 rounded-xl px-3 py-2.5 text-slate-200 text-sm font-mono focus:outline-none focus:border-amber-500" placeholder='{"gasoline":"Petrol","gasoil":"Diesel"}'>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Currency</label>
            <input type="text" name="currency" id="src_currency" maxlength="8" value="USD" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div class="lg:col-span-2">
            <label class="text-slate-400 text-xs block mb-1">Documentation URL</label>
            <input type="url" name="doc_url" id="src_doc_url" maxlength="255" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="https://provider.tld/docs/api">
        </div>
        <div class="flex items-end">
            <label class="inline-flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" name="is_active" id="src_is_active" value="1" checked class="accent-amber-500"> Active
            </label>
        </div>
        <div class="sm:col-span-2 lg:col-span-3 flex items-center gap-3">
            <button class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold transition">
                <i class="fa-solid fa-floppy-disk mr-1.5"></i>Save source
            </button>
            <button type="button" onclick="resetSource()" class="px-4 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-medium transition">Clear</button>
        </div>
    </form>
</div>

<datalist id="cityList">
    <?php foreach (array_keys($cities) as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
</datalist>

<script>
function openSource(s) {
    document.getElementById('src_id').value       = s.id || 0;
    document.getElementById('src_name').value     = s.name || '';
    document.getElementById('src_kind').value     = s.kind || 'api';
    document.getElementById('src_city').value     = s.city || '';
    document.getElementById('src_endpoint').value = s.endpoint || '';
    document.getElementById('src_json_path').value= s.json_path || '';
    document.getElementById('src_fuel_map').value = s.fuel_map || '';
    document.getElementById('src_doc_url').value  = s.doc_url || '';
    document.getElementById('src_is_active').checked = !!s.is_active;
    document.getElementById('sourceForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function resetSource() {
    document.getElementById('src_id').value = 0;
    document.getElementById('sourceForm').reset();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
