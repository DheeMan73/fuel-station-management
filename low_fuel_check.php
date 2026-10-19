<?php
/**
 * Low Fuel Stock — live JSON status feed.
 *
 * Polled by the dashboard / tank pages to keep the alert badge fresh
 * without a full reload. Any signed-in user may read it.
 *
 * Response:
 *   { "count": 2, "threshold": 15, "tanks": [ { id, fuel_type, current, capacity, fill_pct } ] }
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$low = low_fuel_tanks($pdo);

$payload = [
    'count'     => count($low),
    'threshold' => (int)round(LOW_FUEL_THRESHOLD * 100),
    'tanks'     => [],
];

foreach ($low as $t) {
    $payload['tanks'][] = [
        'id'        => (int)$t['id'],
        'fuel_type' => $t['fuel_type'],
        'current'   => (float)$t['current_stock_liters'],
        'capacity'  => (float)$t['tank_capacity_liters'],
        'fill_pct'  => $t['fill_pct'],
    ];
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
