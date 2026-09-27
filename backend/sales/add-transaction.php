<?php
/**
 * add_transaction.php
 *
 * Manually records an income or expense transaction.
 * Used by admin from the Sales & Finance page for:
 *   - Standalone product sales not tied to an appointment
 *   - Manual expenses: staff wages, utilities, etc.
 *   - Inventory restock expenses (called automatically
 *     from scan_handler.php when action = 'restock')
 *
 * Expected POST fields:
 *   transaction_type   (string, required) 'income' | 'expense'
 *   category           (string, required)
 *   description        (string, required)
 *   amount             (decimal, required)
 *   transaction_date   (date, required)  YYYY-MM-DD
 *   payment_method     (string, optional)
 *   appointment_id     (int, optional)
 *   inventory_tx_id    (int, optional)
 *   notes              (string, optional)
 *   recorded_by        (string, optional, default 'admin')
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

$type          = trim($_POST['transaction_type'] ?? '');
$category      = trim($_POST['category'] ?? '');
$description   = trim($_POST['description'] ?? '');
$amount        = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$txDate        = trim($_POST['transaction_date'] ?? date('Y-m-d'));
$paymentMethod = trim($_POST['payment_method'] ?? '');
$appointmentId = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
$inventoryTxId = !empty($_POST['inventory_tx_id']) ? (int)$_POST['inventory_tx_id'] : null;
$notes         = trim($_POST['notes'] ?? '');
$recordedBy    = trim($_POST['recorded_by'] ?? 'admin');

// Validate
$errors = [];
if (!in_array($type, ['income', 'expense'], true)) $errors[] = 'Transaction type must be income or expense.';
if ($category === '')    $errors[] = 'Category is required.';
if ($description === '') $errors[] = 'Description is required.';
if ($amount <= 0)        $errors[] = 'Amount must be greater than zero.';
if ($txDate === '')      $errors[] = 'Transaction date is required.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$validMethods = ['cash', 'gcash', 'maya', 'card', 'other', ''];
if (!in_array($paymentMethod, $validMethods, true)) $paymentMethod = 'other';
$paymentMethod = $paymentMethod === '' ? null : $paymentMethod;

$conn = getDbConnection();

$stmt = $conn->prepare(
    "INSERT INTO transactions
        (transaction_type, category, description, amount, payment_method,
         appointment_id, inventory_tx_id, recorded_by, transaction_date, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'sssdsiiiss',
    $type,
    $category,
    $description,
    $amount,
    $paymentMethod,
    $appointmentId,
    $inventoryTxId,
    $recordedBy,
    $txDate,
    $notes
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    respond(true, 'Transaction recorded successfully.', ['transaction_id' => $newId]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to record transaction: ' . $err);
}