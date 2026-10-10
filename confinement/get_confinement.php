<?php
/**
 * get_confinement.php
 *
 * Returns all confinement admissions for the admin
 * confinement management page. Each record includes a
 * count of its medication log entries.
 *
 * Optional GET params:
 *   status  - 'pending' | 'admitted' | 'under_care' | 'discharged' | 'cancelled' | 'all' (default)
 *   search  - partial match on owner name, email, or pet name
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            ca.admission_id, ca.first_name, ca.last_name, ca.email, ca.contact_number,
            ca.pet_name, ca.pet_breed, ca.pet_age, ca.pet_weight,
            ca.ward_type, ca.rate_per_day,
            ca.admission_date, ca.discharge_date, ca.estimated_duration,
            ca.chief_complaint, ca.current_medications, ca.allergies_notes,
            ca.status, ca.created_at,
            COUNT(ml.log_id) AS medication_count,
            SUM(CASE WHEN ml.status = 'Pending' THEN 1 ELSE 0 END) AS pending_medications
        FROM confinement_admissions ca
        LEFT JOIN medication_logs ml ON ml.admission_id = ca.admission_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($status !== 'all') {
    $sql .= " AND ca.status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($search !== '') {
    $sql .= " AND (ca.first_name LIKE ? OR ca.last_name LIKE ? OR ca.email LIKE ? OR ca.pet_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

$sql .= " GROUP BY ca.admission_id
          ORDER BY
            CASE ca.status
                WHEN 'pending' THEN 1
                WHEN 'admitted' THEN 2
                WHEN 'under_care' THEN 3
                WHEN 'discharged' THEN 4
                WHEN 'cancelled' THEN 5
            END,
            ca.admission_date ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$admissions = [];
while ($row = $result->fetch_assoc()) {
    $admissions[] = [
        'admission_id'        => (int)$row['admission_id'],
        'owner_name'          => $row['first_name'] . ' ' . $row['last_name'],
        'email'               => $row['email'],
        'contact_number'      => $row['contact_number'],
        'pet_name'            => $row['pet_name'],
        'pet_breed'           => $row['pet_breed'],
        'pet_age'             => $row['pet_age'],
        'pet_weight'          => $row['pet_weight'] ? (float)$row['pet_weight'] : null,
        'ward_type'           => $row['ward_type'],
        'rate_per_day'        => (float)$row['rate_per_day'],
        'admission_date'      => $row['admission_date'],
        'discharge_date'      => $row['discharge_date'],
        'estimated_duration'  => $row['estimated_duration'],
        'chief_complaint'     => $row['chief_complaint'],
        'current_medications' => $row['current_medications'],
        'allergies_notes'     => $row['allergies_notes'],
        'status'              => $row['status'],
        'created_at'          => $row['created_at'],
        'medication_count'    => (int)$row['medication_count'],
        'pending_medications' => (int)$row['pending_medications']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')     AS pending,
        SUM(status = 'admitted')    AS admitted,
        SUM(status = 'under_care')  AS under_care,
        SUM(status = 'discharged')  AS discharged,
        SUM(status = 'cancelled')   AS cancelled,
        SUM(CASE WHEN status IN ('admitted','under_care') THEN 1 ELSE 0 END) AS currently_confined
     FROM confinement_admissions"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success'    => true,
    'admissions' => $admissions,
    'summary'    => [
        'total'              => (int)$summary['total'],
        'pending'            => (int)$summary['pending'],
        'admitted'           => (int)$summary['admitted'],
        'under_care'         => (int)$summary['under_care'],
        'discharged'         => (int)$summary['discharged'],
        'cancelled'          => (int)$summary['cancelled'],
        'currently_confined' => (int)$summary['currently_confined']
    ]
]);