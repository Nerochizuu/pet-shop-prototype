<?php
/**
 * submit_boarding.php
 *
 * Receives the boarding reservation form from packages.html
 * and saves it to the boarding_reservations table.
 *
 * On submission, also attempts to create or link a customer
 * record (via email) so the booking appears under that
 * customer in the admin Customers page.
 *
 * Expected POST fields:
 *   first_name            (string, required)
 *   last_name             (string, required)
 *   email                 (string, required)
 *   contact_number        (string, required)
 *   pet_name              (string, required)
 *   pet_breed             (string, optional)
 *   room_type             (string, required)  e.g. 'Cozy Suite'
 *   rate_per_night        (decimal, required)
 *   check_in_date         (date, required)    YYYY-MM-DD
 *   check_out_date        (date, required)    YYYY-MM-DD
 *   addons                (string, optional)  comma-separated add-on names
 *   special_instructions  (string, optional)
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
$firstName    = trim($_POST['first_name']           ?? '');
$lastName     = trim($_POST['last_name']            ?? '');
$email        = trim($_POST['email']                ?? '');
$contactNum   = trim($_POST['contact_number']       ?? '');
$petName      = trim($_POST['pet_name']             ?? '');
$petBreed     = trim($_POST['pet_breed']            ?? '');
$roomType     = trim($_POST['room_type']            ?? '');
$ratePerNight = isset($_POST['rate_per_night'])     ? (float)$_POST['rate_per_night'] : 0;
$checkIn      = trim($_POST['check_in_date']        ?? '');
$checkOut     = trim($_POST['check_out_date']       ?? '');
$addons       = trim($_POST['addons']               ?? '');
$instructions = trim($_POST['special_instructions'] ?? '');

// ── Validate ──
$errors = [];
if ($firstName === '')   $errors[] = 'First name is required.';
if ($lastName === '')    $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
}
if ($contactNum === '')  $errors[] = 'Contact number is required.';
if ($petName === '')     $errors[] = 'Pet name is required.';
if ($roomType === '')    $errors[] = 'Room type is required.';
if ($ratePerNight <= 0) $errors[] = 'Invalid room rate.';
if ($checkIn === '')     $errors[] = 'Check-in date is required.';
if ($checkOut === '')    $errors[] = 'Check-out date is required.';

if (empty($errors) && strtotime($checkOut) <= strtotime($checkIn)) {
    $errors[] = 'Check-out date must be after check-in date.';
}
if (empty($errors) && strtotime($checkIn) < strtotime(date('Y-m-d'))) {
    $errors[] = 'Check-in date cannot be in the past.';
}
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // ── Step 1: Create or update customer record ──
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
        $fetchCust = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $fetchCust->bind_param('s', $email);
        $fetchCust->execute();
        $customerId = (int)$fetchCust->get_result()->fetch_assoc()['customer_id'];
        $fetchCust->close();
    }

    // ── Step 2: Create or find pet record ──
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

    // ── Step 3: Save boarding reservation ──
    $stmt = $conn->prepare(
        "INSERT INTO boarding_reservations
            (first_name, last_name, email, contact_number,
             pet_name, pet_breed, room_type, rate_per_night,
             check_in_date, check_out_date, addons,
             special_instructions, customer_id, pet_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'sssssssdssssii',
        $firstName,
        $lastName,
        $email,
        $contactNum,
        $petName,
        $petBreed,
        $roomType,
        $ratePerNight,
        $checkIn,
        $checkOut,
        $addons,
        $instructions,
        $customerId,
        $petId
    );
    $stmt->execute();
    $reservationId = $stmt->insert_id;
    $stmt->close();

    $conn->commit();

    // Calculate nights for the response message
    $nights = (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400);

    respond(true,
        "Reservation confirmed! 🐾 {$petName} is booked into our {$roomType} for {$nights} night" .
        ($nights > 1 ? 's' : '') . ". We'll reach out to finalize the details.",
        [
            'reservation_id' => $reservationId,
            'customer_id'    => $customerId,
            'pet_id'         => $petId,
            'nights'         => $nights,
            'room_type'      => $roomType
        ]
    );

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Failed to save reservation: ' . $e->getMessage());
} finally {
    $conn->close();
}