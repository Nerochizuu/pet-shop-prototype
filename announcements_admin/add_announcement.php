<?php
/**
 * add_announcement.php
 *
 * Admin posts a new announcement, which immediately becomes
 * visible on the customer home page (if is_active, which
 * defaults to true).
 *
 * Expected POST fields:
 *   title       (string, required)
 *   body        (string, required)
 *   tag         (string, required)  e.g. 'Promo', 'Update', 'Notice', 'Shop'
 *   tag_type    (string, required)  'promo' | 'info' | 'alert' | 'general'
 *   emoji       (string, optional)
 *   is_pinned   (int, optional) 1 or 0
 *   posted_by   (int, optional) admin's user_id
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

$title     = trim($_POST['title']    ?? '');
$body      = trim($_POST['body']     ?? '');
$tag       = trim($_POST['tag']      ?? '');
$tagType   = trim($_POST['tag_type'] ?? 'info');
$emoji     = trim($_POST['emoji']    ?? '') ?: '📋';
$isPinned  = isset($_POST['is_pinned']) ? (int)$_POST['is_pinned'] : 0;
$postedBy  = !empty($_POST['posted_by']) ? (int)$_POST['posted_by'] : null;

$errors = [];
if ($title === '') $errors[] = 'Title is required.';
if ($body === '')  $errors[] = 'Announcement body is required.';
if ($tag === '')   $errors[] = 'Category is required.';
$validTagTypes = ['promo', 'info', 'alert', 'general'];
if (!in_array($tagType, $validTagTypes, true)) $tagType = 'info';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

$stmt = $conn->prepare(
    "INSERT INTO announcements (title, body, tag, tag_type, emoji, is_pinned, posted_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param('sssssii', $title, $body, $tag, $tagType, $emoji, $isPinned, $postedBy);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    respond(true, 'Announcement posted successfully.', ['announcement_id' => $newId]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to post announcement: ' . $err);
}
