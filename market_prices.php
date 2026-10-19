<?php
/**
 * Market Prices — fuel price intelligence.
 *
 * Records fuel price observations per city (manual entry, CSV import or a
 * configured JSON source), tracks the change against the previous observation
 * and compares the market price with the station's selling price and its
 * supplier purchase costs.
 *
 * Integrity rules:
 *   - A price is only shown as "Current" while it is inside the freshness
 *     window (`market_stale_days`); older observations are labelled "Stale"
 *     and are never presented as live market data.
 *   - Every observation shows its source and last-updated timestamp.
 *   - Bundled sample rows are flagged `is_demo` and badged DEMO.
 */
require_once __DIR__ . '/config.php';

$pageTitle    = 'Market Prices';
$pageSubtitle = 'Track fuel price movements by city and compare them with your costs';

$fuelTypes = market_fuel_types($pdo);

/** Normalise a Y-m-d date input, falling back to today. */
$cleanDate = static function ($v): string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : date('Y-m-d');
};

/* ==========================================================
 *  Actions
 *  Everyone can read market data; only admins may change it.
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if (!is_admin()) {
        set_flash('error', 'Access Denied: Admin privileges are required to change market prices.');
        redirect('market_prices.php');
    }

    /* ---- Record one observation ---- */
    if ($action === 'add_price') {
        $fuel   = trim((string)($_POST['fuel_type'] ?? ''));
        $city   = trim((string)($_POST['city'] ?? ''));
        $price  = parse_decimal($_POST['price_per_liter'] ?? '', 2);
        $date   = $cleanDate($_POST['price_date'] ?? '');
        $region = trim((string)($_POST['region'] ?? ''));
        $source = trim((string)($_POST['source'] ?? '')) ?: 'Manual';
        $ref    = trim((string)($_POST['source_ref'] ?? ''));
        $notes  = trim((string)($_POST['notes'] ?? ''));
        $cur    = trim((string)($_POST['currency'] ?? '')) ?: 'USD';

        if ($fuel === '' || $city === '' || $price === null) {
            set_flash('error', 'Enter a fuel type, a city and a valid positive price.');
            redirect('market_prices.php');
        }
        if (mb_strlen($city) > 80) {
            $city = mb_substr($city, 0, 80);
        }

        $res = market_record($pdo, [
            'fuel_type'       => $fuel,
            'city'            => $city,
            'region'          => $region,
            'price_per_liter' => $price,
            'currency'        => $cur,
            'price_date'      => $date,
            'source'          => $source,
            'source_ref'      => $ref,
            'notes'           => $notes,
            'is_demo'         => 0,
        ]);

        if (empty($res['ok'])) {
            set_flash('error', 'Could not save the price: ' . ($res['error'] ?? 'unknown error'));
            redirect('market_prices.php');
        }

        log_activity(
            $pdo,
            'market_add',
            sprintf('Recorded %s market price for %s: $%s on %s (source: %s).', $fuel, $city, money($price), $date, $source),
            'market_price',
            (int)$res['id']
        );

        $changeNote = 'first observation for this market';
        if ($res['change'] !== null) {
            $changeNote = sprintf('%+.2f%% vs previous $%s', (float)$res['change_pct'], money((float)$res['previous']));
        }
        set_flash('success', sprintf('%s price for %s saved: $%s (%s).', $fuel, $city, money($price), $changeNote));

        if (!empty($res['alert'])) {
            set_flash('error', 'ALERT: ' . $res['alert']['message']);
            log_activity($pdo, 'market_alert', (string)$res['alert']['message'], 'market_price', (int)$res['id']);
        }
        redirect('market_prices.php?city=' . urlencode($city) . '&fuel=' . urlencode($fuel));
    }

    /* ---- Save alert rule + freshness window ---- */
    if ($action === 'save_alert_rule') {
        $enabled = isset($_POST['alert_enabled']) ? '1' : '0';
        $pct     = (float)($_POST['alert_pct'] ?? 3);
        if ($pct <= 0 || $pct > 100) {
            $pct = 3.0;
        }
        $dir = (string)($_POST['alert_direction'] ?? 'both');
        if (!in_array($dir, ['both', 'up', 'down'], true)) {
            $dir = 'both';
        }
        $stale = (int)($_POST['stale_days'] ?? 3);
        if ($stale < 1)  { $stale = 1; }
        if ($stale > 60) { $stale = 60; }
        $city = mb_substr(trim((string)($_POST['default_city'] ?? '')), 0, 80);

        set_setting($pdo, 'market_alert_enabled', $enabled);
        set_setting($pdo, 'market_alert_pct', number_format($pct, 2, '.', ''));
        set_setting($pdo, 'market_alert_direction', $dir);
        set_setting($pdo, 'market_stale_days', (string)$stale);
        set_setting($pdo, 'market_default_city', $city);

        log_activity(
            $pdo,
            'market_alert_rule',
            sprintf('Market alerts %s at %.2f%% (%s); prices go stale after %d day(s).', $enabled === '1' ? 'enabled' : 'disabled', $pct, $dir, $stale),
            'setting',
            null
        );
        set_flash('success', 'Market alert and freshness settings saved.');
        redirect('market_prices.php');
    }

    /* ---- Remove demo dataset ---- */
    if ($action === 'remove_demo') {
        $n = 0;
        try {
            $n = (int)$pdo->exec("DELETE FROM market_prices WHERE is_demo = 1");
        } catch (Throwable $e) {
            $n = 0;
        }
        set_setting($pdo, 'market_demo_state', 'removed');
        log_activity($pdo, 'market_demo', 'Removed ' . $n . ' demo market price row(s).', 'market_price', null);
        set_flash('success', $n . ' demo row(s) removed. Market Prices now shows only real data.');
        redirect('market_prices.php');
    }

    /* ---- Load demo dataset (explicit admin action) ---- */
    if ($action === 'load_demo') {
        $n = market_seed_demo($pdo, 400, true);
        set_setting($pdo, 'market_demo_state', 'seeded');
        log_activity($pdo, 'market_demo', 'Loaded ' . $n . ' demo market price row(s).', 'market_price', null);
        set_flash('success', $n . ' demo row(s) loaded (clearly labelled as sample data).');
        redirect('market_prices.php');
    }

    redirect('market_prices.php');
}

