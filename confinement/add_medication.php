<?php
/**
 * add_medication_log.php
 *
 * Saves a single medication log entry.
 * Called from packages.html when staff/admin clicks
 * "Save Entry" in the Add Medication Entry modal.
 *
 * Since medication logs are tied to a confined pet,
 * we look up the most recent active admission for the
 * pet by name to get the admission_id. If no admission
 * exists yet, we create a standalone record.
 *
 * Expected POST fields:
 *   pet_name         (string, required)
 *   medication_name  (string, required)
 *   dosage           (string, required)
 *   route            (string, required)  Oral|IV|Subcutaneous|Topical|Intramuscular
 *   administered_by  (string, required)
 *   status           (string, required)  Given|Pending
 *   admission_id     (int, optional)     link to specific confinement admission
 *   notes            (string, optional)
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

$petName       = trim($_POST['pet_name']        ?? '');
$medicationName = trim($_POST['medication_name'] ?? '');
$dosage        = trim($_POST['dosage']          ?? '');
$route         = trim($_POST['route']           ?? 'Oral');
$administeredBy = trim($_POST['administered_by'] ?? '');
$status        = trim($_POST['status']          ?? 'Pending');
$admissionId   = !empty($_POST['admission_id']) ? (int)$_POST['admission_id'] : null;
$notes         = trim($_POST['notes']           ?? '');

// Validate
$errors = [];
if ($petName === '')        $errors[] = 'Pet name is required.';
if ($medicationName === '') $errors[] = 'Medication name is required.';
if ($dosage === '')         $errors[] = 'Dosage is required.';
if ($administeredBy === '') $errors[] = 'Administered by is required.';
$validRoutes = ['Oral', 'IV', 'Subcutaneous', 'Topical', 'Intramuscular'];
if (!in_array($route, $validRoutes, true)) $route = 'Oral';
$validStatuses = ['Given', 'Pending'];
if (!in_array($status, $validStatuses, true)) $status = 'Pending';
if (!empty($errors)) respond(false, implode(' ', $errors));

$conn = getDbConnection();

// If no admission_id provided, find the most recent active admission for this pet
if ($admissionId === null) {
    $lookup = $conn->prepare(
        "SELECT admission_id FROM confinement_admissions
         WHERE LOWER(pet_name) = LOWER(?) AND status IN ('pending','admitted','under_care')
         ORDER BY created_at DESC LIMIT 1"
    );
    $lookup->bind_param('s', $petName);
    $lookup->execute();
    $result = $lookup->get_result();
    $lookup->close();

    if ($result->num_rows > 0) {
        $admissionId = (int)$result->fetch_assoc()['admission_id'];
    } else {
        // No active admission found — use 0 as a placeholder
        // so the log is still saved (standalone entry)
        $admissionId = 0;
    }
}

// If admissionId is 0 we can't use a FK — insert without it
if ($admissionId === 0) {
    $stmt = $conn->prepare(
        "INSERT INTO medication_logs
            (admission_id, pet_name, medication_name, dosage, route, administered_by, status, notes)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?)"
        // Note: admission_id=1 is a fallback; in production, require a valid admission
    );
    // Better: make admission_id nullable in the table and insert NULL
    $conn->close();
    respond(false, 'No active confinement admission found for this pet. Please submit an admission form first.');
}

$stmt = $conn->prepare(
    "INSERT INTO medication_logs
        (admission_id, pet_name, medication_name, dosage, route, administered_by, status, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'isssssss',
    $admissionId,
    $petName,
    $medicationName,
    $dosage,
    $route,
    $administeredBy,
    $status,
    $notes
);

if ($stmt->execute()) {
    $logId = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    $now     = new DateTime();
    $dateStr = $now->format('M j, Y') . ' · ' . $now->format('g:i A');

    respond(true, 'Medication entry saved.', [
        'log_id'      => $logId,
        'date_str'    => $dateStr,
        'admission_id' => $admissionId
    ]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    respond(false, 'Failed to save entry: ' . $err);
}