<?php
/**
 * submit_appointment.php
 *
 * Receives the Schedule Appointment modal form submission from
 * the CUSTOMER-FACING side (packages.html). No login required.
 *
 * On every submission this script:
 *  1. Creates or updates a customer record (keyed by email)
 *  2. Creates or finds a pet record for that customer
 *  3. Inserts the appointment linked to both
 *  4. Logs the initial status for the audit trail
 *
 * Expected POST fields:
 *   first_name        (string, required)
 *   last_name         (string, required)
 *   email             (string, required)
 *   contact_number    (string, required)
 *   pet_name          (string, required)
 *   pet_breed         (string, optional)
 *   service_id        (int, required)
 *   appointment_date  (date, required)  YYYY-MM-DD
 *   appointment_time  (time, required)  HH:MM
 *   staff_id          (int, optional)
 *   booking_type      (string, optional) 'online' | 'walk-in'
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

// ── Collect & sanitize input ──
$firstName    = trim($_POST['first_name'] ?? '');
$lastName     = trim($_POST['last_name'] ?? '');
$email        = trim($_POST['email'] ?? '');
$contactNum   = trim($_POST['contact_number'] ?? '');
$petName      = trim($_POST['pet_name'] ?? '');
$petBreed     = trim($_POST['pet_breed'] ?? '');
$serviceId    = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$apptDate     = trim($_POST['appointment_date'] ?? '');
$apptTime     = trim($_POST['appointment_time'] ?? '');
$staffId      = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
$bookingType  = trim($_POST['booking_type'] ?? 'online');
if (!in_array($bookingType, ['online', 'walk-in'], true)) $bookingType = 'online';

// ── Validate ──
$errors = [];
if ($firstName === '')   $errors[] = 'First name is required.';
if ($lastName === '')    $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($contactNum === '')  $errors[] = 'Contact number is required.';
if ($petName === '')     $errors[] = 'Pet name is required.';
if ($serviceId <= 0)     $errors[] = 'Please select a service.';
if ($apptDate === '')    $errors[] = 'Appointment date is required.';
if ($apptTime === '')    $errors[] = 'Appointment time is required.';
if ($apptDate !== '' && strtotime($apptDate) < strtotime(date('Y-m-d'))) {
    $errors[] = 'Appointment date cannot be in the past.';
}
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

// ── Confirm service exists ──
$svcCheck = $conn->prepare("SELECT service_id, service_name FROM services WHERE service_id = ? AND is_active = 1");
$svcCheck->bind_param('i', $serviceId);
$svcCheck->execute();
$svcResult = $svcCheck->get_result();
if ($svcResult->num_rows === 0) {
    $svcCheck->close(); $conn->close();
    respond(false, 'Selected service is invalid or no longer available.');
}
$service = $svcResult->fetch_assoc();
$svcCheck->close();

$conn->begin_transaction();

try {
    // ── Step 1: Create or update customer record ──
    // If email already exists, update name/contact in case they changed.
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

    // If insert_id is 0, the email already existed — fetch the existing customer_id
    if ($customerId === 0) {
        $fetchCust = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $fetchCust->bind_param('s', $email);
        $fetchCust->execute();
        $customerId = (int)$fetchCust->get_result()->fetch_assoc()['customer_id'];
        $fetchCust->close();
    }

    // ── Step 2: Create or find pet record ──
    // Match by pet_name + customer_id (same customer, same pet name = same pet)
    $petCheck = $conn->prepare(
        "SELECT pet_id FROM pets WHERE customer_id = ? AND LOWER(pet_name) = LOWER(?)"
    );
    $petCheck->bind_param('is', $customerId, $petName);
    $petCheck->execute();
    $petResult = $petCheck->get_result();
    $petCheck->close();

    if ($petResult->num_rows > 0) {
        // Pet already registered — use existing pet_id
        $petId = (int)$petResult->fetch_assoc()['pet_id'];
    } else {
        // New pet — register it
        $petStmt = $conn->prepare(
            "INSERT INTO pets (customer_id, pet_name, pet_breed) VALUES (?, ?, ?)"
        );
        $petStmt->bind_param('iss', $customerId, $petName, $petBreed);
        $petStmt->execute();
        $petId = $petStmt->insert_id;
        $petStmt->close();
    }

    // ── Step 3: Insert the appointment ──
    $apptStmt = $conn->prepare(
        "INSERT INTO appointments
            (customer_id, pet_id, first_name, last_name, email, contact_number,
             pet_name, pet_breed, service_id, appointment_date, appointment_time,
             booking_type, status, assigned_staff_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    $apptStmt->bind_param(
        'iissssssiss si',
        $customerId,
        $petId,
        $firstName,
        $lastName,
        $email,
        $contactNum,
        $petName,
        $petBreed,
        $serviceId,
        $apptDate,
        $apptTime,
        $bookingType,
        $staffId
    );
    $apptStmt->execute();
    $newApptId = $apptStmt->insert_id;
    $apptStmt->close();

    // ── Step 4: Log initial status ──
    $log = $conn->prepare(
        "INSERT INTO appointment_status_log (appointment_id, old_status, new_status, changed_by)
         VALUES (?, NULL, 'pending', 'customer')"
    );
    $log->bind_param('i', $newApptId);
    $log->execute();
    $log->close();

    $conn->commit();

    respond(true, 'Your appointment request has been submitted! We will confirm it shortly.', [
        'appointment_id' => $newApptId,
        'customer_id'    => $customerId,
        'pet_id'         => $petId,
        'service_name'   => $service['service_name'],
        'status'         => 'pending'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'An error occurred: ' . $e->getMessage());
} finally {
    $conn->close();
}