/* ==========================================================
 *  Filters
 * ========================================================= */
$city      = trim((string)($_GET['city'] ?? ''));
$fuel      = trim((string)($_GET['fuel'] ?? ''));
$showDemo  = !isset($_GET['demo']) || $_GET['demo'] !== '0';
$periodOpts = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'];
$period    = (int)($_GET['period'] ?? 30);
if (!isset($periodOpts[$period])) {
    $period = 30;
}

$cities = market_cities($pdo);
if ($city !== '' && !isset($cities[$city])) {
    $city = '';
}
if ($fuel !== '' && !in_array($fuel, $fuelTypes, true)) {
    $fuel = '';
}

/** Build a link preserving the active filters. */
$link = static function (array $over = []) use ($city, $fuel, $period, $showDemo): string {
    $p = array_merge([
        'city'   => $city,
        'fuel'   => $fuel,
        'period' => $period,
        'demo'   => $showDemo ? '1' : '0',
    ], $over);
    return 'market_prices.php?' . http_build_query($p);
};

/* ==========================================================
 *  Data
 * ========================================================= */
$current  = market_current_rows($pdo, $fuel !== '' ? $fuel : null, $city !== '' ? $city : null, $showDemo);
$compare  = market_compare($pdo, $city !== '' ? $city : null, $showDemo);
$alertCfg = market_alert_settings($pdo);
$staleDays = market_stale_days($pdo);

/* Latest observation overall, per fuel (across the filtered cities). */
$latestByFuel = [];
foreach ($current as $row) {
    $ft = (string)$row['fuel_type'];
    if (!isset($latestByFuel[$ft]) || strcmp((string)$row['price_date'], (string)$latestByFuel[$ft]['price_date']) > 0) {
        $latestByFuel[$ft] = $row;
    }
}

/* Chart: one line per fuel, sharing a union label axis. */
$seriesMaps = [];
$allDates   = [];
foreach ($fuelTypes as $ft) {
    if ($fuel !== '' && $ft !== $fuel) {
        continue;
    }
    $map = [];
    foreach (market_series($pdo, $ft, $city !== '' ? $city : null, $period, $showDemo) as $pt) {
        $key = (string)$pt['price_date'];
        $map[$key] = (float)$pt['price'];
        $allDates[$key] = true;
    }
    $seriesMaps[$ft] = $map;
}
$labels = array_keys($allDates);
sort($labels);

$fuelColors      = ['Petrol' => '#F59E0B', 'Diesel' => '#22D3EE', 'LPG' => '#A78BFA'];
$fallbackPalette = ['#34D399', '#F472B6', '#60A5FA', '#FBBF24'];

$chartDatasets = [];
$fi = 0;
foreach ($seriesMaps as $ft => $map) {
    $color = $fuelColors[$ft] ?? $fallbackPalette[$fi % count($fallbackPalette)];
    $fi++;
    $data = [];
    foreach ($labels as $d) {
        $data[] = $map[$d] ?? null;
    }
    $chartDatasets[] = ['label' => (string)$ft, 'data' => $data, 'color' => $color];
}

