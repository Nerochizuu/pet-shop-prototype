<?php
/**
 * update_boarding.php
 *
 * Admin updates a boarding reservation's status:
 *   pending → confirmed → checked_in → checked_out
 *   or cancelled at any point.
 *
 * When status is set to 'checked_out', automatically records
 * an income transaction using the reservation's estimated_total
 * (same auto-sale pattern as appointments and shop orders).
 *
 * Expected POST fields:
 *   reservation_id   (int, required)
 *   status           (string, required) pending|confirmed|checked_in|checked_out|cancelled
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

$reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
$newStatus     = trim($_POST['status'] ?? '');

if ($reservationId <= 0) respond(false, 'Invalid reservation ID.');

$validStatuses = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];
if (!in_array($newStatus, $validStatuses, true)) respond(false, 'Invalid status value.');

$conn = getDbConnection();

// Fetch current reservation
$current = $conn->prepare(
    "SELECT status, estimated_total, first_name, last_name, pet_name, room_type
     FROM boarding_reservations WHERE reservation_id = ?"
);
$current->bind_param('i', $reservationId);
$current->execute();
$result = $current->get_result();
if ($result->num_rows === 0) {
    $current->close(); $conn->close();
    respond(false, 'Reservation not found.');
}
$reservation   = $result->fetch_assoc();
$currentStatus = $reservation['status'];
$current->close();

// Update status
$stmt = $conn->prepare("UPDATE boarding_reservations SET status = ? WHERE reservation_id = ?");
$stmt->bind_param('si', $newStatus, $reservationId);
$stmt->execute();
$stmt->close();

// Auto-record sale when checked out (and wasn't already checked out)
if ($newStatus === 'checked_out' && $currentStatus !== 'checked_out') {
    $today       = date('Y-m-d');
    $amount      = (float)$reservation['estimated_total'];
    $description = 'Boarding — ' . $reservation['room_type'] . ' for ' . $reservation['pet_name']
                 . ' (' . $reservation['first_name'] . ' ' . $reservation['last_name'] . ')';

    $txStmt = $conn->prepare(
        "INSERT INTO transactions
            (transaction_type, category, description, amount, payment_method,
             recorded_by, transaction_date)
         VALUES ('income', 'boarding', ?, ?, 'cash', 'admin', ?)"
    );
    $txStmt->bind_param('sds', $description, $amount, $today);
    $txStmt->execute();
    $txStmt->close();
}

$conn->close();

respond(true, "Reservation #{$reservationId} updated to {$newStatus}.", ['status' => $newStatus]);
