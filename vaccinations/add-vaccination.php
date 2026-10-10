<?php
/**
 * add_vaccination.php
 *
 * Manually logs a vaccination record.
 * Used for walk-in vaccinations, or when admin wants
 * to add a record outside of a booked appointment.
 *
 * Also called automatically from update_appointment.php
 * when a vaccination-type appointment is completed
 * (appointment_id is passed in that case).
 *
 * Expected POST fields:
 *   pet_id           (int, required)
 *   customer_id      (int, required)
 *   vaccine_type_id  (int, required)
 *   date_given       (date, required)   YYYY-MM-DD
 *   next_due_date    (date, optional)   YYYY-MM-DD
 *   administered_by  (string, optional)
 *   appointment_id   (int, optional)
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

$petId          = isset($_POST['pet_id'])          ? (int)$_POST['pet_id']          : 0;
$customerId     = isset($_POST['customer_id'])     ? (int)$_POST['customer_id']     : 0;
$vaccineTypeId  = isset($_POST['vaccine_type_id']) ? (int)$_POST['vaccine_type_id'] : 0;
$dateGiven      = trim($_POST['date_given']      ?? '');
$nextDueDate    = trim($_POST['next_due_date']   ?? '') ?: null;
$administeredBy = trim($_POST['administered_by'] ?? '');
$appointmentId  = !empty($_POST['appointment_id'])  ? (int)$_POST['appointment_id']  : null;
$batchNumber    = trim($_POST['batch_number']    ?? '');
$notes          = trim($_POST['notes']           ?? '');

$errors = [];
if ($petId <= 0)         $errors[] = 'Pet is required.';
if ($customerId <= 0)    $errors[] = 'Customer is required.';
if ($vaccineTypeId <= 0) $errors[] = 'Vaccine type is required.';
if ($dateGiven === '')   $errors[] = 'Date given is required.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

$stmt = $conn->prepare(
    "INSERT INTO vaccination_records
        (pet_id, customer_id, vaccine_type_id, date_given, next_due_date,
         administered_by, appointment_id, batch_number, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'iiisssisss',
    $petId,
    $customerId,
    $vaccineTypeId,
    $dateGiven,
    $nextDueDate,
    $administeredBy,
    $appointmentId,
    $batchNumber,
    $notes
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    respond(true, 'Vaccination record saved successfully.', ['record_id' => $newId]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to save record: ' . $err);
}