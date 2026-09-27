<?php
/**
 * get_products.php
 *
 * Returns all active products for the customer home page shop section.
 * Optionally filters by category.
 *
 * GET params:
 *   category - 'grooming' | 'treats' | 'accessories' | 'health' | 'all' (default)
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn     = getDbConnection();
$category = trim($_GET['category'] ?? 'all');

$sql = "SELECT
            product_id, name, category, description,
            price, old_price, emoji, badge, badge_type,
            stock_status, stock_label
        FROM products
        WHERE is_active = 1";

$params = [];
$types  = '';

if ($category !== 'all') {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types   .= 's';
}

$sql .= " ORDER BY product_id ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id'          => (int)$row['product_id'],
        'name'        => $row['name'],
        'category'    => $row['category'],
        'desc'        => $row['description'],
        'price'       => (float)$row['price'],
        'oldPrice'    => $row['old_price'] ? (float)$row['old_price'] : null,
        'emoji'       => $row['emoji'],
        'badge'       => $row['badge'],
        'badgeType'   => $row['badge_type'],
        'stock'       => $row['stock_status'],
        'stockLabel'  => $row['stock_label']
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'products' => $products]);