<?php
/**
 * logout.php
 *
 * Invalidates the current session token.
 * The client should delete the token from sessionStorage after calling this.
 *
 * Expected POST fields:
 *   token  (string, required) — the session token to invalidate
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

function respond(bool $success, string $message): void {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$token = trim($_POST['token'] ?? '');
if ($token === '') respond(false, 'Token is required.');

$conn = getDbConnection();

$stmt = $conn->prepare("DELETE FROM login_sessions WHERE session_token = ?");
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->close();
$conn->close();

respond(true, 'Logged out successfully.');