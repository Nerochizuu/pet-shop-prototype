<?php
/**
 * update_confinement.php
 *
 * Admin updates a confinement admission's status:
 *   pending → admitted → under_care → discharged
 *   or cancelled at any point.
 *
 * When status is set to 'discharged', automatically:
 *   1. Sets discharge_date to today
 *   2. Calculates total days confined (minimum 1 day)
 *   3. Records an income transaction (rate_per_day × days)
 *
 * Expected POST fields:
 *   admission_id   (int, required)
 *   status         (string, required) pending|admitted|under_care|discharged|cancelled
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

$admissionId = isset($_POST['admission_id']) ? (int)$_POST['admission_id'] : 0;
$newStatus   = trim($_POST['status'] ?? '');

if ($admissionId <= 0) respond(false, 'Invalid admission ID.');

$validStatuses = ['pending', 'admitted', 'under_care', 'discharged', 'cancelled'];
if (!in_array($newStatus, $validStatuses, true)) respond(false, 'Invalid status value.');

$conn = getDbConnection();

// Fetch current admission
$current = $conn->prepare(
    "SELECT status, rate_per_day, admission_date, first_name, last_name, pet_name, ward_type
     FROM confinement_admissions WHERE admission_id = ?"
);
$current->bind_param('i', $admissionId);
$current->execute();
$result = $current->get_result();
if ($result->num_rows === 0) {
    $current->close(); $conn->close();
    respond(false, 'Admission not found.');
}
$admission     = $result->fetch_assoc();
$currentStatus = $admission['status'];
$current->close();

if ($newStatus === 'discharged' && $currentStatus !== 'discharged') {
    // ── Discharge flow: set discharge_date + auto-record sale ──
    $today = date('Y-m-d');

    $stmt = $conn->prepare(
        "UPDATE confinement_admissions SET status = ?, discharge_date = ? WHERE admission_id = ?"
    );
    $stmt->bind_param('ssi', $newStatus, $today, $admissionId);
    $stmt->execute();
    $stmt->close();

    // Calculate days confined (minimum 1 day, even for same-day discharge)
    $admissionDate = new DateTime($admission['admission_date']);
    $dischargeDate = new DateTime($today);
    $days = max(1, (int)$admissionDate->diff($dischargeDate)->days);

    $amount      = (float)$admission['rate_per_day'] * $days;
    $description = 'Confinement — ' . $admission['ward_type'] . ' for ' . $admission['pet_name']
                 . ' (' . $admission['first_name'] . ' ' . $admission['last_name'] . '), '
                 . $days . ' day' . ($days > 1 ? 's' : '');

    $txStmt = $conn->prepare(
        "INSERT INTO transactions
            (transaction_type, category, description, amount, payment_method,
             recorded_by, transaction_date)
         VALUES ('income', 'confinement', ?, ?, 'cash', 'admin', ?)"
    );
    $txStmt->bind_param('sds', $description, $amount, $today);
    $txStmt->execute();
    $txStmt->close();

    $conn->close();
    respond(true, "{$admission['pet_name']} discharged. Billed for {$days} day(s): ₱" . number_format($amount, 2), [
        'status' => $newStatus,
        'days'   => $days,
        'amount' => $amount
    ]);
} else {
    // ── All other status changes ──
    $stmt = $conn->prepare("UPDATE confinement_admissions SET status = ? WHERE admission_id = ?");
    $stmt->bind_param('si', $newStatus, $admissionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    respond(true, "Admission #{$admissionId} updated to {$newStatus}.", ['status' => $newStatus]);
}
