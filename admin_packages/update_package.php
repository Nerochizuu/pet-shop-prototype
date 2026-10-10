<?php
/**
 * update_package.php
 *
 * Handles two admin actions on an existing package:
 *   - update_info: edit name, description, features, price, etc.
 *   - toggle_status: flip active/inactive (hides/shows it on
 *     the customer packages page without deleting it)
 *
 * Expected POST fields:
 *   service_id   (int, required)
 *   action       (string, required) 'update_info' | 'toggle_status'
 *
 *   For update_info, all the same fields as add_package.php.
 *   For toggle_status:
 *     is_active  (int, required) 1 or 0
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

$serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$action    = trim($_POST['action']      ?? '');

if ($serviceId <= 0) respond(false, 'Invalid package ID.');

$conn = getDbConnection();

if ($action === 'toggle_status') {
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
    $stmt = $conn->prepare("UPDATE services SET is_active = ? WHERE service_id = ?");
    $stmt->bind_param('ii', $isActive, $serviceId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    respond(true, $isActive ? 'Package activated.' : 'Package deactivated.');

} elseif ($action === 'update_info') {
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
    if (!empty($errors)) {
        $conn->close();
        respond(false, implode(' ', $errors));
    }

    $features = array_values(array_filter(array_map('trim', explode("\n", $featuresRaw)), fn($l) => $l !== ''));
    $featuresJson = json_encode($features);

    $stmt = $conn->prepare(
        "UPDATE services SET
            service_name = ?, description = ?, features = ?, price = ?, price_unit = ?,
            badge_label = ?, badge_color = ?, icon_emoji = ?, icon_bg_color = ?
         WHERE service_id = ?"
    );
    $stmt->bind_param(
        'sssdsssssi',
        $serviceName,
        $description,
        $featuresJson,
        $price,
        $priceUnit,
        $badgeLabel,
        $badgeColor,
        $iconEmoji,
        $iconBgColor,
        $serviceId
    );

    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        respond(true, "\"{$serviceName}\" updated successfully.");
    } else {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        respond(false, 'Failed to update package: ' . $err);
    }

} else {
    $conn->close();
    respond(false, 'Invalid action.');
}
