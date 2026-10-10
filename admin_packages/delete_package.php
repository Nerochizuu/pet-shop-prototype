<?php
/**
 * delete_package.php
 *
 * Permanently deletes a package — but only if no existing
 * appointments reference it. If any do, deletion is blocked
 * and the admin is told to deactivate it instead, since
 * deleting would break the historical record of those bookings.
 *
 * Expected POST fields:
 *   service_id   (int, required)
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
if ($serviceId <= 0) respond(false, 'Invalid package ID.');

$conn = getDbConnection();

// Check for existing appointments referencing this package
$check = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE service_id = ?");
$check->bind_param('i', $serviceId);
$check->execute();
$count = (int)$check->get_result()->fetch_assoc()['cnt'];
$check->close();

if ($count > 0) {
    $conn->close();
    respond(false, "This package can't be deleted — it's used in {$count} existing appointment(s). Deactivate it instead to hide it from customers while keeping booking history intact.", [
        'blocked'          => true,
        'appointment_count' => $count
    ]);
}

$stmt = $conn->prepare("DELETE FROM services WHERE service_id = ?");
$stmt->bind_param('i', $serviceId);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    respond(true, 'Package deleted successfully.');
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to delete package: ' . $err);
}