$chartPayload = json_encode([
    'labels'   => array_map(static fn($d) => date('M j', strtotime((string)$d)), $labels),
    'datasets' => $chartDatasets,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

/* Recent alerts + counts. */
$recentAlerts = [];
$alerts7 = 0;
try {
    $recentAlerts = $pdo->query("SELECT * FROM market_alerts ORDER BY created_at DESC, id DESC LIMIT 8")->fetchAll();
    $alerts7 = (int)$pdo->query("SELECT COUNT(*) FROM market_alerts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist yet */
}

/* History (filtered). */
$history = [];
$histWhere = [];
$histArgs  = [];
if ($city !== '') { $histWhere[] = 'city = ?';      $histArgs[] = $city; }
if ($fuel !== '') { $histWhere[] = 'fuel_type = ?'; $histArgs[] = $fuel; }
if (!$showDemo)   { $histWhere[] = 'is_demo = 0'; }
try {
    $h = $pdo->prepare(
        "SELECT * FROM market_prices"
        . ($histWhere ? ' WHERE ' . implode(' AND ', $histWhere) : '')
        . " ORDER BY price_date DESC, created_at DESC, id DESC LIMIT 40"
    );
    $h->execute($histArgs);
    $history = $h->fetchAll();
} catch (Throwable $e) {
    $history = [];
}

/* Totals + provenance. */
$totalObs  = 0;
$demoCount = 0;
$lastUpdate = null;
try {
    $totalObs   = (int)$pdo->query("SELECT COUNT(*) FROM market_prices")->fetchColumn();
    $demoCount  = (int)$pdo->query("SELECT COUNT(*) FROM market_prices WHERE is_demo = 1")->fetchColumn();
    $lastUpdate = $pdo->query("SELECT MAX(created_at) FROM market_prices")->fetchColumn();
} catch (Throwable $e) {
    /* no data yet */
}

$freshCount = 0;
$staleCount = 0;
foreach ($current as $row) {
    if (!empty($row['is_fresh'])) { $freshCount++; } else { $staleCount++; }
}

$defaultCity = (string)get_setting($pdo, 'market_default_city', 'Beirut');

/** Render a price-change badge. */
$changeBadge = static function (?float $diff, ?float $pct): string {
    if ($diff === null) {
        return '<span class="text-slate-500 text-xs"><i class="fa-solid fa-minus mr-1"></i>no prior price</span>';
    }
    if (abs($diff) < 0.005) {
        return '<span class="text-slate-400 text-xs"><i class="fa-solid fa-equals mr-1"></i>unchanged</span>';
    }
    $up  = $diff > 0;
    $cls = $up ? 'text-emerald-400' : 'text-red-400';
    $ic  = $up ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    return sprintf(
        '<span class="%s text-sm font-semibold"><i class="fa-solid %s mr-1"></i>%s%s (%s%s%%)</span>',
        $cls, $ic,
        $up ? '+' : '-', money(abs($diff)),
        $up ? '+' : '-', number_format(abs((float)$pct), 2)
    );
};

$freshBadge = static function (array $row): string {
    if (!empty($row['is_fresh'])) {
        return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[11px] font-medium"><i class="fa-solid fa-circle-check"></i>Current</span>';
    }
    $age = $row['age_days'] === null ? '?' : (int)$row['age_days'];
    return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[11px] font-medium" title="Older than the freshness window"><i class="fa-solid fa-clock-rotate-left"></i>Stale &middot; ' . $age . 'd</span>';
};

$demoBadge = static function (array $row): string {
    return !empty($row['is_demo'])
        ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-fuchsia-500/10 text-fuchsia-300 border border-fuchsia-500/30 text-[11px] font-semibold"><i class="fa-solid fa-flask"></i>DEMO</span>'
        : '';
};

$smPage = basename($_SERVER['PHP_SELF']);
include __DIR__ . '/includes/header.php';
?>

<!-- MODULE TABS -->
<div class="inline-flex bg-slate-800 border border-slate-700 rounded-xl p-1 flex-wrap">
    <a href="market_prices.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'market_prices.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
        <i class="fa-solid fa-arrow-trend-up mr-1.5"></i>Market Prices
    </a>
    <a href="market_import.php" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $smPage === 'market_import.php' ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>">
        <i class="fa-solid fa-file-csv mr-1.5"></i>Import &amp; Sources
    </a>
    <?php if (is_admin()): ?>
    <a href="last_price.php" class="px-4 py-2 rounded-lg text-sm font-medium transition text-slate-400 hover:text-white">
        <i class="fa-solid fa-tags mr-1.5"></i>Active Prices
    </a>
    <?php endif; ?>
</div>

<?php if ($demoCount > 0): ?>
<!-- DEMO DATA BANNER -->
<div class="bg-fuchsia-500/10 border border-fuchsia-500/30 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-fuchsia-500/20 flex items-center justify-center flex-shrink-0">
        <i class="fa-solid fa-flask text-fuchsia-300 text-lg"></i>
    </div>
    <div class="flex-1">
        <p class="text-fuchsia-200 font-semibold text-sm">Demo dataset is loaded (<?= $demoCount ?> sample rows)</p>
        <p class="text-slate-400 text-xs mt-1">
            These are <strong class="text-fuchsia-300">sample</strong> prices bundled so the module is demonstrable — they are
            <strong class="text-fuchsia-300">not live market data</strong> and every row is tagged <code class="text-fuchsia-300">Demo dataset (sample)</code>.
            Add real prices (manual or CSV), then remove the sample rows.
        </p>
    </div>
    <?php if (is_admin()): ?>
    <form method="POST" onsubmit="return confirm('Remove all demo/sample rows? Real observations are kept.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_demo">
        <button class="px-4 py-2.5 rounded-xl bg-fuchsia-500/20 hover:bg-fuchsia-500/30 text-fuchsia-100 text-sm font-medium transition whitespace-nowrap">
            <i class="fa-solid fa-trash-can mr-1.5"></i>Remove demo data
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Current Markets</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $freshCount ?></p>
            <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-clock-rotate-left mr-1"></i><?= $staleCount ?> stale (outside <?= $staleDays ?>d)</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 flex items-center justify-center">
            <i class="fa-solid fa-arrow-trend-up text-cyan-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Cities Tracked</p>
            <p class="text-white text-2xl font-bold mt-1"><?= count($cities) ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-database mr-1"></i><?= $totalObs ?> observation(s) stored</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-500/5 flex items-center justify-center">
            <i class="fa-solid fa-location-dot text-amber-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Last Updated</p>
            <p class="text-white text-lg font-bold mt-1"><?= $lastUpdate ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$lastUpdate))) : '—' ?></p>
            <p class="text-slate-500 text-xs mt-2"><i class="fa-solid fa-clock mr-1"></i>Most recent observation</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-500/5 flex items-center justify-center">
            <i class="fa-solid fa-cloud-arrow-down text-emerald-400 text-xl"></i>
        </div>
    </div>
    <div class="card-glow bg-slate-800 border border-slate-700 rounded-2xl p-5 flex items-start justify-between transition">
        <div>
            <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Alerts (7 days)</p>
            <p class="text-white text-2xl font-bold mt-1"><?= $alerts7 ?></p>
            <p class="<?= $alertCfg['enabled'] ? 'text-emerald-400' : 'text-slate-500' ?> text-xs mt-2">
                <i class="fa-solid fa-bell mr-1"></i><?= $alertCfg['enabled'] ? 'Threshold ' . number_format($alertCfg['pct'], 2) . '% (' . $alertCfg['direction'] . ')' : 'Alerts disabled' ?>
            </p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500/20 to-red-500/5 flex items-center justify-center">
            <i class="fa-solid fa-bell text-red-400 text-xl"></i>
        </div>
    </div>
