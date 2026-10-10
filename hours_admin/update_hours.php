<?php
/**
 * update_hours.php
 *
 * Admin updates the open/close time (or closed status) for a
 * single day of the week. The customer-facing home page reads
 * this same operating_hours table via get_hours.php, so changes
 * here take effect immediately.
 *
 * Expected POST fields:
 *   day_of_week   (int, required)  0=Sunday ... 6=Saturday
 *   open_time     (string, required) HH:MM (24-hour)
 *   close_time    (string, required) HH:MM (24-hour)
 *   is_closed     (int, required)  1 or 0
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

$dayOfWeek = isset($_POST['day_of_week']) ? (int)$_POST['day_of_week'] : -1;
$openTime  = trim($_POST['open_time']  ?? '');
$closeTime = trim($_POST['close_time'] ?? '');
$isClosed  = isset($_POST['is_closed']) ? (int)$_POST['is_closed'] : 0;

if ($dayOfWeek < 0 || $dayOfWeek > 6) respond(false, 'Invalid day of week.');

// Only validate times if the day isn't marked closed
if (!$isClosed) {
    if ($openTime === '' || $closeTime === '') {
        respond(false, 'Open and close times are required unless the day is marked closed.');
    }
    if (strtotime($openTime) >= strtotime($closeTime)) {
        respond(false, 'Close time must be after open time.');
    }
}

// Fall back to sensible defaults if closed (keeps the row consistent)
if ($openTime === '')  $openTime  = '08:00';
if ($closeTime === '') $closeTime = '18:00';

$conn = getDbConnection();

$stmt = $conn->prepare(
    "UPDATE operating_hours SET open_time = ?, close_time = ?, is_closed = ?
     WHERE day_of_week = ?"
);
$stmt->bind_param('ssii', $openTime, $closeTime, $isClosed, $dayOfWeek);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    respond(true, 'Hours updated.');
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to update hours: ' . $err);
}
