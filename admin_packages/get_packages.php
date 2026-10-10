<?php
/**
 * get_packages.php
 *
 * Returns all packages (rows in the `services` table) for the
 * admin packages.html page — both active and inactive, since
 * admin needs to see and manage everything.
 *
 * Also returns computed metrics: total count, active count,
 * the most-booked package (by appointment count), and the
 * active price range.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn = getDbConnection();

$result = $conn->query(
    "SELECT service_id, service_name, description, features, price, price_unit,
            badge_label, badge_color, icon_emoji, icon_bg_color, is_active, display_order
     FROM services
     ORDER BY display_order ASC, service_id ASC"
);

$packages = [];
while ($row = $result->fetch_assoc()) {
    $features = json_decode($row['features'] ?? '[]', true);
    if (!is_array($features)) $features = [];

    $packages[] = [
        'service_id'    => (int)$row['service_id'],
        'name'          => $row['service_name'],
        'description'   => $row['description'],
        'features'      => $features,
        'price'         => (float)$row['price'],
        'price_unit'    => $row['price_unit'],
        'badge_label'   => $row['badge_label'],
        'badge_color'   => $row['badge_color'],
        'icon_emoji'    => $row['icon_emoji'],
        'icon_bg_color' => $row['icon_bg_color'],
        'is_active'     => (bool)$row['is_active'],
        'display_order' => (int)$row['display_order']
    ];
}

// ── Metrics ──
$total  = count($packages);
$active = count(array_filter($packages, fn($p) => $p['is_active']));

$activePrices = array_map(fn($p) => $p['price'], array_filter($packages, fn($p) => $p['is_active']));
$priceMin = $activePrices ? min($activePrices) : 0;
$priceMax = $activePrices ? max($activePrices) : 0;

// Most-booked package, by count of appointments referencing it
$mostBookedResult = $conn->query(
    "SELECT s.service_name, COUNT(a.appointment_id) AS booking_count
     FROM services s
     LEFT JOIN appointments a ON a.service_id = s.service_id
     GROUP BY s.service_id
     ORDER BY booking_count DESC
     LIMIT 1"
);
$mostBooked = $mostBookedResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success'  => true,
    'packages' => $packages,
    'summary'  => [
        'total'            => $total,
        'active'            => $active,
        'most_booked_name'  => $mostBooked['service_name'] ?? '—',
        'most_booked_count' => (int)($mostBooked['booking_count'] ?? 0),
        'price_min'         => $priceMin,
        'price_max'         => $priceMax
    ]
]);