</div>

<!-- INTEGRITY NOTE -->
<div class="bg-slate-800/60 border border-slate-700 rounded-2xl px-5 py-3 flex items-start gap-3">
    <i class="fa-solid fa-shield-halved text-cyan-400 mt-0.5"></i>
    <p class="text-slate-400 text-xs leading-relaxed">
        A price is shown as <span class="text-emerald-400 font-medium">Current</span> only when its price date falls inside the
        freshness window of <strong class="text-slate-200"><?= $staleDays ?> day(s)</strong>; otherwise it is marked
        <span class="text-amber-400 font-medium">Stale</span> and is never reported as live. Every row carries its
        <strong class="text-slate-200">source</strong> and a <strong class="text-slate-200">last-updated</strong> timestamp.
    </p>
</div>

<!-- LATEST PER FUEL -->
<?php if (!empty($latestByFuel)): ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($latestByFuel as $ft => $row): ?>
    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full" style="background: <?= htmlspecialchars($fuelColors[$ft] ?? '#22D3EE') ?>"></span>
                <p class="text-white font-semibold"><?= htmlspecialchars((string)$ft) ?></p>
            </div>
            <div class="flex items-center gap-1.5">
                <?= $demoBadge($row) ?>
                <?= $freshBadge($row) ?>
            </div>
        </div>
        <p class="text-3xl font-bold text-white mt-3">$<?= money($row['price_per_liter']) ?></p>
        <p class="text-slate-500 text-xs"><?= htmlspecialchars((string)$row['city']) ?><?= $row['region'] ? ' · ' . htmlspecialchars((string)$row['region']) : '' ?></p>
        <div class="mt-3 pt-3 border-t border-slate-700 space-y-1.5">
            <div><?= $changeBadge($row['change'] === null ? null : (float)$row['change'], $row['change_pct'] === null ? null : (float)$row['change_pct']) ?></div>
            <p class="text-slate-500 text-[11px]"><i class="fa-solid fa-calendar-day mr-1"></i>Price date <?= htmlspecialchars(date('M j, Y', strtotime((string)$row['price_date']))) ?></p>
            <p class="text-slate-500 text-[11px]"><i class="fa-solid fa-tower-broadcast mr-1"></i><?= htmlspecialchars((string)$row['source']) ?></p>
            <p class="text-slate-500 text-[11px]"><i class="fa-solid fa-cloud-arrow-down mr-1"></i>Updated <?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="period" value="<?= (int)$period ?>">
        <div>
            <label class="text-slate-400 text-xs block mb-1">City</label>
            <select name="city" class="bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                <option value="">All cities</option>
                <?php foreach (array_keys($cities) as $c): ?>
                <option value="<?= htmlspecialchars((string)$c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)$c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Fuel type</label>
            <select name="fuel" class="bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                <option value="">All fuels</option>
                <?php foreach ($fuelTypes as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-300 pb-2 cursor-pointer">
            <input type="checkbox" name="demo" value="1" <?= $showDemo ? 'checked' : '' ?> class="accent-fuchsia-500">
            Include demo rows
        </label>
        <button class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold transition">
            <i class="fa-solid fa-filter mr-1.5"></i>Apply
        </button>
        <a href="market_prices.php" class="px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-medium transition">Reset</a>
    </form>
</div>

<!-- CHART -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-chart-line text-cyan-400 mr-2"></i>Market Price Trend</h3>
            <p class="text-slate-500 text-xs mt-0.5">
                <?= htmlspecialchars($city !== '' ? $city : 'All cities') ?> ·
                <?= htmlspecialchars($fuel !== '' ? $fuel : 'All fuels') ?> ·
                daily average per fuel, last <?= htmlspecialchars($periodOpts[$period]) ?>
            </p>
        </div>
        <div class="inline-flex bg-slate-900 border border-slate-700 rounded-xl p-1">
            <?php foreach ($periodOpts as $p => $lbl): ?>
            <a href="<?= htmlspecialchars($link(['period' => $p])) ?>"
               class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $period === $p ? 'bg-slate-700 text-white shadow' : 'text-slate-400 hover:text-white' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($labels)): ?>
    <div class="h-64 flex flex-col items-center justify-center text-center">
        <i class="fa-solid fa-chart-line text-4xl text-slate-600 mb-3"></i>
        <p class="text-slate-400 text-sm">No market prices for this selection.</p>
        <?php if (is_admin()): ?>
        <button onclick="var el=document.getElementById('manualEntry');if(el)el.scrollIntoView({behavior:'smooth'})" class="mt-3 text-amber-400 text-sm hover:text-amber-300">
            <i class="fa-solid fa-plus mr-1"></i>Record the first price
        </button>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="relative" style="height: 320px">
        <canvas id="marketChart"></canvas>
        <div id="chartFallback" class="hidden absolute inset-0 flex flex-col items-center justify-center text-center bg-slate-800 border border-dashed border-slate-600 rounded-xl">
            <i class="fa-solid fa-triangle-exclamation text-3xl text-amber-400 mb-2"></i>
            <p class="text-slate-300 text-sm">Chart library unavailable (offline?).</p>
            <p class="text-slate-500 text-xs mt-1">The price table and comparison below still show the data.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- COMPARISON -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-700">
        <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-scale-balanced text-amber-400 mr-2"></i>Market vs Your Prices &amp; Supplier Cost</h3>
        <p class="text-slate-500 text-xs mt-0.5">Latest market price compared with your selling price and the most recent confirmed supplier cost.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-5 py-3">Fuel</th>
                    <th class="text-right px-5 py-3">Market Price</th>
                    <th class="text-right px-5 py-3">Supplier Cost</th>
                    <th class="text-right px-5 py-3">Your Selling Price</th>
                    <th class="text-right px-5 py-3">Sell vs Market</th>
                    <th class="text-right px-5 py-3">Margin vs Cost</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php foreach ($compare as $c): ?>
                <tr class="hover:bg-slate-700/30 transition">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full" style="background: <?= htmlspecialchars($fuelColors[$c['fuel_type']] ?? '#22D3EE') ?>"></span>
                            <span class="text-white font-medium"><?= htmlspecialchars((string)$c['fuel_type']) ?></span>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($c['market_price'] === null): ?>
                            <span class="text-slate-500 text-xs">No data</span>
                        <?php else: ?>
                            <span class="text-white font-semibold">$<?= money($c['market_price']) ?></span>
                            <span class="block text-slate-500 text-[11px]">
                                <?= htmlspecialchars((string)$c['market_city']) ?> · <?= htmlspecialchars(date('M j', strtotime((string)$c['market_date']))) ?>
                                <?php if (!empty($c['market_demo'])): ?><span class="text-fuchsia-300 ml-1">DEMO</span><?php endif; ?>
                                <?php if (empty($c['market_fresh'])): ?><span class="text-amber-400 ml-1">stale</span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($c['cost_price'] === null): ?>
                            <span class="text-slate-500 text-xs">No purchases</span>
                        <?php else: ?>
                            <span class="text-slate-200">$<?= money($c['cost_price']) ?></span>
                            <span class="block text-slate-500 text-[11px]">
                                last buy<?= $c['cost_date'] ? ' ' . htmlspecialchars(date('M j', strtotime((string)$c['cost_date']))) : '' ?>
                                <?= $c['cost_avg30'] !== null ? ' · 30d avg $' . money($c['cost_avg30']) : '' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-right text-white font-semibold">$<?= money($c['selling_price']) ?></td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($c['vs_market'] === null): ?>
                            <span class="text-slate-500 text-xs">—</span>
                        <?php else:
                            $up = $c['vs_market'] >= 0; ?>
                            <span class="<?= $up ? 'text-emerald-400' : 'text-red-400' ?> font-medium">
                                <?= $up ? '+' : '-' ?>$<?= money(abs($c['vs_market'])) ?>
                            </span>
                            <span class="block text-slate-500 text-[11px]"><?= $up ? 'above market' : 'below market' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($c['vs_cost'] === null): ?>
                            <span class="text-slate-500 text-xs">—</span>
                        <?php else:
                            $up = $c['vs_cost'] >= 0; ?>
                            <span class="<?= $up ? 'text-emerald-400' : 'text-red-400' ?> font-medium">
                                <?= $up ? '+' : '-' ?>$<?= money(abs($c['vs_cost'])) ?>
                            </span>
                            <span class="block text-slate-500 text-[11px]">per liter</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- CURRENT PRICES -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-list text-cyan-400 mr-2"></i>Latest Price per Market</h3>
            <p class="text-slate-500 text-xs mt-0.5">Newest observation for every fuel / city, with source and freshness.</p>
        </div>
        <?php if (is_admin()): ?>
        <button onclick="document.getElementById('manualEntry').scrollIntoView({behavior:'smooth'})" class="px-3 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-xs font-semibold transition">
            <i class="fa-solid fa-plus mr-1"></i>Add price
        </button>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-5 py-3">Fuel / City</th>
                    <th class="text-right px-5 py-3">Current</th>
                    <th class="text-right px-5 py-3">Previous</th>
                    <th class="text-right px-5 py-3">Change</th>
                    <th class="text-left px-5 py-3">Source</th>
                    <th class="text-left px-5 py-3">As of / Updated</th>
                    <th class="text-left px-5 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($current)): ?>
                <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">
                    No market prices recorded yet.
                    <?php if (is_admin()): ?> Use the form below or <a href="market_import.php" class="text-amber-400">import a CSV</a>.<?php endif; ?>
                </td></tr>
                <?php else: foreach ($current as $row): ?>
                <tr class="hover:bg-slate-700/30 transition">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full" style="background: <?= htmlspecialchars($fuelColors[$row['fuel_type']] ?? '#22D3EE') ?>"></span>
                            <div>
                                <span class="text-white font-medium"><?= htmlspecialchars((string)$row['fuel_type']) ?></span>
                                <span class="block text-slate-500 text-[11px]"><?= htmlspecialchars((string)$row['city']) ?><?= $row['region'] ? ' · ' . htmlspecialchars((string)$row['region']) : '' ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-right text-white font-semibold">$<?= money($row['price_per_liter']) ?></td>
                    <td class="px-5 py-3 text-right text-slate-400">
                        <?= $row['change'] === null ? '—' : '$' . money((float)((float)$row['price_per_liter'] - (float)$row['change'])) ?>
                    </td>
                    <td class="px-5 py-3 text-right"><?= $changeBadge($row['change'] === null ? null : (float)$row['change'], $row['change_pct'] === null ? null : (float)$row['change_pct']) ?></td>
                    <td class="px-5 py-3">
                        <span class="text-slate-300 text-xs"><?= htmlspecialchars((string)$row['source']) ?></span>
                        <?= $demoBadge($row) ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-400">
                        <?= htmlspecialchars(date('M j, Y', strtotime((string)$row['price_date']))) ?>
                        <span class="block text-slate-500 text-[11px]"><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></span>
                    </td>
                    <td class="px-5 py-3"><?= $freshBadge($row) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ALERTS + RULE -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
    <div class="xl:col-span-2 bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between">
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-bell text-red-400 mr-2"></i>Recent Price Alerts</h3>
            <span class="text-slate-500 text-xs"><?= $alerts7 ?> in the last 7 days</span>
        </div>
        <?php if (empty($recentAlerts)): ?>
        <div class="px-5 py-10 text-center text-slate-500 text-sm">
            <i class="fa-solid fa-bell-slash text-2xl mb-2 block text-slate-600"></i>
            No alerts yet. Alerts fire when a new price moves more than the configured threshold.
        </div>
        <?php else: ?>
        <ul class="divide-y divide-slate-700">
            <?php foreach ($recentAlerts as $a):
                $up = ((float)$a['change_pct']) > 0; ?>
            <li class="px-5 py-3 flex items-start gap-3">
                <span class="w-9 h-9 rounded-xl <?= $up ? 'bg-red-500/10 text-red-400' : 'bg-emerald-500/10 text-emerald-400' ?> flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid <?= $up ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' ?>"></i>
                </span>
                <div class="flex-1">
                    <p class="text-slate-200 text-sm"><?= htmlspecialchars((string)$a['message']) ?></p>
                    <p class="text-slate-500 text-[11px] mt-0.5">
                        <i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$a['created_at']))) ?>
                        · threshold <?= number_format((float)$a['threshold_pct'], 2) ?>%
                    </p>
                </div>
                <span class="<?= $up ? 'text-red-400' : 'text-emerald-400' ?> font-semibold text-sm whitespace-nowrap">
                    <?= ((float)$a['change_pct']) > 0 ? '+' : '' ?><?= number_format((float)$a['change_pct'], 2) ?>%
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
        <h3 class="text-white font-semibold text-sm mb-1"><i class="fa-solid fa-sliders text-amber-400 mr-2"></i>Alert &amp; Freshness Rule</h3>
        <p class="text-slate-500 text-xs mb-4">Applies to new manual entries, CSV imports and API syncs.</p>
        <?php if (!is_admin()): ?>
        <div class="text-slate-400 text-sm space-y-2">
            <p>Alerts are <strong class="<?= $alertCfg['enabled'] ? 'text-emerald-400' : 'text-slate-400' ?>"><?= $alertCfg['enabled'] ? 'enabled' : 'disabled' ?></span></strong> at <strong class="text-slate-200"><?= number_format($alertCfg['pct'], 2) ?>%</strong> (<?= htmlspecialchars($alertCfg['direction']) ?>).</p>
            <p class="text-xs text-slate-500">Only an administrator can change this rule.</p>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_alert_rule">
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" name="alert_enabled" value="1" <?= $alertCfg['enabled'] ? 'checked' : '' ?> class="accent-amber-500">
                Enable price alerts
            </label>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Threshold %</label>
                    <input type="number" name="alert_pct" step="0.01" min="0.1" max="100" value="<?= number_format($alertCfg['pct'], 2, '.', '') ?>"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Direction</label>
                    <select name="alert_direction" class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500">
                        <option value="both" <?= $alertCfg['direction'] === 'both' ? 'selected' : '' ?>>Rise &amp; fall</option>
                        <option value="up"   <?= $alertCfg['direction'] === 'up'   ? 'selected' : '' ?>>Rise only</option>
                        <option value="down" <?= $alertCfg['direction'] === 'down' ? 'selected' : '' ?>>Fall only</option>
                    </select>
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Stale after (days)</label>
                    <input type="number" name="stale_days" min="1" max="60" value="<?= $staleDays ?>"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="text-slate-400 text-xs block mb-1">Default city</label>
                    <input type="text" name="default_city" maxlength="80" value="<?= htmlspecialchars($defaultCity) ?>" list="cityList"
                           class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:border-amber-500">
                </div>
            </div>
            <button class="w-full px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold transition">
                <i class="fa-solid fa-floppy-disk mr-1.5"></i>Save settings
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<datalist id="cityList">
    <?php foreach (array_keys($cities) as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
</datalist>

<!-- MANUAL ENTRY -->
<?php if (is_admin()): ?>
<div id="manualEntry" class="bg-slate-800 border border-slate-700 rounded-2xl p-5">
    <h3 class="text-white font-semibold text-sm mb-1"><i class="fa-solid fa-pen-to-square text-cyan-400 mr-2"></i>Record a Market Price</h3>
    <p class="text-slate-500 text-xs mb-4">Manual observation. For bulk data use <a href="market_import.php" class="text-amber-400">CSV import</a> or a configured source.</p>
    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_price">
        <div>
            <label class="text-slate-400 text-xs block mb-1">Fuel Type <span class="text-red-400">*</span></label>
            <select name="fuel_type" required class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
                <?php foreach ($fuelTypes as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">City <span class="text-red-400">*</span></label>
            <input type="text" name="city" required maxlength="80" list="cityList" value="<?= htmlspecialchars($city !== '' ? $city : $defaultCity) ?>"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="e.g. Beirut">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Price / Liter (USD) <span class="text-red-400">*</span></label>
            <input type="number" name="price_per_liter" step="0.01" min="0.01" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="0.00">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Price Date <span class="text-red-400">*</span></label>
            <input type="date" name="price_date" required value="<?= date('Y-m-d') ?>"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Source</label>
            <input type="text" name="source" maxlength="80" value="Manual"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="e.g. Ministry bulletin">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Source Reference</label>
            <input type="text" name="source_ref" maxlength="255"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500" placeholder="URL / bulletin no.">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Region (optional)</label>
            <input type="text" name="region" maxlength="80"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div>
            <label class="text-slate-400 text-xs block mb-1">Notes (optional)</label>
            <input type="text" name="notes" maxlength="255"
                   class="w-full bg-slate-700 border border-slate-600 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500">
        </div>
        <div class="sm:col-span-2 lg:col-span-4">
            <button class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold transition">
                <i class="fa-solid fa-plus mr-1.5"></i>Save Market Price
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- HISTORY -->
<div class="bg-slate-800 border border-slate-700 rounded-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-white font-semibold text-sm"><i class="fa-solid fa-clock-rotate-left text-amber-400 mr-2"></i>Price History</h3>
            <p class="text-slate-500 text-xs mt-0.5">Historical observations are retained for reporting and comparison.</p>
        </div>
        <span class="text-slate-500 text-xs">showing <?= count($history) ?> of <?= $totalObs ?></span>
    </div>
    <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider sticky top-0">
                <tr>
                    <th class="text-left px-5 py-3">Date</th>
                    <th class="text-left px-5 py-3">Fuel</th>
                    <th class="text-left px-5 py-3">City</th>
                    <th class="text-right px-5 py-3">Price</th>
                    <th class="text-left px-5 py-3">Source</th>
                    <th class="text-left px-5 py-3">Recorded by</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                <?php if (empty($history)): ?>
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">No history for this filter.</td></tr>
                <?php else: foreach ($history as $h): ?>
                <tr class="hover:bg-slate-700/30 transition">
                    <td class="px-5 py-2.5 text-slate-300 whitespace-nowrap"><?= htmlspecialchars(date('M j, Y', strtotime((string)$h['price_date']))) ?></td>
                    <td class="px-5 py-2.5 text-white"><?= htmlspecialchars((string)$h['fuel_type']) ?></td>
                    <td class="px-5 py-2.5 text-slate-300"><?= htmlspecialchars((string)$h['city']) ?></td>
                    <td class="px-5 py-2.5 text-right text-white font-medium">$<?= money($h['price_per_liter']) ?></td>
                    <td class="px-5 py-2.5 text-slate-400 text-xs"><?= htmlspecialchars((string)$h['source']) ?> <?= $demoBadge($h) ?></td>
                    <td class="px-5 py-2.5 text-slate-500 text-xs"><?= htmlspecialchars((string)($h['recorded_by'] ?? '—')) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($labels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var payload = <?= $chartPayload ?>;
    var canvas  = document.getElementById('marketChart');
    var fallback = document.getElementById('chartFallback');
    if (!canvas) return;

    if (typeof Chart === 'undefined') {
        canvas.style.display = 'none';
        if (fallback) fallback.classList.remove('hidden');
        return;
    }

    var datasets = payload.datasets.map(function (ds) {
        return {
            label: ds.label,
            data: ds.data,
            borderColor: ds.color,
            backgroundColor: ds.color + '22',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 0,
            pointHoverRadius: 4,
            spanGaps: true,
            fill: payload.datasets.length === 1
        };
    });

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels: payload.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: { color: '#94A3B8', usePointStyle: true, boxWidth: 8, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ': $' + (ctx.parsed.y === null ? '—' : ctx.parsed.y.toFixed(2));
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#64748B', maxTicksLimit: 10, font: { size: 10 } },
                    grid: { color: 'rgba(148,163,184,0.08)' }
                },
                y: {
                    ticks: {
                        color: '#64748B', font: { size: 10 },
                        callback: function (v) { return '$' + Number(v).toFixed(2); }
                    },
                    grid: { color: 'rgba(148,163,184,0.08)' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
