<?php
/**
 * update_order.php
 *
 * Admin updates an order's status:
 *   pending → confirmed → ready → completed
 *   or cancelled at any point.
 *
 * When status is set to 'completed', automatically records
 * an income transaction (same pattern as appointment completion).
 *
 * Expected POST fields:
 *   order_id   (int, required)
 *   status     (string, required) pending|confirmed|ready|completed|cancelled
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$orderId   = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newStatus = trim($_POST['status']     ?? '');

if ($orderId <= 0) respond(false, 'Invalid order ID.');

$validStatuses = ['pending', 'confirmed', 'ready', 'completed', 'cancelled'];
if (!in_array($newStatus, $validStatuses, true)) respond(false, 'Invalid status value.');

$conn = getDbConnection();

// Fetch current order
$current = $conn->prepare("SELECT status, total, customer_name FROM orders WHERE order_id = ?");
$current->bind_param('i', $orderId);
$current->execute();
$result = $current->get_result();
if ($result->num_rows === 0) {
    $current->close(); $conn->close();
    respond(false, 'Order not found.');
}
$order = $result->fetch_assoc();
$currentStatus = $order['status'];
$current->close();

// Update status
$stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
$stmt->bind_param('si', $newStatus, $orderId);
$stmt->execute();
$stmt->close();

// Auto-record sale when marked completed (and wasn't already completed)
if ($newStatus === 'completed' && $currentStatus !== 'completed') {
    $today       = date('Y-m-d');
    $amount      = (float)$order['total'];
    $description = 'Shop order #' . $orderId . ' — ' . $order['customer_name'];

    $txStmt = $conn->prepare(
        "INSERT INTO transactions
            (transaction_type, category, description, amount, payment_method,
             recorded_by, transaction_date)
         VALUES ('income', 'product', ?, ?, 'cash', 'admin', ?)"
    );
    $txStmt->bind_param('sds', $description, $amount, $today);
    $txStmt->execute();
    $txStmt->close();
}

$conn->close();

respond(true, "Order #{$orderId} updated to {$newStatus}.", ['status' => $newStatus]);