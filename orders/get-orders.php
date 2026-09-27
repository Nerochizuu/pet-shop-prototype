<?php
/**
 * get_orders.php
 *
 * Returns all customer shop orders for the admin orders.html page.
 * Each order includes its line items.
 *
 * Optional GET params:
 *   status  - 'pending' | 'confirmed' | 'ready' | 'completed' | 'cancelled' | 'all' (default)
 *   search  - partial match on customer name or email
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            order_id, customer_name, customer_email, customer_phone,
            subtotal, delivery_fee, total, status, notes, created_at
        FROM orders
        WHERE 1=1";

$params = [];
$types  = '';

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($search !== '') {
    $sql .= " AND (customer_name LIKE ? OR customer_email LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
$orderIds = [];
while ($row = $result->fetch_assoc()) {
    $orders[(int)$row['order_id']] = [
        'order_id'       => (int)$row['order_id'],
        'customer_name'  => $row['customer_name'],
        'customer_email' => $row['customer_email'],
        'customer_phone' => $row['customer_phone'],
        'subtotal'       => (float)$row['subtotal'],
        'delivery_fee'   => (float)$row['delivery_fee'],
        'total'          => (float)$row['total'],
        'status'         => $row['status'],
        'notes'          => $row['notes'],
        'created_at'     => $row['created_at'],
        'items'          => []
    ];
    $orderIds[] = (int)$row['order_id'];
}
$stmt->close();

// Fetch items for all orders in one query
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types2 = str_repeat('i', count($orderIds));

    $itemStmt = $conn->prepare(
        "SELECT order_id, product_name, unit_price, quantity, subtotal
         FROM order_items
         WHERE order_id IN ($placeholders)"
    );
    $itemStmt->bind_param($types2, ...$orderIds);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();

    while ($item = $itemResult->fetch_assoc()) {
        $oid = (int)$item['order_id'];
        if (isset($orders[$oid])) {
            $orders[$oid]['items'][] = [
                'product_name' => $item['product_name'],
                'unit_price'   => (float)$item['unit_price'],
                'quantity'     => (int)$item['quantity'],
                'subtotal'     => (float)$item['subtotal']
            ];
        }
    }
    $itemStmt->close();
}

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')   AS pending,
        SUM(status = 'confirmed') AS confirmed,
        SUM(status = 'ready')     AS ready,
        SUM(status = 'completed') AS completed,
        SUM(status = 'cancelled') AS cancelled,
        SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS total_revenue
     FROM orders"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success' => true,
    'orders'  => array_values($orders),
    'summary' => [
        'total'         => (int)$summary['total'],
        'pending'       => (int)$summary['pending'],
        'confirmed'     => (int)$summary['confirmed'],
        'ready'         => (int)$summary['ready'],
        'completed'     => (int)$summary['completed'],
        'cancelled'     => (int)$summary['cancelled'],
        'total_revenue' => (float)$summary['total_revenue']
    ]
]);