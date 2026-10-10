<?php
/**
 * delete_announcement.php
 *
 * Permanently deletes an announcement. Unlike packages,
 * announcements have no dependent records elsewhere in the
 * system, so this is a safe, unconditional hard delete.
 *
 * Expected POST fields:
 *   announcement_id   (int, required)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

function respond(bool $success, string $message): void {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$announcementId = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
if ($announcementId <= 0) respond(false, 'Invalid announcement ID.');

$conn = getDbConnection();

$stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
$stmt->bind_param('i', $announcementId);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    respond(true, 'Announcement deleted successfully.');
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to delete announcement: ' . $err);
}
