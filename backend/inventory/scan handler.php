<?php
/**
 * scan_handler.php
 *
 * Receives a barcode (scanned via USB HID scanner) and an action
 * (restock or deduct), then updates inventory stock in real time.
 *
 * USB HID scanners act like a keyboard: they "type" the barcode
 * digits into whatever input field is focused, then send an
 * Enter keypress. The frontend JS listens for that Enter key
 * and POSTs the captured barcode here via fetch/AJAX.
 *
 * Expected POST fields:
 *   barcode        (string, required)
 *   action         (string, required) - 'restock' or 'deduct'
 *   quantity       (int, optional, default 1)
 *   performed_by   (string, optional) - logged-in admin/staff name
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
$action       = trim($_POST['action'] ?? '');
$quantity     = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$performedBy  = trim($_POST['performed_by'] ?? 'admin');

if ($barcode === '') {
    respond(false, 'No barcode received.');
}

if (!in_array($action, ['restock', 'deduct'], true)) {
    respond(false, 'Invalid action. Must be "restock" or "deduct".');
}

if ($quantity <= 0) {
    respond(false, 'Quantity must be greater than zero.');
}

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // 1. Look up the item by barcode (lock the row for update)
    $stmt = $conn->prepare(
        "SELECT item_id, item_name, current_stock, reorder_level
         FROM inventory_items
         WHERE barcode = ? AND is_active = 1
         FOR UPDATE"
    );
    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $conn->rollback();
        respond(false, 'Barcode not recognized. This item may need to be added first.', [
            'barcode' => $barcode,
            'unknown' => true
        ]);
    }

    $item = $result->fetch_assoc();
    $stmt->close();

    $stockBefore = (int)$item['current_stock'];

    // 2. Calculate new stock level
    if ($action === 'restock') {
        $stockAfter = $stockBefore + $quantity;
        $quantityChange = $quantity;
        $transactionType = 'restock';
    } else { // deduct
        $stockAfter = $stockBefore - $quantity;
        if ($stockAfter < 0) {
            $conn->rollback();
            respond(false, "Not enough stock. Only {$stockBefore} unit(s) of \"{$item['item_name']}\" remaining.", [
                'current_stock' => $stockBefore
            ]);
        }
        $quantityChange = -$quantity;
        $transactionType = 'deduct';
    }

    // 3. Update the item's stock
    $update = $conn->prepare(
        "UPDATE inventory_items
         SET current_stock = ?
         WHERE item_id = ?"
    );
    $update->bind_param('ii', $stockAfter, $item['item_id']);
    $update->execute();
    $update->close();

    // 4. Log the transaction (audit trail)
    $log = $conn->prepare(
        "INSERT INTO inventory_transactions
            (item_id, transaction_type, quantity_change, stock_before, stock_after, source, performed_by)
         VALUES (?, ?, ?, ?, ?, 'barcode_scan', ?)"
    );
    $log->bind_param(
        'isiiis',
        $item['item_id'],
        $transactionType,
        $quantityChange,
        $stockBefore,
        $stockAfter,
        $performedBy
    );
    $log->execute();
    $log->close();

    $conn->commit();

    // 5. Determine stock status for the frontend to display
    $status = 'good';
    if ($stockAfter <= 0) {
        $status = 'out_of_stock';
    } elseif ($stockAfter <= (int)$item['reorder_level']) {
        $status = 'low';
    }

    respond(true, "{$item['item_name']} updated successfully.", [
        'item_id'       => $item['item_id'],
        'item_name'     => $item['item_name'],
        'action'        => $action,
        'quantity'      => $quantity,
        'stock_before'  => $stockBefore,
        'stock_after'   => $stockAfter,
        'reorder_level' => (int)$item['reorder_level'],
        'stock_status'  => $status
    ]);

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'An error occurred while updating inventory: ' . $e->getMessage());
} finally {
    $conn->close();
}