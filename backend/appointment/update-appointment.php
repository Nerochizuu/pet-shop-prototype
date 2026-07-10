<?php
/**
 * update_appointment.php
 *
 * Handles ADMIN actions on an existing appointment:
 *   - Confirm a pending booking
 *   - Assign or reassign a groomer
 *   - Change status (pending → confirmed → in_progress → completed)
 *   - Cancel a booking
 *   - Reschedule (change date/time)
 *
 * Expected POST fields:
 *   appointment_id     (int, required)
 *   action             (string, required) - 'confirm' | 'assign_staff' | 'set_status' | 'cancel' | 'reschedule'
 *   staff_id           (int, required if action = 'assign_staff')
 *   status             (string, required if action = 'set_status')
 *   appointment_date   (date, required if action = 'reschedule')
 *   appointment_time   (time, required if action = 'reschedule')
 *   admin_notes        (string, optional)
 *   changed_by         (string, optional, default 'admin')
 */

header('Content-Type: application/json');
require_once '../db_connect.php';
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

if ($appointmentId <= 0) {
    respond(false, 'Invalid appointment ID.');
}

$validActions = ['confirm', 'assign_staff', 'set_status', 'cancel', 'reschedule'];
if (!in_array($action, $validActions, true)) {
    respond(false, 'Invalid action.');
}

$conn = getDbConnection();

// Fetch current appointment to know its current status
$current = $conn->prepare("SELECT status FROM appointments WHERE appointment_id = ?");
$current->bind_param('i', $appointmentId);
$current->execute();
$currentResult = $current->get_result();

if ($currentResult->num_rows === 0) {
    $current->close();
    $conn->close();
    respond(false, 'Appointment not found.');
}
$currentStatus = $currentResult->fetch_assoc()['status'];
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
        if ($staffId <= 0) {
            respond(false, 'A valid staff_id is required to assign a groomer.');
        }
        $stmt = $conn->prepare("UPDATE appointments SET assigned_staff_id = ? WHERE appointment_id = ?");
        $stmt->bind_param('ii', $staffId, $appointmentId);
        $stmt->execute();
        $stmt->close();

        // Fetch groomer name for confirmation message
        $staffQuery = $conn->prepare("SELECT full_name FROM staff WHERE staff_id = ?");
        $staffQuery->bind_param('i', $staffId);
        $staffQuery->execute();
        $staffName = $staffQuery->get_result()->fetch_assoc()['full_name'] ?? 'Staff';
        $staffQuery->close();

        respond(true, "Groomer assigned: {$staffName}", ['groomer_name' => $staffName]);
        break;

    case 'set_status':
        $newStatus = trim($_POST['status'] ?? '');
        $validStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($newStatus, $validStatuses, true)) {
            respond(false, 'Invalid status value.');
        }
        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
        $stmt->bind_param('si', $newStatus, $appointmentId);
        $stmt->execute();
        $stmt->close();
        logStatusChange($conn, $appointmentId, $currentStatus, $newStatus, $changedBy);
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
        if ($newDate === '' || $newTime === '') {
            respond(false, 'Both date and time are required to reschedule.');
        }
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

/**
 * Helper: log every status transition for audit history
 */
function logStatusChange(mysqli $conn, int $appointmentId, string $oldStatus, string $newStatus, string $changedBy): void {
    $log = $conn->prepare(
        "INSERT INTO appointment_status_log (appointment_id, old_status, new_status, changed_by)
         VALUES (?, ?, ?, ?)"
    );
    $log->bind_param('isss', $appointmentId, $oldStatus, $newStatus, $changedBy);
    $log->execute();
    $log->close();
}