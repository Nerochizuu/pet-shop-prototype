<?php
/**
 * update_user.php
 *
 * Handles admin actions on a user account:
 *   - Reset password (admin sets a new password for another user)
 *   - Activate / deactivate account
 *   - Update full name or position
 *
 * Expected POST fields:
 *   user_id      (int, required)    — the account being updated
 *   action       (string, required) — 'reset_password' | 'toggle_status' | 'update_info'
 *   admin_id     (int, required)    — logged-in admin performing the action
 *
 *   For reset_password:
 *     new_password  (string, required, min 8 chars)
 *
 *   For toggle_status:
 *     is_active     (int, required) 1 or 0
 *
 *   For update_info:
 *     full_name     (string, optional)
 *     position      (string, optional)
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

$userId  = isset($_POST['user_id'])  ? (int)$_POST['user_id']  : 0;
$action  = trim($_POST['action']     ?? '');
$adminId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;

if ($userId <= 0)  respond(false, 'Invalid user ID.');
if ($adminId <= 0) respond(false, 'Admin ID is required.');

$validActions = ['reset_password', 'toggle_status', 'update_info'];
if (!in_array($action, $validActions, true)) respond(false, 'Invalid action.');

$conn = getDbConnection();

// Prevent admin from deactivating their own account
if ($action === 'toggle_status' && $userId === $adminId) {
    $conn->close();
    respond(false, 'You cannot deactivate your own account.');
}

switch ($action) {

    case 'reset_password':
        $newPassword = trim($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            $conn->close();
            respond(false, 'New password must be at least 8 characters.');
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "UPDATE user_accounts SET password_hash = ? WHERE user_id = ?"
        );
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();

        // Log the reset
        $log = $conn->prepare(
            "INSERT INTO password_reset_log (target_user_id, reset_by) VALUES (?, ?)"
        );
        $log->bind_param('ii', $userId, $adminId);
        $log->execute();
        $log->close();

        // Invalidate all existing sessions for this user
        $del = $conn->prepare("DELETE FROM login_sessions WHERE user_id = ?");
        $del->bind_param('i', $userId);
        $del->execute();
        $del->close();

        $conn->close();
        respond(true, 'Password reset successfully. All active sessions have been cleared.');
        break;

    case 'toggle_status':
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
        $stmt = $conn->prepare(
            "UPDATE user_accounts SET is_active = ? WHERE user_id = ?"
        );
        $stmt->bind_param('ii', $isActive, $userId);
        $stmt->execute();
        $stmt->close();

        // If deactivating, clear sessions
        if ($isActive === 0) {
            $del = $conn->prepare("DELETE FROM login_sessions WHERE user_id = ?");
            $del->bind_param('i', $userId);
            $del->execute();
            $del->close();
        }

        $conn->close();
        respond(true, $isActive ? 'Account reactivated.' : 'Account deactivated.');
        break;

    case 'update_info':
        $fields = [];
        $params = [];
        $types  = '';

        if (!empty($_POST['full_name'])) {
            $fields[] = 'full_name = ?';
            $params[] = trim($_POST['full_name']);
            $types   .= 's';
        }
        if (isset($_POST['position'])) {
            $fields[] = 'position = ?';
            $params[] = trim($_POST['position']);
            $types   .= 's';
        }

        if (empty($fields)) {
            $conn->close();
            respond(false, 'Nothing to update.');
        }

        $params[] = $userId;
        $types   .= 'i';

        $stmt = $conn->prepare(
            "UPDATE user_accounts SET " . implode(', ', $fields) . " WHERE user_id = ?"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        respond(true, 'Account updated successfully.');
        break;
}