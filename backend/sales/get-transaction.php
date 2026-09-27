<?php
/**
 * get_transactions.php
 *
 * Returns all transactions for the admin Sales & Finance page.
 * Also returns a financial summary (total income, expenses, profit).
 *
 * Optional GET params:
 *   type       - 'income' | 'expense' | 'all' (default)
 *   period     - 'today' | 'week' | 'month' | 'year' | 'all' (default)
 *   date_from  - YYYY-MM-DD (custom range start)
 *   date_to    - YYYY-MM-DD (custom range end)
 *   search     - partial match on description
 */

header('Content-Type: application/json');
require_once 'D:\xampp\htdocs\PET SHOP PROTOTYPE\db_connect.php';

$conn      = getDbConnection();
$type      = trim($_GET['type'] ?? 'all');
$period    = trim($_GET['period'] ?? 'month');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$search    = trim($_GET['search'] ?? '');

// ── Date range logic ──
$today = date('Y-m-d');
if ($dateFrom === '' || $dateTo === '') {
    switch ($period) {
        case 'today':
            $dateFrom = $dateTo = $today;
            break;
        case 'week':
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = $today;
            break;
        case 'month':
            $dateFrom = date('Y-m-01');
            $dateTo   = $today;
            break;
        case 'year':
            $dateFrom = date('Y-01-01');
            $dateTo   = $today;
            break;
        default: // 'all'
            $dateFrom = '2000-01-01';
            $dateTo   = $today;
    }
}

// ── Build query ──
$sql = "SELECT
            t.transaction_id,
            t.transaction_type,
            t.category,
            t.description,
            t.amount,
            t.payment_method,
            t.transaction_date,
            t.notes,
            t.recorded_by,
            t.created_at,
            a.first_name,
            a.last_name,
            s.service_name
        FROM transactions t
        LEFT JOIN appointments a ON t.appointment_id = a.appointment_id
        LEFT JOIN services s ON a.service_id = s.service_id
        WHERE t.transaction_date BETWEEN ? AND ?";

$params = [$dateFrom, $dateTo];
$types  = 'ss';

if ($type !== 'all') {
    $sql .= " AND t.transaction_type = ?";
    $params[] = $type;
    $types .= 's';
}

if ($search !== '') {
    $sql .= " AND t.description LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = [
        'transaction_id'   => (int)$row['transaction_id'],
        'transaction_type' => $row['transaction_type'],
        'category'         => $row['category'],
        'description'      => $row['description'],
        'amount'           => (float)$row['amount'],
        'payment_method'   => $row['payment_method'],
        'transaction_date' => $row['transaction_date'],
        'notes'            => $row['notes'],
        'recorded_by'      => $row['recorded_by'],
        'customer_name'    => $row['first_name']
                              ? $row['first_name'] . ' ' . $row['last_name']
                              : null,
        'service_name'     => $row['service_name']
    ];
}
$stmt->close();

// ── Financial summary for the selected period ──
$summaryStmt = $conn->prepare(
    "SELECT
        SUM(CASE WHEN transaction_type = 'income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS total_expenses,
        COUNT(CASE WHEN transaction_type = 'income'  THEN 1 END) AS income_count,
        COUNT(CASE WHEN transaction_type = 'expense' THEN 1 END) AS expense_count
     FROM transactions
     WHERE transaction_date BETWEEN ? AND ?"
);
$summaryStmt->bind_param('ss', $dateFrom, $dateTo);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$totalIncome   = (float)($summary['total_income']   ?? 0);
$totalExpenses = (float)($summary['total_expenses'] ?? 0);

// ── Revenue by service (income from appointments) ──
$revenueStmt = $conn->prepare(
    "SELECT
        s.service_name,
        SUM(t.amount) AS total,
        COUNT(t.transaction_id) AS count
     FROM transactions t
     INNER JOIN appointments a ON t.appointment_id = a.appointment_id
     INNER JOIN services s ON a.service_id = s.service_id
     WHERE t.transaction_type = 'income'
       AND t.transaction_date BETWEEN ? AND ?
     GROUP BY s.service_id
     ORDER BY total DESC"
);
$revenueStmt->bind_param('ss', $dateFrom, $dateTo);
$revenueStmt->execute();
$revenueResult = $revenueStmt->get_result();
$revenueByService = [];
while ($row = $revenueResult->fetch_assoc()) {
    $revenueByService[] = [
        'service_name' => $row['service_name'],
        'total'        => (float)$row['total'],
        'count'        => (int)$row['count']
    ];
}
$revenueStmt->close();
$conn->close();

echo json_encode([
    'success'            => true,
    'transactions'       => $transactions,
    'period'             => ['from' => $dateFrom, 'to' => $dateTo],
    'summary'            => [
        'total_income'    => $totalIncome,
        'total_expenses'  => $totalExpenses,
        'net_profit'      => $totalIncome - $totalExpenses,
        'income_count'    => (int)($summary['income_count'] ?? 0),
        'expense_count'   => (int)($summary['expense_count'] ?? 0)
    ],
    'revenue_by_service' => $revenueByService
]);