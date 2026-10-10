<?php
/**
 * get_boarding.php
 *
 * Returns all boarding reservations for the admin
 * boarding management page.
 *
 * Optional GET params:
 *   status  - 'pending' | 'confirmed' | 'checked_in' | 'checked_out' | 'cancelled' | 'all' (default)
 *   search  - partial match on owner name, email, or pet name
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            reservation_id, first_name, last_name, email, contact_number,
            pet_name, pet_breed, room_type, rate_per_night,
            check_in_date, check_out_date, total_nights, estimated_total,
            addons, special_instructions, status, created_at
        FROM boarding_reservations
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
                WHEN 'checked_in' THEN 3
                WHEN 'checked_out' THEN 4
                WHEN 'cancelled' THEN 5
            END,
            check_in_date ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$reservations = [];
while ($row = $result->fetch_assoc()) {
    $reservations[] = [
        'reservation_id'       => (int)$row['reservation_id'],
        'owner_name'           => $row['first_name'] . ' ' . $row['last_name'],
        'email'                => $row['email'],
        'contact_number'       => $row['contact_number'],
        'pet_name'             => $row['pet_name'],
        'pet_breed'            => $row['pet_breed'],
        'room_type'            => $row['room_type'],
        'rate_per_night'       => (float)$row['rate_per_night'],
        'check_in_date'        => $row['check_in_date'],
        'check_out_date'       => $row['check_out_date'],
        'total_nights'         => (int)$row['total_nights'],
        'estimated_total'      => (float)$row['estimated_total'],
        'addons'               => $row['addons'],
        'special_instructions' => $row['special_instructions'],
        'status'               => $row['status'],
        'created_at'           => $row['created_at']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')     AS pending,
        SUM(status = 'confirmed')   AS confirmed,
        SUM(status = 'checked_in')  AS checked_in,
        SUM(status = 'checked_out') AS checked_out,
        SUM(status = 'cancelled')   AS cancelled,
        SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) AS currently_boarding
     FROM boarding_reservations"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success'      => true,
    'reservations' => $reservations,
    'summary'      => [
        'total'              => (int)$summary['total'],
        'pending'            => (int)$summary['pending'],
        'confirmed'          => (int)$summary['confirmed'],
        'checked_in'         => (int)$summary['checked_in'],
        'checked_out'        => (int)$summary['checked_out'],
        'cancelled'          => (int)$summary['cancelled'],
        'currently_boarding' => (int)$summary['currently_boarding']
    ]
]);
