<?php
/**
 * add_item.php
 *
 * Adds a new inventory item. Used when a barcode is scanned
 * Expected POST fields:
 *   barcode        (string, required)
 *   item_name      (string, required)
 *   category_id    (int, optional)
 *   unit           (string, optional, default 'pc')
 *   unit_cost      (decimal, optional, default 0)
 *   current_stock  (int, optional, default 0)
 *   reorder_level  (int, optional, default 5)
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

$barcode      = trim($_POST['barcode'] ?? '');
$itemName     = trim($_POST['item_name'] ?? '');
$categoryId   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
$unit         = trim($_POST['unit'] ?? 'pc');
$unitCost     = isset($_POST['unit_cost']) ? (float)$_POST['unit_cost'] : 0.00;
$currentStock = isset($_POST['current_stock']) ? (int)$_POST['current_stock'] : 0;
$reorderLevel = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 5;

if ($barcode === '' || $itemName === '') {
    respond(false, 'Barcode and item name are required.');
}

$conn = getDbConnection();

// Check for duplicate barcode first
$check = $conn->prepare("SELECT item_id FROM inventory_items WHERE barcode = ?");
$check->bind_param('s', $barcode);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();
    respond(false, 'This barcode already exists in the system.');
}
$check->close();

$stmt = $conn->prepare(
    "INSERT INTO inventory_items
        (barcode, item_name, category_id, unit, unit_cost, current_stock, reorder_level)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssisdii',
    $barcode,
    $itemName,
    $categoryId,
    $unit,
    $unitCost,
    $currentStock,
    $reorderLevel
);

if ($stmt->execute()) {
    $newItemId = $stmt->insert_id;
    $stmt->close();

    // Log the initial stock as a transaction for audit history
    if ($currentStock > 0) {
        $log = $conn->prepare(
            "INSERT INTO inventory_transactions
                (item_id, transaction_type, quantity_change, stock_before, stock_after, source, performed_by, notes)
             VALUES (?, 'initial', ?, 0, ?, 'manual', 'admin', 'Initial stock on item creation')"
        );
        $log->bind_param('iii', $newItemId, $currentStock, $currentStock);
        $log->execute();
        $log->close();
    }

    $conn->close();
    respond(true, "\"{$itemName}\" added to inventory.", ['item_id' => $newItemId]);
} else {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to add item: ' . $error);
}