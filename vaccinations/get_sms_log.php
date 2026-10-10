<?php
/**
 * get_sms_log.php — SMS reminder panel on the Vaccinations page:
 * current mode (live / test), how many reminders are waiting to go out,
 * and the 50 most recent SMS. Requires a logged-in admin (contains
 * customers' phone numbers).
 *
 * POST: token
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../sms/vaccine_reminders.php';

$conn = getDbConnection();
requireAdminSession($conn);

$pending = count(findDueReminders($conn));

$result = $conn->query(
    "SELECT s.sms_id, s.phone_number, s.message, s.reminder_type, s.due_date, s.status,
            s.error_message, s.sent_by, s.created_at,
            c.first_name, c.last_name
     FROM sms_log s
     LEFT JOIN customers c ON s.customer_id = c.customer_id
     ORDER BY s.created_at DESC, s.sms_id DESC
     LIMIT 50"
);
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        'sms_id'        => (int)$row['sms_id'],
        'customer_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—',
        'phone_number'  => $row['phone_number'],
        'message'       => $row['message'],
        'reminder_type' => $row['reminder_type'],
        'due_date'      => $row['due_date'],
        'status'        => $row['status'],
        'error_message' => $row['error_message'],
        'sent_by'       => $row['sent_by'],
        'created_at'    => $row['created_at'],
    ];
}

$stats = $conn->query(
    "SELECT
        SUM(status = 'sent')      AS sent,
        SUM(status = 'simulated') AS simulated,
        SUM(status = 'failed')    AS failed
     FROM sms_log WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetch_assoc();
$conn->close();

echo json_encode([
    'success'       => true,
    'mode'          => smsIsLiveMode() ? 'live' : 'test',
    'days_before'   => REMINDER_DAYS_BEFORE,
    'pending_count' => $pending,
    'month_stats'   => [
        'sent'      => (int)($stats['sent'] ?? 0),
        'simulated' => (int)($stats['simulated'] ?? 0),
        'failed'    => (int)($stats['failed'] ?? 0),
    ],
    'logs'          => $logs,
]);
