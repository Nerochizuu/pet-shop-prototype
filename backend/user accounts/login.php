<?php
/**
 * login.php
 *
 * Authenticates an admin or staff user.
 * On success, creates a session token and stores it
 * in login_sessions. Returns the token to the client
 * which should store it in sessionStorage and send it
 * as a header on subsequent requests.
 *
 * Expected POST fields:
 *   email     (string, required)
 *   password  (string, required)
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

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    respond(false, 'Email and password are required.');
}

$conn = getDbConnection();

$stmt = $conn->prepare(
    "SELECT user_id, full_name, email, password_hash, role, position, is_active
     FROM user_accounts WHERE email = ?"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close(); $conn->close();
    respond(false, 'Invalid email or password.');
}

$user = $result->fetch_assoc();
$stmt->close();

// Check account is active
if (!(int)$user['is_active']) {
    $conn->close();
    respond(false, 'This account has been deactivated. Please contact an administrator.');
}

// Verify password
if (!password_verify($password, $user['password_hash'])) {
    $conn->close();
    respond(false, 'Invalid email or password.');
}

// Generate session token
$token     = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$sessionStmt = $conn->prepare(
    "INSERT INTO login_sessions (user_id, session_token, ip_address, user_agent, expires_at)
     VALUES (?, ?, ?, ?, ?)"
);
$sessionStmt->bind_param('issss', $user['user_id'], $token, $ipAddress, $userAgent, $expiresAt);
$sessionStmt->execute();
$sessionStmt->close();

// Update last login
$loginStmt = $conn->prepare(
    "UPDATE user_accounts SET last_login = NOW() WHERE user_id = ?"
);
$loginStmt->bind_param('i', $user['user_id']);
$loginStmt->execute();
$loginStmt->close();

$conn->close();

respond(true, 'Login successful.', [
    'token'      => $token,
    'expires_at' => $expiresAt,
    'user'       => [
        'user_id'  => (int)$user['user_id'],
        'name'     => $user['full_name'],
        'email'    => $user['email'],
        'role'     => $user['role'],
        'position' => $user['position']
    ]
]);