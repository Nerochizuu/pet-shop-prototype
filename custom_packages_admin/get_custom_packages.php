<?php
/**
 * get_custom_packages.php
 *
 * Returns all custom package requests for the admin
 * custom-packages.html page. Parses the selected_services
 * JSON into a usable array for the frontend.
 *
 * Optional GET params:
 *   status  - 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'all' (default)
 *   search  - partial match on customer name, email, or pet name
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            order_id, first_name, last_name, email, contact_number,
            pet_name, selected_services, total_price,
            preferred_date, preferred_time, notes, status, created_at
        FROM custom_package_orders
        WHERE 1=1";

$params = [];
$types  = '';

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($search !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR pet_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

$sql .= " ORDER BY
            CASE status
                WHEN 'pending' THEN 1
                WHEN 'confirmed' THEN 2
                WHEN 'completed' THEN 3
                WHEN 'cancelled' THEN 4
            END,
            created_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
while ($row = $result->fetch_assoc()) {
    $services = json_decode($row['selected_services'], true) ?: [];

    $orders[] = [
        'order_id'        => (int)$row['order_id'],
        'customer_name'   => $row['first_name'] . ' ' . $row['last_name'],
        'email'           => $row['email'],
        'contact_number'  => $row['contact_number'],
        'pet_name'        => $row['pet_name'],
        'services'        => $services,
        'service_count'   => count($services),
        'total_price'     => (float)$row['total_price'],
        'preferred_date'  => $row['preferred_date'],
        'preferred_time'  => $row['preferred_time'],
        'notes'           => $row['notes'],
        'status'          => $row['status'],
        'created_at'      => $row['created_at']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')   AS pending,
        SUM(status = 'confirmed') AS confirmed,
        SUM(status = 'completed') AS completed,
        SUM(status = 'cancelled') AS cancelled,
        SUM(CASE WHEN status != 'cancelled' THEN total_price ELSE 0 END) AS total_value
     FROM custom_package_orders"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success' => true,
    'orders'  => $orders,
    'summary' => [
        'total'       => (int)$summary['total'],
        'pending'     => (int)$summary['pending'],
        'confirmed'   => (int)$summary['confirmed'],
        'completed'   => (int)$summary['completed'],
        'cancelled'   => (int)$summary['cancelled'],
        'total_value' => (float)$summary['total_value']
    ]
]);
