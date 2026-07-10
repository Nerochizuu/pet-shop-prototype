<?php
/**
 * get_appointments.php
 *
 * Returns appointments for the ADMIN-FACING appointments.html
 * table. Pulls directly from what customers submitted via
 * submit_appointment.php, joined with service and staff info.
 *
 * Optional GET params:
 *   status   - 'pending' | 'confirmed' | 'in_progress' | 'completed' | 'cancelled' | 'all' (default)
 *   date     - filter to a specific date (YYYY-MM-DD)
 *   search   - partial match on customer name, pet name, or email
 */

header('Content-Type: application/json');
require_once '../db_connect.php';
$conn = getDbConnection();

$statusFilter = $_GET['status'] ?? 'all';
$dateFilter   = trim($_GET['date'] ?? '');
$search       = trim($_GET['search'] ?? '');

$sql = "SELECT
            a.appointment_id,
            a.first_name,
            a.last_name,
            a.email,
            a.contact_number,
            a.pet_name,
            a.pet_breed,
            s.service_name,
            s.price,
            s.price_unit,
            a.appointment_date,
            a.appointment_time,
            a.booking_type,
            a.status,
            a.admin_notes,
            st.full_name AS groomer_name,
            a.assigned_staff_id,
            a.created_at
        FROM appointments a
        INNER JOIN services s ON a.service_id = s.service_id
        LEFT JOIN staff st ON a.assigned_staff_id = st.staff_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($statusFilter !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($dateFilter !== '') {
    $sql .= " AND a.appointment_date = ?";
    $params[] = $dateFilter;
    $types .= 's';
}

if ($search !== '') {
    $sql .= " AND (a.first_name LIKE ? OR a.last_name LIKE ? OR a.pet_name LIKE ? OR a.email LIKE ?)";
    $likeSearch = '%' . $search . '%';
    $params = array_merge($params, [$likeSearch, $likeSearch, $likeSearch, $likeSearch]);
    $types .= 'ssss';
}

$sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$appointments = [];
$counts = [
    'all'         => 0,
    'pending'     => 0,
    'confirmed'   => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'cancelled'   => 0
];

while ($row = $result->fetch_assoc()) {
    $appointments[] = [
        'appointment_id'   => (int)$row['appointment_id'],
        'customer_name'    => $row['first_name'] . ' ' . $row['last_name'],
        'email'            => $row['email'],
        'contact_number'   => $row['contact_number'],
        'pet_name'         => $row['pet_name'],
        'pet_breed'        => $row['pet_breed'],
        'service_name'     => $row['service_name'],
        'price'            => (float)$row['price'],
        'price_unit'       => $row['price_unit'],
        'appointment_date' => $row['appointment_date'],
        'appointment_time' => $row['appointment_time'],
        'booking_type'     => $row['booking_type'],
        'status'           => $row['status'],
        'admin_notes'      => $row['admin_notes'],
        'groomer_name'     => $row['groomer_name'], // null if not yet assigned
        'assigned_staff_id'=> $row['assigned_staff_id'] ? (int)$row['assigned_staff_id'] : null,
        'submitted_at'     => $row['created_at']
    ];
}
$stmt->close();

// Get overall counts (unfiltered by status, for the tab badges)
$countResult = $conn->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status");
while ($row = $countResult->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['cnt'];
    $counts['all'] += (int)$row['cnt'];
}

$conn->close();

echo json_encode([
    'success'      => true,
    'appointments' => $appointments,
    'counts'       => $counts
]);