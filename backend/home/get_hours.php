<?php
/**
 * get_hours.php
 *
 * Returns all operating hours for the customer home page.
 * Also returns whether the shop is currently open based
 * on the current server time.
 */

header('Content-Type: application/json');
require_once '../db_connect.php';

$conn = getDbConnection();

$result = $conn->query(
    "SELECT day_of_week, day_name, open_time, close_time, is_closed
     FROM operating_hours
     ORDER BY day_of_week ASC"
);

$hours = [];
while ($row = $result->fetch_assoc()) {
    $hours[] = [
        'day_of_week' => (int)$row['day_of_week'],
        'day_name'    => $row['day_name'],
        'open_time'   => date('g:i A', strtotime($row['open_time'])),
        'close_time'  => date('g:i A', strtotime($row['close_time'])),
        'open_raw'    => $row['open_time'],
        'close_raw'   => $row['close_time'],
        'is_closed'   => (bool)$row['is_closed']
    ];
}

// Determine if currently open
$todayDow   = (int)date('w'); // 0=Sun, 6=Sat
$currentTime = date('H:i:s');
$isOpen     = false;

foreach ($hours as $h) {
    if ($h['day_of_week'] === $todayDow && !$h['is_closed']) {
        $isOpen = ($currentTime >= $h['open_raw'] && $currentTime < $h['close_raw']);
        break;
    }
}

$conn->close();

echo json_encode([
    'success'  => true,
    'hours'    => $hours,
    'is_open'  => $isOpen,
    'today'    => $todayDow
]);