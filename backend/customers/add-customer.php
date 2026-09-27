<?php
/**
 * add_customer.php
 *
 * Allows admin to manually add a customer from customers.html.
 * Also accepts an optional first pet name/breed to register
 * at the same time.
 *
 * Expected POST fields:
 *   first_name       (string, required)
 *   last_name        (string, required)
 *   email            (string, required)
 *   contact_number   (string, required)
 *   pet_name         (string, optional)
 *   pet_breed        (string, optional)
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

$firstName   = trim($_POST['first_name'] ?? '');
$lastName    = trim($_POST['last_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$contactNum  = trim($_POST['contact_number'] ?? '');
$petName     = trim($_POST['pet_name'] ?? '');
$petBreed    = trim($_POST['pet_breed'] ?? '');

$errors = [];
if ($firstName === '') $errors[] = 'First name is required.';
if ($lastName === '')  $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($contactNum === '') $errors[] = 'Contact number is required.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

// Check for duplicate email
$check = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
$check->bind_param('s', $email);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close(); $conn->close();
    respond(false, 'A customer with this email already exists.');
}
$check->close();

$conn->begin_transaction();

try {
    // Insert customer
    $stmt = $conn->prepare(
        "INSERT INTO customers (first_name, last_name, email, contact_number)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('ssss', $firstName, $lastName, $email, $contactNum);
    $stmt->execute();
    $customerId = $stmt->insert_id;
    $stmt->close();

    $petId = null;

    // Optionally register a pet at the same time
    if ($petName !== '') {
        $petStmt = $conn->prepare(
            "INSERT INTO pets (customer_id, pet_name, pet_breed) VALUES (?, ?, ?)"
        );
        $petStmt->bind_param('iss', $customerId, $petName, $petBreed);
        $petStmt->execute();
        $petId = $petStmt->insert_id;
        $petStmt->close();
    }

    $conn->commit();

    respond(true, "{$firstName} {$lastName} has been added successfully.", [
        'customer_id' => $customerId,
        'pet_id'      => $petId
    ]);

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Failed to add customer: ' . $e->getMessage());
} finally {
    $conn->close();
}