<?php
/**
 * update_customer.php
 *
 * Handles admin actions on a customer record:
 *   - Activate / deactivate account
 *   - Update contact info
 *
 * Expected POST fields:
 *   customer_id   (int, required)
 *   is_active     (int, optional) - 1 or 0
 *   first_name    (string, optional)
 *   last_name     (string, optional)
 *   contact_number (string, optional)
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
if ($customerId <= 0) respond(false, 'Invalid customer ID.');

$conn = getDbConnection();

// Build a dynamic UPDATE based on what was sent
$fields = [];
$params = [];
$types  = '';

if (isset($_POST['is_active'])) {
    $fields[] = 'is_active = ?';
    $params[] = (int)$_POST['is_active'];
    $types   .= 'i';
}
if (!empty($_POST['first_name'])) {
    $fields[] = 'first_name = ?';
    $params[] = trim($_POST['first_name']);
    $types   .= 's';
}
if (!empty($_POST['last_name'])) {
    $fields[] = 'last_name = ?';
    $params[] = trim($_POST['last_name']);
    $types   .= 's';
}
if (!empty($_POST['contact_number'])) {
    $fields[] = 'contact_number = ?';
    $params[] = trim($_POST['contact_number']);
    $types   .= 's';
}

if (empty($fields)) {
    $conn->close();
    respond(false, 'Nothing to update.');
}

$params[] = $customerId;
$types   .= 'i';

$stmt = $conn->prepare(
    "UPDATE customers SET " . implode(', ', $fields) . " WHERE customer_id = ?"
);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    respond(true, 'Customer updated successfully.');
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Update failed: ' . $err);
}