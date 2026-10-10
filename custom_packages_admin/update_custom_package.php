<?php
/**
 * update_custom_package.php
 *
 * Admin updates a custom package order's status:
 *   pending → confirmed → completed
 *   or cancelled at any point.
 *
 * When status is set to 'completed', automatically records
 * an income transaction using the order's total_price
 * (same auto-sale pattern as appointments, orders, boarding,
 * and confinement).
 *
 * Expected POST fields:
 *   order_id   (int, required)
 *   status     (string, required) pending|confirmed|completed|cancelled
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

$orderId   = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newStatus = trim($_POST['status']    ?? '');

if ($orderId <= 0) respond(false, 'Invalid order ID.');

$validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
if (!in_array($newStatus, $validStatuses, true)) respond(false, 'Invalid status value.');

$conn = getDbConnection();

// Fetch current order
$current = $conn->prepare(
    "SELECT status, total_price, first_name, last_name, pet_name
     FROM custom_package_orders WHERE order_id = ?"
);
$current->bind_param('i', $orderId);
$current->execute();
$result = $current->get_result();
if ($result->num_rows === 0) {
    $current->close(); $conn->close();
    respond(false, 'Order not found.');
}
$order         = $result->fetch_assoc();
$currentStatus = $order['status'];
$current->close();

// Update status
$stmt = $conn->prepare("UPDATE custom_package_orders SET status = ? WHERE order_id = ?");
$stmt->bind_param('si', $newStatus, $orderId);
$stmt->execute();
$stmt->close();

// Auto-record sale when completed (and wasn't already completed)
if ($newStatus === 'completed' && $currentStatus !== 'completed') {
    $today       = date('Y-m-d');
    $amount      = (float)$order['total_price'];
    $petLabel    = $order['pet_name'] ? ' for ' . $order['pet_name'] : '';
    $description = 'Custom package' . $petLabel . ' — '
                 . $order['first_name'] . ' ' . $order['last_name'];

    $txStmt = $conn->prepare(
        "INSERT INTO transactions
            (transaction_type, category, description, amount, payment_method,
             recorded_by, transaction_date)
         VALUES ('income', 'grooming', ?, ?, 'cash', 'admin', ?)"
    );
    $txStmt->bind_param('sds', $description, $amount, $today);
    $txStmt->execute();
    $txStmt->close();
}

$conn->close();

respond(true, "Order #{$orderId} updated to {$newStatus}.", ['status' => $newStatus]);
