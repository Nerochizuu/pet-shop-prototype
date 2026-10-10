<?php
/**
 * get_announcements_admin.php
 *
 * Returns ALL announcements (active and inactive) for the
 * admin announcements.html page — unlike the customer-facing
 * get_announcements.php, which only returns active ones.
 *
 * Optional GET params:
 *   status  - 'active' | 'inactive' | 'all' (default)
 *   search  - partial match on title or body
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn   = getDbConnection();
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            a.announcement_id, a.title, a.body, a.tag, a.tag_type,
            a.emoji, a.is_pinned, a.is_active, a.created_at, a.updated_at,
            u.full_name AS posted_by_name
        FROM announcements a
        LEFT JOIN user_accounts u ON a.posted_by = u.user_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($status === 'active') {
    $sql .= " AND a.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND a.is_active = 0";
}

if ($search !== '') {
    $sql .= " AND (a.title LIKE ? OR a.body LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}

$sql .= " ORDER BY a.is_pinned DESC, a.created_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$announcements = [];
while ($row = $result->fetch_assoc()) {
    $announcements[] = [
        'announcement_id' => (int)$row['announcement_id'],
        'title'           => $row['title'],
        'body'            => $row['body'],
        'tag'             => $row['tag'],
        'tag_type'        => $row['tag_type'],
        'emoji'           => $row['emoji'],
        'is_pinned'       => (bool)$row['is_pinned'],
        'is_active'       => (bool)$row['is_active'],
        'posted_by_name'  => $row['posted_by_name'],
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active,
        SUM(is_active = 0) AS inactive,
        SUM(is_pinned = 1) AS pinned
     FROM announcements"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success'       => true,
    'announcements' => $announcements,
    'summary'       => [
        'total'    => (int)$summary['total'],
        'active'   => (int)$summary['active'],
        'inactive' => (int)$summary['inactive'],
        'pinned'   => (int)$summary['pinned']
    ]
]);
