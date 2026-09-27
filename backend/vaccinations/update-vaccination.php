<?php
/**
 * update_vaccination.php
 *
 * Allows admin to update a vaccination record's next due date,
 * administered_by, batch number, or notes.
 *
 * Expected POST fields:
 *   record_id        (int, required)
 *   next_due_date    (date, optional)
 *   administered_by  (string, optional)
 *   batch_number     (string, optional)
 *   notes            (string, optional)
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$recordId = isset($_POST['record_id']) ? (int)$_POST['record_id'] : 0;
if ($recordId <= 0) respond(false, 'Invalid record ID.');

$conn = getDbConnection();

$fields = [];
$params = [];
$types  = '';

if (!empty($_POST['next_due_date'])) {
    $fields[] = 'next_due_date = ?';
    $params[] = trim($_POST['next_due_date']);
    $types   .= 's';
}
if (isset($_POST['administered_by'])) {
    $fields[] = 'administered_by = ?';
    $params[] = trim($_POST['administered_by']);
    $types   .= 's';
}
if (isset($_POST['batch_number'])) {
    $fields[] = 'batch_number = ?';
    $params[] = trim($_POST['batch_number']);
    $types   .= 's';
}
if (isset($_POST['notes'])) {
    $fields[] = 'notes = ?';
    $params[] = trim($_POST['notes']);
    $types   .= 's';
}

if (empty($fields)) {
    $conn->close();
    respond(false, 'Nothing to update.');
}

$params[] = $recordId;
$types   .= 'i';

$stmt = $conn->prepare(
    "UPDATE vaccination_records SET " . implode(', ', $fields) . " WHERE record_id = ?"
);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    respond(true, 'Vaccination record updated.');
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Update failed: ' . $err);
}