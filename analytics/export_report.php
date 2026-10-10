<?php
/**
 * export_report.php
 *
 * Generates a downloadable CSV for one of three report types,
 * for the "Export reports" section of analytics.html.
 *
 * GET params:
 *   type       (required) 'financial' | 'appointments' | 'inventory'
 *   date_from  (optional) YYYY-MM-DD, defaults to start of current month
 *   date_to    (optional) YYYY-MM-DD, defaults to today
 */

require_once __DIR__ . '/../db_connect.php';

$type     = trim($_GET['type']      ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['date_to']   ?? date('Y-m-d'));

$validTypes = ['financial', 'appointments', 'inventory'];
if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo 'Invalid report type.';
    exit;
}

$conn = getDbConnection();

// ── Set CSV download headers ──
$filename = "{$type}_report_{$dateFrom}_to_{$dateTo}.csv";
header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"{$filename}\"");

$output = fopen('php://output', 'w');

if ($type === 'financial') {
    fputcsv($output, ['Date', 'Type', 'Category', 'Description', 'Amount', 'Payment Method']);

    $stmt = $conn->prepare(
        "SELECT transaction_date, transaction_type, category, description, amount, payment_method
         FROM transactions
         WHERE transaction_date >= ? AND transaction_date <= ?
         ORDER BY transaction_date ASC"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['transaction_date'],
            ucfirst($row['transaction_type']),
            ucfirst($row['category']),
            $row['description'],
            number_format((float)$row['amount'], 2),
            $row['payment_method'] ? ucfirst($row['payment_method']) : ''
        ]);
    }
    $stmt->close();

} elseif ($type === 'appointments') {
    fputcsv($output, ['Date', 'Time', 'Customer', 'Pet', 'Service', 'Groomer', 'Status', 'Booking Type']);

    $stmt = $conn->prepare(
        "SELECT a.appointment_date, a.appointment_time, a.first_name, a.last_name,
                a.pet_name, s.service_name, st.full_name AS groomer_name,
                a.status, a.booking_type
         FROM appointments a
         INNER JOIN services s ON a.service_id = s.service_id
         LEFT JOIN staff st ON a.assigned_staff_id = st.staff_id
         WHERE a.appointment_date >= ? AND a.appointment_date <= ?
         ORDER BY a.appointment_date ASC, a.appointment_time ASC"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['appointment_date'],
            $row['appointment_time'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['pet_name'],
            $row['service_name'],
            $row['groomer_name'] ?? 'Unassigned',
            ucfirst($row['status']),
            ucfirst($row['booking_type'])
        ]);
    }
    $stmt->close();

} elseif ($type === 'inventory') {
    fputcsv($output, ['Date', 'Item', 'Action', 'Quantity', 'Performed By']);

    $stmt = $conn->prepare(
        "SELECT DATE(it.created_at) AS transaction_date, i.item_name,
                it.transaction_type AS action, ABS(it.quantity_change) AS quantity, it.performed_by
         FROM inventory_transactions it
         INNER JOIN inventory_items i ON it.item_id = i.item_id
         WHERE DATE(it.created_at) >= ? AND DATE(it.created_at) <= ?
         ORDER BY it.created_at ASC"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['transaction_date'],
            $row['item_name'],
            ucfirst($row['action']),
            $row['quantity'],
            $row['performed_by'] ?? ''
        ]);
    }
    $stmt->close();
}

fclose($output);
$conn->close();
