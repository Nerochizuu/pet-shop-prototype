<?php
/**
 * vaccine_reminders.php — the reminder rules, shared by the daily job,
 * the "Send due reminders now" button, and the per-record "Send reminder".
 *
 * Rule: a customer gets ONE automatic SMS for each vaccine whose
 * next_due_date is 3 days away. If a day's run was missed, the next run
 * still catches anything due within the next 1–3 days (the message then
 * says "in 2 days" / "tomorrow"). Each vaccine + due date is texted at
 * most once automatically — guaranteed by sms_log.dedupe_key (UNIQUE).
 * If the admin changes the due date, the new date gets its own reminder.
 */

require_once __DIR__ . '/sms_service.php';

const REMINDER_DAYS_BEFORE = 3;

function reminderRecordQuery(): string {
    return "SELECT vr.record_id, vr.next_due_date, vr.customer_id,
                   c.first_name, c.contact_number, c.is_active,
                   p.pet_name, vt.vaccine_name
            FROM vaccination_records vr
            INNER JOIN customers c      ON vr.customer_id = c.customer_id
            INNER JOIN pets p           ON vr.pet_id = p.pet_id
            INNER JOIN vaccine_types vt ON vr.vaccine_type_id = vt.vaccine_type_id";
}

/** Vaccines due in 1–3 days that have NOT had their automatic reminder yet. */
function findDueReminders(mysqli $conn): array {
    $from = date('Y-m-d', strtotime('+1 day'));
    $to   = date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days'));

    $stmt = $conn->prepare(
        reminderRecordQuery() . "
         LEFT JOIN sms_log s
                ON s.dedupe_key = CONCAT('auto:', vr.record_id, ':', vr.next_due_date)
         WHERE vr.next_due_date BETWEEN ? AND ?
           AND c.is_active = 1
           AND s.sms_id IS NULL
         ORDER BY vr.next_due_date, vr.record_id"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Writes the log row, then sends, then records the outcome. */
function processReminder(mysqli $conn, array $rec, string $type, string $sentBy): array {
    $phone   = normalizePhMobile($rec['contact_number']);
    $message = buildVaccineReminder($rec['first_name'], $rec['pet_name'], $rec['vaccine_name'], $rec['next_due_date']);
    $dedupe  = $type === 'auto_3day' ? 'auto:' . $rec['record_id'] . ':' . $rec['next_due_date'] : null;
    $phoneForLog = $phone ?? substr(preg_replace('/\s+/', '', (string)$rec['contact_number']), 0, 20);
    $provider = smsIsLiveMode() ? 'semaphore' : 'test-mode';

    // 1) Claim it. INSERT IGNORE + the UNIQUE dedupe_key means a second run
    //    that reaches the same reminder at the same moment gets 0 rows and skips.
    $claim = $conn->prepare(
        "INSERT IGNORE INTO sms_log
            (record_id, customer_id, phone_number, message, reminder_type, due_date,
             status, provider, dedupe_key, sent_by)
         VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?)"
    );
    $recordId   = (int)$rec['record_id'];
    $customerId = (int)$rec['customer_id'];
    $claim->bind_param('iisssssss', $recordId, $customerId, $phoneForLog, $message, $type,
                       $rec['next_due_date'], $provider, $dedupe, $sentBy);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $smsId   = $claim->insert_id;
    $claim->close();
    if (!$claimed) return ['record_id' => $recordId, 'status' => 'skipped', 'reason' => 'already sent'];

    // 2) Send (or mark failed if the number can't be used)
    $result = $phone === null
        ? ['status' => 'failed', 'provider_message_id' => null,
           'error' => 'Invalid PH mobile number: ' . substr((string)$rec['contact_number'], 0, 30)]
        : sendSms($phone, $message);

    // 3) Record the outcome
    $upd = $conn->prepare(
        "UPDATE sms_log SET status = ?, provider_message_id = ?, error_message = ? WHERE sms_id = ?"
    );
    $upd->bind_param('sssi', $result['status'], $result['provider_message_id'], $result['error'], $smsId);
    $upd->execute();
    $upd->close();

    return ['record_id' => $recordId, 'status' => $result['status'], 'phone' => $phoneForLog,
            'message' => $message, 'error' => $result['error']];
}

/** The daily job: text everyone whose vaccine is due in 3 days. */
function runVaccineReminders(mysqli $conn, string $sentBy = 'system'): array {
    $summary = ['checked' => 0, 'sent' => 0, 'simulated' => 0, 'failed' => 0, 'skipped' => 0,
                'mode' => smsIsLiveMode() ? 'live' : 'test', 'run_date' => date('Y-m-d H:i'), 'results' => []];

    foreach (findDueReminders($conn) as $rec) {
        $summary['checked']++;
        $r = processReminder($conn, $rec, 'auto_3day', $sentBy);
        $summary[$r['status']]++;
        $summary['results'][] = $r;
    }
    return $summary;
}

/** Admin's per-record "Send reminder" button (sends now, any due date). */
function sendManualReminder(mysqli $conn, int $recordId, string $sentBy): ?array {
    $stmt = $conn->prepare(reminderRecordQuery() . " WHERE vr.record_id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rec) return null;
    if (empty($rec['next_due_date'])) {
        return ['record_id' => $recordId, 'status' => 'failed', 'error' => 'This record has no next due date set.'];
    }
    return processReminder($conn, $rec, 'manual', $sentBy);
}
