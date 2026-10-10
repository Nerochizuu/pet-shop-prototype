<?php
/**
 * get_medication_logs.php
 *
 * Returns all medication log entries for a specific
 * confinement admission. Used when admin expands an
 * admission row to view its medication history.
 *
 * Expected GET params:
 *   admission_id  (int, required)
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn         = getDbConnection();
$admissionId  = isset($_GET['admission_id']) ? (int)$_GET['admission_id'] : 0;

if ($admissionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid admission ID.']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT log_id, medication_name, dosage, route, administered_by,
            status, administered_at, notes
     FROM medication_logs
     WHERE admission_id = ?
     ORDER BY administered_at DESC"
);
$stmt->bind_param('i', $admissionId);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        'log_id'          => (int)$row['log_id'],
        'medication_name' => $row['medication_name'],
        'dosage'          => $row['dosage'],
        'route'           => $row['route'],
        'administered_by' => $row['administered_by'],
        'status'          => $row['status'],
        'administered_at' => $row['administered_at'],
        'notes'           => $row['notes']
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'logs' => $logs]);