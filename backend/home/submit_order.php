<?php
/**
 * submit_order.php
 *
 * Saves a customer cart order to the database.
 * Called when customer clicks "Proceed to Checkout".
 *
 * Expected POST fields:
 *   customer_name   (string, required)
 *   customer_email  (string, required)
 *   customer_phone  (string, optional)
 *   notes           (string, optional)
 *   items           (JSON string, required)
 *                   Array of: [{id, name, price, qty}]
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

$customerName  = trim($_POST['customer_name']  ?? '');
$customerEmail = trim($_POST['customer_email'] ?? '');
$customerPhone = trim($_POST['customer_phone'] ?? '');
$notes         = trim($_POST['notes']          ?? '');
$itemsJson     = trim($_POST['items']          ?? '');

// Validate
$errors = [];
if ($customerName === '')  $errors[] = 'Your name is required.';
if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
}
if ($itemsJson === '') $errors[] = 'No items in cart.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$items = json_decode($itemsJson, true);
if (!$items || !is_array($items) || count($items) === 0) {
    respond(false, 'Cart is empty or invalid.');
}

$deliveryFee = 80.00;
$subtotal    = 0;

foreach ($items as $item) {
    $subtotal += (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
}

$total = $subtotal + $deliveryFee;

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // Insert order
    $orderStmt = $conn->prepare(
        "INSERT INTO orders
            (customer_name, customer_email, customer_phone, subtotal, delivery_fee, total, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $orderStmt->bind_param(
        'sssddds',
        $customerName,
        $customerEmail,
        $customerPhone,
        $subtotal,
        $deliveryFee,
        $total,
        $notes
    );
    $orderStmt->execute();
    $orderId = $orderStmt->insert_id;
    $orderStmt->close();

    // Insert order items
    $itemStmt = $conn->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $productId   = (int)$item['id'];
        $productName = trim($item['name'] ?? '');
        $unitPrice   = (float)$item['price'];
        $qty         = (int)$item['qty'];
        $itemSubtotal = $unitPrice * $qty;

        $itemStmt->bind_param(
            'iisdid',
            $orderId,
            $productId,
            $productName,
            $unitPrice,
            $qty,
            $itemSubtotal
        );
        $itemStmt->execute();
    }
    $itemStmt->close();

    $conn->commit();

    respond(true, 'Order placed successfully! We\'ll contact you shortly to confirm your pickup schedule. 🐾', [
        'order_id' => $orderId,
        'total'    => $total
    ]);

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Failed to place order: ' . $e->getMessage());
} finally {
    $conn->close();
}