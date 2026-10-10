<?php
/**
 * update_announcement.php
 *
 * Handles three admin actions on an existing announcement:
 *   - update_info: edit title, body, tag, emoji
 *   - toggle_pin: pin/unpin (pinned announcements always show first)
 *   - toggle_status: activate/deactivate (hides from customer page
 *     without deleting it)
 *
 * Expected POST fields:
 *   announcement_id  (int, required)
 *   action           (string, required) 'update_info' | 'toggle_pin' | 'toggle_status'
 *
 *   For update_info: title, body, tag, tag_type, emoji
 *   For toggle_pin: is_pinned (1 or 0)
 *   For toggle_status: is_active (1 or 0)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$announcementId = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
$action         = trim($_POST['action'] ?? '');

if ($announcementId <= 0) respond(false, 'Invalid announcement ID.');

$conn = getDbConnection();

if ($action === 'toggle_pin') {
    $isPinned = isset($_POST['is_pinned']) ? (int)$_POST['is_pinned'] : 0;
    $stmt = $conn->prepare("UPDATE announcements SET is_pinned = ? WHERE announcement_id = ?");
    $stmt->bind_param('ii', $isPinned, $announcementId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    respond(true, $isPinned ? 'Announcement pinned.' : 'Announcement unpinned.');

} elseif ($action === 'toggle_status') {
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
    $stmt = $conn->prepare("UPDATE announcements SET is_active = ? WHERE announcement_id = ?");
    $stmt->bind_param('ii', $isActive, $announcementId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    respond(true, $isActive ? 'Announcement activated.' : 'Announcement deactivated.');

} elseif ($action === 'update_info') {
    $title   = trim($_POST['title']    ?? '');
    $body    = trim($_POST['body']     ?? '');
    $tag     = trim($_POST['tag']      ?? '');
    $tagType = trim($_POST['tag_type'] ?? 'info');
    $emoji   = trim($_POST['emoji']    ?? '') ?: '📋';

    $errors = [];
    if ($title === '') $errors[] = 'Title is required.';
    if ($body === '')  $errors[] = 'Announcement body is required.';
    $validTagTypes = ['promo', 'info', 'alert', 'general'];
    if (!in_array($tagType, $validTagTypes, true)) $tagType = 'info';
    if (!empty($errors)) {
        $conn->close();
        respond(false, implode(' ', $errors));
    }

    $stmt = $conn->prepare(
        "UPDATE announcements SET title = ?, body = ?, tag = ?, tag_type = ?, emoji = ?
         WHERE announcement_id = ?"
    );
    $stmt->bind_param('sssssi', $title, $body, $tag, $tagType, $emoji, $announcementId);

    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        respond(true, 'Announcement updated successfully.');
    } else {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        respond(false, 'Failed to update announcement: ' . $err);
    }

} else {
    $conn->close();
    respond(false, 'Invalid action.');
}
