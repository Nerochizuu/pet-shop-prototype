<?php
/**
 * create_user.php
 *
 * Admin creates a new admin or staff account.
 * Password is hashed with bcrypt before storing.
 *
 * Expected POST fields:
 *   full_name    (string, required)
 *   email        (string, required)
 *   password     (string, required, min 8 chars)
 *   role         (string, required) 'admin' | 'staff'
 *   position     (string, optional) e.g. "Senior Groomer"
 *   created_by   (int, required) user_id of the logged-in admin
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

$fullName  = trim($_POST['full_name']  ?? '');
$email     = trim($_POST['email']      ?? '');
$password  = trim($_POST['password']   ?? '');
$role      = trim($_POST['role']       ?? 'staff');
$position  = trim($_POST['position']   ?? '');
$createdBy = !empty($_POST['created_by']) ? (int)$_POST['created_by'] : null;

// Validate
$errors = [];
if ($fullName === '')  $errors[] = 'Full name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
if (!in_array($role, ['admin', 'staff'], true)) $errors[] = 'Role must be admin or staff.';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

// Check for duplicate email
$check = $conn->prepare("SELECT user_id FROM user_accounts WHERE email = ?");
$check->bind_param('s', $email);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close(); $conn->close();
    respond(false, 'An account with this email already exists.');
}
$check->close();

// Hash password
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $conn->prepare(
    "INSERT INTO user_accounts (full_name, email, password_hash, role, position, created_by)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param('sssssi', $fullName, $email, $passwordHash, $role, $position, $createdBy);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    respond(true, "Account for {$fullName} created successfully.", ['user_id' => $newId]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to create account: ' . $err);
}