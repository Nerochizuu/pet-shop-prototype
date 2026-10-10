<?php
/**
 * get_users.php
 *
 * Returns all user accounts for the admin user-accounts.html table.
 * Each record includes name, email, role, position, status, last login.
 *
 * Optional GET params:
 *   role    - 'admin' | 'staff' | 'all' (default)
 *   status  - 'active' | 'inactive' | 'all' (default)
 *   search  - partial match on name or email
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn   = getDbConnection();
$role   = trim($_GET['role']   ?? 'all');
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.role,
            u.position,
            u.is_active,
            u.last_login,
            u.created_at,
            c.full_name AS created_by_name
        FROM user_accounts u
        LEFT JOIN user_accounts c ON u.created_by = c.user_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($role !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $role;
    $types   .= 's';
}

if ($status === 'active') {
    $sql .= " AND u.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND u.is_active = 0";
}

if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
    $types   .= 'ss';
}

$sql .= " ORDER BY u.role ASC, u.full_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'user_id'         => (int)$row['user_id'],
        'full_name'       => $row['full_name'],
        'email'           => $row['email'],
        'role'            => $row['role'],
        'position'        => $row['position'],
        'is_active'       => (bool)$row['is_active'],
        'last_login'      => $row['last_login'],
        'created_at'      => $row['created_at'],
        'created_by_name' => $row['created_by_name']
    ];
}
$stmt->close();

// Summary counts
$countResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(role = 'admin') AS admins,
        SUM(role = 'staff') AS staff,
        SUM(is_active = 0)  AS inactive
     FROM user_accounts"
);
$summary = $countResult->fetch_assoc();

$conn->close();

echo json_encode([
    'success' => true,
    'users'   => $users,
    'summary' => [
        'total'    => (int)$summary['total'],
        'admins'   => (int)$summary['admins'],
        'staff'    => (int)$summary['staff'],
        'inactive' => (int)$summary['inactive']
    ]
]);
