<?php
/**
 * get_customers.php
 *
 * Returns all customer records for the admin customers.html table.
 * Each customer includes:
 *   - Full name, email, contact number
 *   - List of their registered pets
 *   - Total visits (count of non-cancelled appointments)
 *   - Last visit date
 *   - Active / inactive status
 *
 * Optional GET params:
 *   search   - partial match on name, email, or pet name
 *   status   - 'active' | 'inactive' | 'all' (default)
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

$conn   = getDbConnection();
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? 'all');

$sql = "SELECT
            c.customer_id,
            c.first_name,
            c.last_name,
            c.email,
            c.contact_number,
            c.is_active,
            c.created_at,
            COUNT(DISTINCT CASE WHEN a.status != 'cancelled' THEN a.appointment_id END) AS total_visits,
            MAX(CASE WHEN a.status = 'completed' THEN a.appointment_date END) AS last_visit
        FROM customers c
        LEFT JOIN appointments a ON a.customer_id = c.customer_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($status === 'active') {
    $sql .= " AND c.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND c.is_active = 0";
}

if ($search !== '') {
    $sql .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}

$sql .= " GROUP BY c.customer_id ORDER BY c.last_name ASC, c.first_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$customers = [];
while ($row = $result->fetch_assoc()) {
    // Fetch pets for this customer
    $petStmt = $conn->prepare(
        "SELECT pet_id, pet_name, pet_breed FROM pets WHERE customer_id = ? ORDER BY pet_name ASC"
    );
    $petStmt->bind_param('i', $row['customer_id']);
    $petStmt->execute();
    $petResult = $petStmt->get_result();
    $pets = [];
    while ($pet = $petResult->fetch_assoc()) {
        $pets[] = [
            'pet_id'    => (int)$pet['pet_id'],
            'pet_name'  => $pet['pet_name'],
            'pet_breed' => $pet['pet_breed']
        ];
    }
    $petStmt->close();

    $customers[] = [
        'customer_id'    => (int)$row['customer_id'],
        'full_name'      => $row['first_name'] . ' ' . $row['last_name'],
        'first_name'     => $row['first_name'],
        'last_name'      => $row['last_name'],
        'email'          => $row['email'],
        'contact_number' => $row['contact_number'],
        'is_active'      => (bool)$row['is_active'],
        'total_visits'   => (int)$row['total_visits'],
        'last_visit'     => $row['last_visit'],
        'pets'           => $pets,
        'member_since'   => $row['created_at']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active,
        SUM(is_active = 0) AS inactive
     FROM customers"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success'   => true,
    'customers' => $customers,
    'summary'   => [
        'total'    => (int)$summary['total'],
        'active'   => (int)$summary['active'],
        'inactive' => (int)$summary['inactive']
    ]
]);