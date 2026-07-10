<?php
/**
 * submit_appointment.php
 *
 * Receives the Schedule Appointment modal form submission from
 * the CUSTOMER-FACING side (packages.html / home.html). No login
 * required — this is a guest booking.
 *
 * Every new booking is inserted with status = 'pending' and
 * shows up immediately in the admin's Appointments table for
 * review and confirmation.
 *
 * Expected POST fields (matches the modal's form field names):
 *   first_name        (string, required)
 *   last_name         (string, required)
 *   email             (string, required)
 *   contact_number    (string, required)
 *   pet_name          (string, required)
 *   pet_breed         (string, optional)
 *   service_id        (int, required)   - which package was selected
 *   appointment_date  (date, required)  - format: YYYY-MM-DD
 *   appointment_time  (time, required)  - format: HH:MM
 *   staff_id          (int, optional)   - if admin assigns a groomer at creation
 *   booking_type      (string, optional) - 'online' (default) or 'walk-in'
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
if (!in_array($bookingType, ['online', 'walk-in'], true)) {
    $bookingType = 'online';
}

// ── Validate required fields ──
$errors = [];
if ($firstName === '')   $errors[] = 'First name is required.';
if ($lastName === '')    $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($contactNum === '')  $errors[] = 'Contact number is required.';
if ($petName === '')     $errors[] = 'Pet name is required.';
if ($serviceId <= 0)     $errors[] = 'Please select a service.';
if ($apptDate === '')    $errors[] = 'Appointment date is required.';
if ($apptTime === '')    $errors[] = 'Appointment time is required.';

// Date must not be in the past
if ($apptDate !== '' && strtotime($apptDate) < strtotime(date('Y-m-d'))) {
    $errors[] = 'Appointment date cannot be in the past.';
}

if (!empty($errors)) {
    respond(false, implode(' ', $errors));
}

$conn = getDbConnection();

// Confirm the selected service actually exists and is active
$svcCheck = $conn->prepare("SELECT service_id, service_name FROM services WHERE service_id = ? AND is_active = 1");
$svcCheck->bind_param('i', $serviceId);
$svcCheck->execute();
$svcResult = $svcCheck->get_result();

if ($svcResult->num_rows === 0) {
    $svcCheck->close();
    $conn->close();
    respond(false, 'Selected service is invalid or no longer available.');
}
$service = $svcResult->fetch_assoc();
$svcCheck->close();

// ── Insert the appointment as 'pending' ──
$stmt = $conn->prepare(
    "INSERT INTO appointments
        (first_name, last_name, email, contact_number, pet_name, pet_breed,
         service_id, appointment_date, appointment_time, booking_type, status, assigned_staff_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
);
$stmt->bind_param(
    'ssssssissi',
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

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    // Log initial status for the audit trail
    $log = $conn->prepare(
        "INSERT INTO appointment_status_log (appointment_id, old_status, new_status, changed_by)
         VALUES (?, NULL, 'pending', 'customer')"
    );
    $log->bind_param('i', $newId);
    $log->execute();
    $log->close();

    $conn->close();

    respond(true, 'Your appointment request has been submitted! We will confirm it shortly.', [
        'appointment_id' => $newId,
        'service_name'   => $service['service_name'],
        'status'         => 'pending'
    ]);
} else {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to submit appointment: ' . $error);
}