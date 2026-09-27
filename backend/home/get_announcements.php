<?php
/**
 * get_announcements.php
 *
 * Returns active announcements for the customer home page.
 * Pinned announcements always appear first.
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn = getDbConnection();

$result = $conn->query(
    "SELECT
        announcement_id, title, body, tag, tag_type,
        emoji, is_pinned, created_at
     FROM announcements
     WHERE is_active = 1
     ORDER BY is_pinned DESC, created_at DESC
     LIMIT 8"
);

$announcements = [];
while ($row = $result->fetch_assoc()) {
    $announcements[] = [
        'id'        => (int)$row['announcement_id'],
        'title'     => $row['title'],
        'body'      => $row['body'],
        'tag'       => $row['tag'],
        'tagType'   => $row['tag_type'],
        'emoji'     => $row['emoji'],
        'isPinned'  => (bool)$row['is_pinned'],
        'date'      => date('M j, Y', strtotime($row['created_at']))
    ];
}

$conn->close();

echo json_encode(['success' => true, 'announcements' => $announcements]);