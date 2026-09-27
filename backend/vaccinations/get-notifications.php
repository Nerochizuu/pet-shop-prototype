<?php
/**
 * get_notifications.php
 *
 * Returns live notifications for the admin dashboard panel,
 * including:
 *   - Overdue vaccinations
 *   - Vaccinations due within 30 days
 *   - New pending appointments (last 24 hours)
 *   - Low / out-of-stock inventory items
 *
 * Used by dashboard-notifications.js to render the
 * notifications card on admin-dashboard.html.
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';
$conn  = getDbConnection();
$today = date('Y-m-d');
$in30  = date('Y-m-d', strtotime('+30 days'));
$last24 = date('Y-m-d H:i:s', strtotime('-24 hours'));

$notifications = [];

// ── 1. Overdue vaccinations ──
$overdueStmt = $conn->prepare(
    "SELECT vr.record_id, p.pet_name, c.first_name, c.last_name,
            vt.vaccine_name, vr.next_due_date
     FROM vaccination_records vr
     INNER JOIN pets p        ON vr.pet_id = p.pet_id
     INNER JOIN customers c   ON vr.customer_id = c.customer_id
     INNER JOIN vaccine_types vt ON vr.vaccine_type_id = vt.vaccine_type_id
     WHERE vr.next_due_date < ?
     ORDER BY vr.next_due_date ASC
     LIMIT 5"
);
$overdueStmt->bind_param('s', $today);
$overdueStmt->execute();
$overdueResult = $overdueStmt->get_result();
while ($row = $overdueResult->fetch_assoc()) {
    $notifications[] = [
        'type'     => 'vaccination_overdue',
        'priority' => 'high',
        'icon'     => 'syringe',
        'color'    => '#FBEAF0',
        'text_color' => '#993556',
        'message'  => "{$row['pet_name']}'s {$row['vaccine_name']} vaccine is overdue"
                      . " (due " . date('M j', strtotime($row['next_due_date'])) . ")",
        'meta'     => $row['first_name'] . ' ' . $row['last_name'],
        'record_id' => (int)$row['record_id']
    ];
}
$overdueStmt->close();

// ── 2. Vaccinations due within 30 days ──
$dueSoonStmt = $conn->prepare(
    "SELECT vr.record_id, p.pet_name, c.first_name, c.last_name,
            vt.vaccine_name, vr.next_due_date
     FROM vaccination_records vr
     INNER JOIN pets p        ON vr.pet_id = p.pet_id
     INNER JOIN customers c   ON vr.customer_id = c.customer_id
     INNER JOIN vaccine_types vt ON vr.vaccine_type_id = vt.vaccine_type_id
     WHERE vr.next_due_date BETWEEN ? AND ?
     ORDER BY vr.next_due_date ASC
     LIMIT 5"
);
$dueSoonStmt->bind_param('ss', $today, $in30);
$dueSoonStmt->execute();
$dueSoonResult = $dueSoonStmt->get_result();
while ($row = $dueSoonResult->fetch_assoc()) {
    $notifications[] = [
        'type'       => 'vaccination_due_soon',
        'priority'   => 'medium',
        'icon'       => 'syringe',
        'color'      => '#FAEEDA',
        'text_color' => '#854F0B',
        'message'    => "{$row['pet_name']}'s {$row['vaccine_name']} is due soon"
                       . " (" . date('M j', strtotime($row['next_due_date'])) . ")",
        'meta'       => $row['first_name'] . ' ' . $row['last_name'],
        'record_id'  => (int)$row['record_id']
    ];
}
$dueSoonStmt->close();

// ── 3. New pending appointments (last 24 hours) ──
$apptStmt = $conn->prepare(
    "SELECT a.appointment_id, a.first_name, a.last_name,
            s.service_name, a.appointment_date, a.appointment_time
     FROM appointments a
     INNER JOIN services s ON a.service_id = s.service_id
     WHERE a.status = 'pending'
       AND a.created_at >= ?
     ORDER BY a.created_at DESC
     LIMIT 5"
);
$apptStmt->bind_param('s', $last24);
$apptStmt->execute();
$apptResult = $apptStmt->get_result();
while ($row = $apptResult->fetch_assoc()) {
    $notifications[] = [
        'type'       => 'new_booking',
        'priority'   => 'medium',
        'icon'       => 'calendar-plus',
        'color'      => '#E6F1FB',
        'text_color' => '#185FA5',
        'message'    => "New booking from {$row['first_name']} {$row['last_name']}"
                       . " — {$row['service_name']}",
        'meta'       => date('M j', strtotime($row['appointment_date']))
                       . ' at ' . date('g:i A', strtotime($row['appointment_time'])),
        'appointment_id' => (int)$row['appointment_id']
    ];
}
$apptStmt->close();

// ── 4. Low / out-of-stock inventory ──
$invStmt = $conn->query(
    "SELECT item_name, current_stock, reorder_level
     FROM inventory_items
     WHERE current_stock <= reorder_level AND is_active = 1
     ORDER BY current_stock ASC
     LIMIT 5"
);
while ($row = $invStmt->fetch_assoc()) {
    $isOut = (int)$row['current_stock'] <= 0;
    $notifications[] = [
        'type'       => $isOut ? 'out_of_stock' : 'low_stock',
        'priority'   => $isOut ? 'high' : 'medium',
        'icon'       => 'box',
        'color'      => '#FAEEDA',
        'text_color' => '#854F0B',
        'message'    => $isOut
                        ? "{$row['item_name']} is out of stock"
                        : "{$row['item_name']} stock is low ({$row['current_stock']} left)",
        'meta'       => $isOut ? 'Needs restocking' : "Reorder level: {$row['reorder_level']}"
    ];
}

// Sort: high priority first
usort($notifications, fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) - ($b['priority'] === 'high' ? 0 : 1));

$conn->close();

echo json_encode([
    'success'       => true,
    'notifications' => $notifications,
    'counts'        => [
        'total'                => count($notifications),
        'vaccination_overdue'  => count(array_filter($notifications, fn($n) => $n['type'] === 'vaccination_overdue')),
        'vaccination_due_soon' => count(array_filter($notifications, fn($n) => $n['type'] === 'vaccination_due_soon')),
        'new_bookings'         => count(array_filter($notifications, fn($n) => $n['type'] === 'new_booking')),
        'inventory_alerts'     => count(array_filter($notifications, fn($n) => in_array($n['type'], ['low_stock', 'out_of_stock'])))
    ]
]);