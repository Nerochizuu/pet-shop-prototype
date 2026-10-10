<?php
/**
 * sms_service.php — sending SMS through Semaphore (Philippine SMS gateway).
 *
 * Configuration (Railway → web service → Variables; or leave unset locally):
 *   SEMAPHORE_API_KEY   Your Semaphore API key. If NOT set, the system runs in
 *                       TEST MODE: messages are logged as "simulated" and no
 *                       SMS is actually sent (no credits used).
 *   SMS_SENDER_NAME     Optional approved sender name (e.g. PAWPRIORITY).
 *                       Leave unset to use your account's default sender.
 *   CLINIC_PHONE        Optional clinic number shown in the SMS.
 *   SEMAPHORE_API_URL   Optional; only for testing against a mock server.
 *
 * Semaphore API: POST https://api.semaphore.co/api/v4/messages with form
 * fields apikey, number, message, sendername. 160 characters = 1 credit.
 */

function smsEnv(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || trim($v) === '') ? $default : trim($v);
}

function smsIsLiveMode(): bool {
    return smsEnv('SEMAPHORE_API_KEY') !== '';
}

/**
 * Turns whatever was typed in the booking form into 09XXXXXXXXX.
 * Accepts "+63 917 123 4567", "639171234567", "0917-123-4567", "9171234567".
 * Returns null if it isn't a valid Philippine mobile number.
 */
function normalizePhMobile(?string $raw): ?string {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (preg_match('/^639\d{9}$/', $digits)) return '0' . substr($digits, 2);
    if (preg_match('/^09\d{9}$/', $digits))  return $digits;
    if (preg_match('/^9\d{9}$/', $digits))   return '0' . $digits;
    return null;
}

/**
 * Builds the reminder text, kept within 160 characters (1 SMS credit).
 * Uses only plain characters so the SMS isn't sent as Unicode (which
 * would cut the limit to 70 characters per credit).
 */
function buildVaccineReminder(string $ownerFirstName, string $petName, string $vaccineName,
                              string $dueDate, ?string $today = null): string {
    $today   = $today ?? date('Y-m-d');
    $days    = (int) round((strtotime($dueDate) - strtotime($today)) / 86400);
    $dueText = date('M j', strtotime($dueDate));

    if ($days > 1)       $when = "is due on $dueText (in $days days)";
    elseif ($days === 1) $when = "is due tomorrow, $dueText";
    elseif ($days === 0) $when = "is due today, $dueText";
    else                 $when = "was due on $dueText";

    $first = smsShorten($ownerFirstName, 15);
    $pet   = smsShorten($petName, 15);
    $vac   = smsShorten($vaccineName, 20);
    $phone = smsEnv('CLINIC_PHONE', '0917 123 4567');

    $candidates = [
        "Hi $first! PawPriority reminder: $pet's $vac vaccine $when. Please visit or book with us. Questions? Call $phone.",
        "Hi $first! PawPriority reminder: $pet's $vac vaccine $when. Call $phone.",
        "PawPriority: $pet's $vac vaccine $when. Call $phone.",
    ];
    foreach ($candidates as $msg) {
        if (strlen($msg) <= 160) return smsNeverStartsWithTest($msg);
    }
    return smsNeverStartsWithTest(substr($candidates[2], 0, 160));
}

function smsShorten(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    // Replace characters outside plain GSM text (emoji etc.) so the SMS stays 160-char
    $s = (string) preg_replace('/[^\x20-\x7E\x{00F1}\x{00D1}]/u', '', $s);
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '.' : $s;
    }
    return strlen($s) > $max ? rtrim(substr($s, 0, $max - 1)) . '.' : $s;
}

// Semaphore silently drops messages that begin with "TEST".
function smsNeverStartsWithTest(string $msg): string {
    return stripos($msg, 'test') === 0 ? 'Hi! ' . $msg : $msg;
}

/**
 * Sends one SMS. Returns:
 *   ['status' => 'sent'|'failed'|'simulated', 'provider_message_id' => ?, 'error' => ?]
 */
function sendSms(string $phone09, string $message): array {
    if (!smsIsLiveMode()) {
        return ['status' => 'simulated', 'provider_message_id' => null, 'error' => null];
    }

    $fields = [
        'apikey'  => smsEnv('SEMAPHORE_API_KEY'),
        'number'  => $phone09,
        'message' => $message,
    ];
    if (smsEnv('SMS_SENDER_NAME') !== '') $fields['sendername'] = smsEnv('SMS_SENDER_NAME');

    $url  = smsEnv('SEMAPHORE_API_URL', 'https://api.semaphore.co/api/v4/messages');
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => 'failed', 'provider_message_id' => null, 'error' => 'Network error: ' . $curlErr];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
        if ($raw === false) {
            return ['status' => 'failed', 'provider_message_id' => null, 'error' => 'Network error contacting Semaphore'];
        }
    }

    $data = json_decode($raw, true);

    // Success: a JSON list with one object per message, each with message_id + status
    if ($httpCode >= 200 && $httpCode < 300 && is_array($data) && isset($data[0]['message_id'])) {
        $providerStatus = strtolower((string)($data[0]['status'] ?? ''));
        if (in_array($providerStatus, ['failed', 'refunded'], true)) {
            return ['status' => 'failed', 'provider_message_id' => (string)$data[0]['message_id'],
                    'error' => 'Semaphore status: ' . $data[0]['status']];
        }
        return ['status' => 'sent', 'provider_message_id' => (string)$data[0]['message_id'], 'error' => null];
    }

    // Anything else is an error; keep a short, readable reason for the SMS log
    $reason = is_array($data) ? json_encode($data) : trim(strip_tags((string)$raw));
    return ['status' => 'failed', 'provider_message_id' => null,
            'error' => substr("HTTP $httpCode: " . $reason, 0, 250)];
}
