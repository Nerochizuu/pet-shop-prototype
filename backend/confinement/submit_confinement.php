<?php
/**
 * submit_confinement.php
 *
 * Receives the pet admission form from packages.html
 * and saves it to confinement_admissions.
 * Also creates/updates the customer and pet records.
 *
 * Expected POST fields:
 *   first_name           (string, required)
 *   last_name            (string, required)
 *   email                (string, required)
 *   contact_number       (string, required)
 *   pet_name             (string, required)
 *   pet_breed            (string, optional)
 *   pet_age              (string, optional)
 *   pet_weight           (decimal, optional)
 *   ward_type            (string, required)  e.g. 'Recovery Suite'
 *   rate_per_day         (decimal, required)
 *   admission_date       (date, required)    YYYY-MM-DD
 *   estimated_duration   (string, optional)  e.g. '3 days'
 *   chief_complaint      (string, optional)
 *   current_medications  (string, optional)
 *   allergies_notes      (string, optional)
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

// ── Collect & sanitize ──
$firstName   = trim($_POST['first_name']          ?? '');
$lastName    = trim($_POST['last_name']           ?? '');
$email       = trim($_POST['email']               ?? '');
$contactNum  = trim($_POST['contact_number']      ?? '');
$petName     = trim($_POST['pet_name']            ?? '');
$petBreed    = trim($_POST['pet_breed']           ?? '');
$petAge      = trim($_POST['pet_age']             ?? '');
$petWeight   = !empty($_POST['pet_weight'])        ? (float)$_POST['pet_weight'] : null;
$wardType    = trim($_POST['ward_type']           ?? '');
$ratePerDay  = isset($_POST['rate_per_day'])       ? (float)$_POST['rate_per_day'] : 0;
$admitDate   = trim($_POST['admission_date']      ?? '');
$estDuration = trim($_POST['estimated_duration']  ?? '');
$complaint   = trim($_POST['chief_complaint']     ?? '');
$currentMeds = trim($_POST['current_medications'] ?? '');
$allergies   = trim($_POST['allergies_notes']     ?? '');

// ── Validate ──
$errors = [];
if ($firstName === '')  $errors[] = 'First name is required.';
if ($lastName === '')   $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
}
if ($contactNum === '') $errors[] = 'Contact number is required.';
if ($petName === '')    $errors[] = 'Pet name is required.';
if ($wardType === '')   $errors[] = 'Ward type is required.';
if ($ratePerDay <= 0)  $errors[] = 'Invalid ward rate.';
if ($admitDate === '')  $errors[] = 'Admission date is required.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // ── Step 1: Create or update customer ──
    $custStmt = $conn->prepare(
        "INSERT INTO customers (first_name, last_name, email, contact_number)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             first_name     = VALUES(first_name),
             last_name      = VALUES(last_name),
             contact_number = VALUES(contact_number)"
    );
    $custStmt->bind_param('ssss', $firstName, $lastName, $email, $contactNum);
    $custStmt->execute();
    $customerId = $custStmt->insert_id;
    $custStmt->close();

    if ($customerId === 0) {
        $fetch = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $fetch->bind_param('s', $email);
        $fetch->execute();
        $customerId = (int)$fetch->get_result()->fetch_assoc()['customer_id'];
        $fetch->close();
    }

    // ── Step 2: Create or find pet ──
    $petCheck = $conn->prepare(
        "SELECT pet_id FROM pets WHERE customer_id = ? AND LOWER(pet_name) = LOWER(?)"
    );
    $petCheck->bind_param('is', $customerId, $petName);
    $petCheck->execute();
    $petResult = $petCheck->get_result();
    $petCheck->close();

    if ($petResult->num_rows > 0) {
        $petId = (int)$petResult->fetch_assoc()['pet_id'];
    } else {
        $petStmt = $conn->prepare(
            "INSERT INTO pets (customer_id, pet_name, pet_breed) VALUES (?, ?, ?)"
        );
        $petStmt->bind_param('iss', $customerId, $petName, $petBreed);
        $petStmt->execute();
        $petId = $petStmt->insert_id;
        $petStmt->close();
    }

    // ── Step 3: Save admission ──
    $stmt = $conn->prepare(
        "INSERT INTO confinement_admissions
            (first_name, last_name, email, contact_number,
             pet_name, pet_breed, pet_age, pet_weight,
             ward_type, rate_per_day, admission_date, estimated_duration,
             chief_complaint, current_medications, allergies_notes,
             customer_id, pet_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssssssdssssssii',
        $firstName, $lastName, $email, $contactNum,
        $petName, $petBreed, $petAge, $petWeight,
        $wardType, $ratePerDay, $admitDate, $estDuration,
        $complaint, $currentMeds, $allergies,
        $customerId, $petId
    );
    $stmt->execute();
    $admissionId = $stmt->insert_id;
    $stmt->close();

    $conn->commit();

    respond(true,
        "Admission form submitted! 🏥 Our veterinary team will prepare for {$petName}'s arrival. " .
        "We'll contact you at {$email} to confirm the admission details.",
        [
            'admission_id' => $admissionId,
            'customer_id'  => $customerId,
            'pet_id'       => $petId,
            'ward_type'    => $wardType
        ]
    );

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Failed to submit admission: ' . $e->getMessage());
} finally {
    $conn->close();
}