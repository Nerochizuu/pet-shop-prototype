<?php
/**
 * get_inventory.php
 *
 * Returns the current inventory list as JSON, including
 * computed stock status (good / low / out_of_stock).
 * Used by inventory.html to populate the table dynamically.
 *
 * Optional GET params:
 *   status   - filter by 'low', 'out_of_stock', or 'all' (default)
 *   search   - filter by item name (partial match)
 */

header('Content-Type: application/json');
require_once '../db_connect.php';
$conn = getDbConnection();

$statusFilter = $_GET['status'] ?? 'all';
$search       = trim($_GET['search'] ?? '');

$sql = "SELECT
            i.item_id,
            i.barcode,
            i.item_name,
            c.category_name,
            i.unit,
            i.unit_cost,
            i.current_stock,
            i.reorder_level,
            (i.unit_cost * i.current_stock) AS stock_value
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.category_id
        WHERE i.is_active = 1";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= " AND i.item_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$sql .= " ORDER BY i.item_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$summary = [
    'total_items'    => 0,
    'low_stock'      => 0,
    'out_of_stock'   => 0,
    'total_value'    => 0.0
];

while ($row = $result->fetch_assoc()) {
    $stock = (int)$row['current_stock'];
    $reorder = (int)$row['reorder_level'];

    if ($stock <= 0) {
        $status = 'out_of_stock';
        $summary['out_of_stock']++;
    } elseif ($stock <= $reorder) {
        $status = 'low';
        $summary['low_stock']++;
    } else {
        $status = 'good';
    }

    // Apply status filter if requested
    if ($statusFilter !== 'all' && $statusFilter !== $status) {
        continue;
    }

    $summary['total_items']++;
    $summary['total_value'] += (float)$row['stock_value'];

    $items[] = [
        'item_id'       => (int)$row['item_id'],
        'barcode'       => $row['barcode'],
        'item_name'     => $row['item_name'],
        'category'      => $row['category_name'] ?? 'Uncategorized',
        'unit'          => $row['unit'],
        'unit_cost'     => (float)$row['unit_cost'],
        'current_stock' => $stock,
        'reorder_level' => $reorder,
        'stock_status'  => $status,
        'stock_value'   => (float)$row['stock_value']
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'items'   => $items,
    'summary' => $summary
]);