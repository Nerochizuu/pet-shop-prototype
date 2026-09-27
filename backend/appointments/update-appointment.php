<?php
/**
 * update_appointment.php
 *
 * Handles ADMIN actions on an existing appointment:
 *   - confirm, assign_staff, set_status, cancel, reschedule
 *
 * When status is set to 'completed', automatically creates
 * an income record in the transactions table.
 *
 * Expected POST fields:
 *   appointment_id     (int, required)
 *   action             (string, required)
 *   staff_id           (int, required if action = 'assign_staff')
 *   status             (string, required if action = 'set_status')
 *   appointment_date   (date, required if action = 'reschedule')
 *   appointment_time   (time, required if action = 'reschedule')
 *   payment_method     (string, optional) - used when completing
 *   admin_notes        (string, optional)
 *   changed_by         (string, optional, default 'admin')
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$action        = trim($_POST['action'] ?? '');
$changedBy     = trim($_POST['changed_by'] ?? 'admin');

if ($appointmentId <= 0) respond(false, 'Invalid appointment ID.');

$validActions = ['confirm', 'assign_staff', 'set_status', 'cancel', 'reschedule'];
if (!in_array($action, $validActions, true)) respond(false, 'Invalid action.');

$conn = getDbConnection();

// Fetch current appointment details (status + service price)
$current = $conn->prepare(
    "SELECT a.status, a.first_name, a.last_name, s.service_name, s.price
     FROM appointments a
     INNER JOIN services s ON a.service_id = s.service_id
     WHERE a.appointment_id = ?"
);
$current->bind_param('i', $appointmentId);
$current->execute();
$currentResult = $current->get_result();

if ($currentResult->num_rows === 0) {
    $current->close(); $conn->close();
    respond(false, 'Appointment not found.');
}
$apptData      = $currentResult->fetch_assoc();
$currentStatus = $apptData['status'];
$current->close();

switch ($action) {

    case 'confirm':
        $newStatus = 'confirmed';
        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
        $stmt->bind_param('si', $newStatus, $appointmentId);
        $stmt->execute();
        $stmt->close();
        logStatusChange($conn, $appointmentId, $currentStatus, $newStatus, $changedBy);
        respond(true, 'Appointment confirmed.', ['status' => $newStatus]);
        break;

    case 'assign_staff':
        $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
        if ($staffId <= 0) respond(false, 'A valid staff_id is required.');
        $stmt = $conn->prepare("UPDATE appointments SET assigned_staff_id = ? WHERE appointment_id = ?");
        $stmt->bind_param('ii', $staffId, $appointmentId);
        $stmt->execute();
        $stmt->close();
        $sq = $conn->prepare("SELECT full_name FROM staff WHERE staff_id = ?");
        $sq->bind_param('i', $staffId);
        $sq->execute();
        $staffName = $sq->get_result()->fetch_assoc()['full_name'] ?? 'Staff';
        $sq->close();
        respond(true, "Groomer assigned: {$staffName}", ['groomer_name' => $staffName]);
        break;

    case 'set_status':
        $newStatus = trim($_POST['status'] ?? '');
        $validStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($newStatus, $validStatuses, true)) respond(false, 'Invalid status value.');

        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
        $stmt->bind_param('si', $newStatus, $appointmentId);
        $stmt->execute();
        $stmt->close();
        logStatusChange($conn, $appointmentId, $currentStatus, $newStatus, $changedBy);

        // ── Auto-record sale when appointment is completed ──
        if ($newStatus === 'completed' && $currentStatus !== 'completed') {
            $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
            recordSaleOnCompletion($conn, $appointmentId, $apptData, $paymentMethod, $changedBy);
        }

        respond(true, "Status updated to {$newStatus}.", ['status' => $newStatus]);
        break;

    case 'cancel':
        $newStatus = 'cancelled';
        $notes = trim($_POST['admin_notes'] ?? '');
        $stmt = $conn->prepare("UPDATE appointments SET status = ?, admin_notes = ? WHERE appointment_id = ?");
        $stmt->bind_param('ssi', $newStatus, $notes, $appointmentId);
        $stmt->execute();
        $stmt->close();
        logStatusChange($conn, $appointmentId, $currentStatus, $newStatus, $changedBy);
        respond(true, 'Appointment cancelled.', ['status' => $newStatus]);
        break;

    case 'reschedule':
        $newDate = trim($_POST['appointment_date'] ?? '');
        $newTime = trim($_POST['appointment_time'] ?? '');
        if ($newDate === '' || $newTime === '') respond(false, 'Both date and time are required.');
        $stmt = $conn->prepare(
            "UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ?"
        );
        $stmt->bind_param('ssi', $newDate, $newTime, $appointmentId);
        $stmt->execute();
        $stmt->close();
        respond(true, 'Appointment rescheduled.', [
            'appointment_date' => $newDate,
            'appointment_time' => $newTime
        ]);
        break;
}

$conn->close();

// ── Helpers ──

function logStatusChange(mysqli $conn, int $apptId, string $old, string $new, string $by): void {
    $log = $conn->prepare(
        "INSERT INTO appointment_status_log (appointment_id, old_status, new_status, changed_by)
         VALUES (?, ?, ?, ?)"
    );
    $log->bind_param('isss', $apptId, $old, $new, $by);
    $log->execute();
    $log->close();
}

/**
 * Auto-creates an income transaction when an appointment is completed.
 * Uses the service price from the services table.
 */
function recordSaleOnCompletion(
    mysqli $conn,
    int    $appointmentId,
    array  $apptData,
    string $paymentMethod,
    string $recordedBy
): void {
    $amount      = (float)$apptData['price'];
    $description = $apptData['service_name'] . ' — '
                 . $apptData['first_name'] . ' ' . $apptData['last_name'];
    $category    = 'grooming'; // default; can be refined based on service type
    $today       = date('Y-m-d');

    $validMethods = ['cash', 'gcash', 'maya', 'card', 'other'];
    if (!in_array($paymentMethod, $validMethods, true)) $paymentMethod = 'cash';

    $stmt = $conn->prepare(
        "INSERT INTO transactions
            (transaction_type, category, description, amount, payment_method,
             appointment_id, recorded_by, transaction_date)
         VALUES ('income', ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssdsiss',
        $category,
        $description,
        $amount,
        $paymentMethod,
        $appointmentId,
        $recordedBy,
        $today
    );
    $stmt->execute();
    $stmt->close();
}