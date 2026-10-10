<?php
/**
 * add_package.php
 *
 * Admin creates a new package. This inserts directly into
 * the `services` table — the same table used by appointment
 * booking — so a new package immediately becomes bookable.
 *
 * Expected POST fields:
 *   service_name    (string, required)
 *   description     (string, optional)
 *   features        (string, optional) — newline-separated;
 *                    converted to a JSON array server-side.
 *                    Last line is treated as the duration label.
 *   price           (decimal, required)
 *   price_unit      (string, optional, default '/ session')
 *   badge_label     (string, optional)
 *   badge_color     (string, optional) teal|amber|pink|blue
 *   icon_emoji      (string, optional)
 *   icon_bg_color   (string, optional)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$serviceName  = trim($_POST['service_name']  ?? '');
$description  = trim($_POST['description']  ?? '');
$featuresRaw  = trim($_POST['features']     ?? '');
$price        = isset($_POST['price']) ? (float)$_POST['price'] : 0;
$priceUnit    = trim($_POST['price_unit']    ?? '') ?: '/ session';
$badgeLabel   = trim($_POST['badge_label']   ?? '');
$badgeColor   = trim($_POST['badge_color']   ?? 'teal');
$iconEmoji    = trim($_POST['icon_emoji']    ?? '') ?: '🐾';
$iconBgColor  = trim($_POST['icon_bg_color']  ?? '') ?: '#E1F5EE';

$errors = [];
if ($serviceName === '') $errors[] = 'Package name is required.';
if ($price <= 0)         $errors[] = 'Price must be greater than zero.';
$validColors = ['teal', 'amber', 'pink', 'blue'];
if (!in_array($badgeColor, $validColors, true)) $badgeColor = 'teal';
if (!empty($errors)) respond(false, implode(' ', $errors));

// Convert newline-separated inclusions into a JSON array
$features = array_values(array_filter(array_map('trim', explode("\n", $featuresRaw)), fn($l) => $l !== ''));
$featuresJson = json_encode($features);

$conn = getDbConnection();

$stmt = $conn->prepare(
    "INSERT INTO services
        (service_name, description, features, price, price_unit,
         badge_label, badge_color, icon_emoji, icon_bg_color, is_active, display_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1,
        (SELECT next_order FROM (SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM services) AS t))"
);
$stmt->bind_param(
    'sssdsssss',
    $serviceName,
    $description,
    $featuresJson,
    $price,
    $priceUnit,
    $badgeLabel,
    $badgeColor,
    $iconEmoji,
    $iconBgColor
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    respond(true, "\"{$serviceName}\" package created successfully.", ['service_id' => $newId]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to create package: ' . $err);
}
