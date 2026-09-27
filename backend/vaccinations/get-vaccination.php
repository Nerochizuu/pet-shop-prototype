<?php
/**
 * get_vaccinations.php
 *
 * Returns all vaccination records for the admin vaccinations.html
 * table. Each record includes pet name, owner name, vaccine type,
 * date given, next due date, and computed status.
 *
 * Status logic:
 *   overdue   — next_due_date is in the past
 *   due_soon  — next_due_date is within 30 days from today
 *   up_to_date — next_due_date is more than 30 days away
 *   no_date   — next_due_date not set
 *
 * Optional GET params:
 *   status  - 'overdue' | 'due_soon' | 'up_to_date' | 'all' (default)
 *   search  - partial match on pet name or owner name
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';


$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            vr.record_id,
            vr.date_given,
            vr.next_due_date,
            vr.administered_by,
            vr.batch_number,
            vr.notes,
            p.pet_id,
            p.pet_name,
            p.pet_breed,
            c.customer_id,
            c.first_name,
            c.last_name,
            c.email,
            vt.vaccine_type_id,
            vt.vaccine_name,
            vr.appointment_id
        FROM vaccination_records vr
        INNER JOIN pets p        ON vr.pet_id = p.pet_id
        INNER JOIN customers c   ON vr.customer_id = c.customer_id
        INNER JOIN vaccine_types vt ON vr.vaccine_type_id = vt.vaccine_type_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= " AND (p.pet_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}

$sql .= " ORDER BY vr.next_due_date ASC, vr.date_given DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$today     = new DateTime();
$in30Days  = (new DateTime())->modify('+30 days');

$records   = [];
$counts    = ['all' => 0, 'overdue' => 0, 'due_soon' => 0, 'up_to_date' => 0, 'no_date' => 0];

while ($row = $result->fetch_assoc()) {
    // Compute status
    $recordStatus = 'no_date';
    if ($row['next_due_date']) {
        $dueDate = new DateTime($row['next_due_date']);
        if ($dueDate < $today) {
            $recordStatus = 'overdue';
        } elseif ($dueDate <= $in30Days) {
            $recordStatus = 'due_soon';
        } else {
            $recordStatus = 'up_to_date';
        }
    }

    $counts['all']++;
    $counts[$recordStatus]++;

    // Apply status filter after computing
    if ($status !== 'all' && $recordStatus !== $status) {
        continue;
    }

    $records[] = [
        'record_id'       => (int)$row['record_id'],
        'pet_id'          => (int)$row['pet_id'],
        'pet_name'        => $row['pet_name'],
        'pet_breed'       => $row['pet_breed'],
        'customer_id'     => (int)$row['customer_id'],
        'owner_name'      => $row['first_name'] . ' ' . $row['last_name'],
        'email'           => $row['email'],
        'vaccine_type_id' => (int)$row['vaccine_type_id'],
        'vaccine_name'    => $row['vaccine_name'],
        'date_given'      => $row['date_given'],
        'next_due_date'   => $row['next_due_date'],
        'administered_by' => $row['administered_by'],
        'batch_number'    => $row['batch_number'],
        'notes'           => $row['notes'],
        'status'          => $recordStatus,
        'appointment_id'  => $row['appointment_id']
    ];
}
$stmt->close();

// Fetch vaccine types for the add form dropdown
$vtResult = $conn->query(
    "SELECT vaccine_type_id, vaccine_name FROM vaccine_types WHERE is_active = 1 ORDER BY vaccine_name"
);
$vaccineTypes = [];
while ($vt = $vtResult->fetch_assoc()) {
    $vaccineTypes[] = [
        'vaccine_type_id' => (int)$vt['vaccine_type_id'],
        'vaccine_name'    => $vt['vaccine_name']
    ];
}

$conn->close();

echo json_encode([
    'success'       => true,
    'records'       => $records,
    'counts'        => $counts,
    'vaccine_types' => $vaccineTypes
]);