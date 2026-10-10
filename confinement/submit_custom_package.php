<?php
/**
 * submit_custom_package.php
 *
 * Saves a customer's custom-built package selection
 * from the Build Your Own Package modal in packages.html.
 *
 * Expected POST fields:
 *   first_name          (string, required)
 *   last_name           (string, required)
 *   email               (string, required)
 *   contact_number      (string, optional)
 *   pet_name            (string, optional)
 *   selected_services   (JSON string, required) array of {name, price}
 *   total_price         (decimal, required)
 *   preferred_date      (date, optional)
 *   preferred_time      (time, optional)
 *   notes               (string, optional)
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

$firstName       = trim($_POST['first_name']        ?? '');
$lastName        = trim($_POST['last_name']         ?? '');
$email           = trim($_POST['email']             ?? '');
$contactNum      = trim($_POST['contact_number']    ?? '');
$petName         = trim($_POST['pet_name']          ?? '');
$servicesJson    = trim($_POST['selected_services'] ?? '');
$totalPrice      = isset($_POST['total_price'])      ? (float)$_POST['total_price'] : 0;
$preferredDate   = trim($_POST['preferred_date']    ?? '') ?: null;
$preferredTime   = trim($_POST['preferred_time']    ?? '') ?: null;
$notes           = trim($_POST['notes']             ?? '');

// Validate
$errors = [];
if ($firstName === '')  $errors[] = 'First name is required.';
if ($lastName === '')   $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
}
if ($servicesJson === '') $errors[] = 'Please select at least one service.';
if ($totalPrice <= 0)     $errors[] = 'Total price is invalid.';
if (!empty($errors)) respond(false, implode(' ', $errors));

// Validate services JSON
$services = json_decode($servicesJson, true);
if (!$services || !is_array($services) || count($services) === 0) {
    respond(false, 'No services selected.');
}

$conn = getDbConnection();
$conn->begin_transaction();

try {
    // Create or update customer record
    $customerId = null;
    if ($email !== '') {
        $custStmt = $conn->prepare(
            "INSERT INTO customers (first_name, last_name, email, contact_number)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 first_name     = VALUES(first_name),
                 last_name      = VALUES(last_name),
                 contact_number = VALUES(contact_number)"
        );
        $custStmt->bind_param('ssss', $firstName, $lastName, $email, $contactNum);
        $custStmt->execute();
        $customerId = $custStmt->insert_id;
        $custStmt->close();

        if ($customerId === 0) {
            $fetch = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
            $fetch->bind_param('s', $email);
            $fetch->execute();
            $customerId = (int)$fetch->get_result()->fetch_assoc()['customer_id'];
            $fetch->close();
        }
    }

    // Save the custom package order
    $stmt = $conn->prepare(
        "INSERT INTO custom_package_orders
            (first_name, last_name, email, contact_number, pet_name,
             selected_services, total_price, preferred_date, preferred_time,
             notes, customer_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssssdsssi',
        $firstName,
        $lastName,
        $email,
        $contactNum,
        $petName,
        $servicesJson,
        $totalPrice,
        $preferredDate,
        $preferredTime,
        $notes,
        $customerId
    );
    $stmt->execute();
    $orderId = $stmt->insert_id;
    $stmt->close();

    $conn->commit();

    $serviceCount = count($services);

    respond(true,
        "Your custom package has been submitted! 🎨 " .
        "We've received your selection of {$serviceCount} service" .
        ($serviceCount > 1 ? 's' : '') .
        " (total: ₱" . number_format($totalPrice, 0) . "). " .
        "Our team will confirm your booking shortly.",
        [
            'order_id'    => $orderId,
            'customer_id' => $customerId,
            'total_price' => $totalPrice,
            'services'    => $services
        ]
    );

} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Failed to submit package: ' . $e->getMessage());
} finally {
    $conn->close();
}