<?php
/**
 * get_analytics.php
 *
 * Returns every metric and chart dataset used on the admin
 * analytics.html page, computed live from the database:
 *
 *   - Metric cards: revenue growth, avg daily bookings,
 *     new customers, top service (all scoped to the current month)
 *   - Monthly sales trend (full current year)
 *   - Appointments by service (this month, as % of total)
 *   - Customer activity: new vs returning (last 5 months)
 *   - Inventory consumption (this month, top items by quantity used)
 *
 * Inventory consumption reads inventory_transactions using its real
 * columns: transaction_type ('deduct'), quantity_change (negative for
 * deductions, so ABS() is applied) and created_at.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db_connect.php';

$conn = getDbConnection();

$thisMonthStart = date('Y-m-01');
$today           = date('Y-m-d');
$dayOfMonth      = (int)date('j');
$thisMonth       = (int)date('n');
$thisYear        = (int)date('Y');
$prevMonthStart  = date('Y-m-01', strtotime('-1 month'));
$prevMonthEnd    = date('Y-m-t', strtotime('-1 month'));

// ══════════════════════════════════════════
// METRIC 1: Revenue growth (this month vs last month)
// ══════════════════════════════════════════
$curIncomeStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
     WHERE transaction_type = 'income' AND transaction_date >= ? AND transaction_date <= ?"
);
$curIncomeStmt->bind_param('ss', $thisMonthStart, $today);
$curIncomeStmt->execute();
$currentIncome = (float)$curIncomeStmt->get_result()->fetch_assoc()['total'];
$curIncomeStmt->close();

$prevIncomeStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
     WHERE transaction_type = 'income' AND transaction_date >= ? AND transaction_date <= ?"
);
$prevIncomeStmt->bind_param('ss', $prevMonthStart, $prevMonthEnd);
$prevIncomeStmt->execute();
$previousIncome = (float)$prevIncomeStmt->get_result()->fetch_assoc()['total'];
$prevIncomeStmt->close();

$revenueGrowth = $previousIncome > 0
    ? round((($currentIncome - $previousIncome) / $previousIncome) * 100, 1)
    : ($currentIncome > 0 ? 100 : 0);

// ══════════════════════════════════════════
// METRIC 2: Average daily bookings (this month so far)
// ══════════════════════════════════════════
$bookingsStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM appointments
     WHERE appointment_date >= ? AND appointment_date <= ? AND status != 'cancelled'"
);
$bookingsStmt->bind_param('ss', $thisMonthStart, $today);
$bookingsStmt->execute();
$totalBookings = (int)$bookingsStmt->get_result()->fetch_assoc()['cnt'];
$bookingsStmt->close();

$avgDailyBookings = $dayOfMonth > 0 ? round($totalBookings / $dayOfMonth, 1) : 0;

// ══════════════════════════════════════════
// METRIC 3: New customers this month
// ══════════════════════════════════════════
$newCustStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM customers WHERE created_at >= ? AND created_at <= ?"
);
$todayEnd = $today . ' 23:59:59';
$newCustStmt->bind_param('ss', $thisMonthStart, $todayEnd);
$newCustStmt->execute();
$newCustomers = (int)$newCustStmt->get_result()->fetch_assoc()['cnt'];
$newCustStmt->close();

// ══════════════════════════════════════════
// METRIC 4: Top service this month (by revenue)
// ══════════════════════════════════════════
$topServiceStmt = $conn->prepare(
    "SELECT s.service_name, SUM(t.amount) AS revenue
     FROM transactions t
     INNER JOIN appointments a ON t.appointment_id = a.appointment_id
     INNER JOIN services s ON a.service_id = s.service_id
     WHERE t.transaction_type = 'income' AND t.transaction_date >= ? AND t.transaction_date <= ?
     GROUP BY s.service_id
     ORDER BY revenue DESC
     LIMIT 1"
);
$topServiceStmt->bind_param('ss', $thisMonthStart, $today);
$topServiceStmt->execute();
$topServiceRow = $topServiceStmt->get_result()->fetch_assoc();
$topServiceStmt->close();

$topServiceName = $topServiceRow['service_name'] ?? '—';
$topServiceRevenue = (float)($topServiceRow['revenue'] ?? 0);
$topServicePct = $currentIncome > 0 ? round(($topServiceRevenue / $currentIncome) * 100) : 0;

// ══════════════════════════════════════════
// CHART 1: Monthly sales trend (full current year, nulls for future months)
// ══════════════════════════════════════════
$salesTrend = array_fill(0, 12, null);
$trendStmt = $conn->prepare(
    "SELECT MONTH(transaction_date) AS m, SUM(amount) AS total
     FROM transactions
     WHERE transaction_type = 'income' AND YEAR(transaction_date) = ?
     GROUP BY MONTH(transaction_date)"
);
$trendStmt->bind_param('i', $thisYear);
$trendStmt->execute();
$trendResult = $trendStmt->get_result();
while ($row = $trendResult->fetch_assoc()) {
    $monthIndex = (int)$row['m'] - 1;
    if ($monthIndex <= $thisMonth - 1) {
        $salesTrend[$monthIndex] = (float)$row['total'];
    }
}
// Ensure months up to and including the current month show at least 0, not null
for ($i = 0; $i < $thisMonth; $i++) {
    if ($salesTrend[$i] === null) $salesTrend[$i] = 0;
}
$trendStmt->close();

// ══════════════════════════════════════════
// CHART 2: Appointments by service, this month (as % of total appointments)
// ══════════════════════════════════════════
$serviceBreakdown = [];
$svcStmt = $conn->prepare(
    "SELECT s.service_name, COUNT(a.appointment_id) AS cnt
     FROM appointments a
     INNER JOIN services s ON a.service_id = s.service_id
     WHERE a.appointment_date >= ? AND a.appointment_date <= ? AND a.status != 'cancelled'
     GROUP BY s.service_id
     ORDER BY cnt DESC
     LIMIT 6"
);
$svcStmt->bind_param('ss', $thisMonthStart, $today);
$svcStmt->execute();
$svcResult = $svcStmt->get_result();
$svcRows = [];
$svcTotal = 0;
while ($row = $svcResult->fetch_assoc()) {
    $svcRows[] = $row;
    $svcTotal += (int)$row['cnt'];
}
foreach ($svcRows as $row) {
    $serviceBreakdown[] = [
        'name'    => $row['service_name'],
        'percent' => $svcTotal > 0 ? round(((int)$row['cnt'] / $svcTotal) * 100) : 0
    ];
}
$svcStmt->close();

// ══════════════════════════════════════════
// CHART 3: Customer activity — new vs returning, last 5 months
// ══════════════════════════════════════════
$customerActivity = ['labels' => [], 'new' => [], 'returning' => []];

for ($i = 4; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd   = date('Y-m-t', strtotime("-$i months"));
    $monthEndCapped = min($monthEnd, $today);
    $customerActivity['labels'][] = date('M', strtotime($monthStart));

    // New: customers created in this month
    $newStmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM customers WHERE created_at >= ? AND created_at <= ?"
    );
    $monthEndDateTime = $monthEndCapped . ' 23:59:59';
    $newStmt->bind_param('ss', $monthStart, $monthEndDateTime);
    $newStmt->execute();
    $newCount = (int)$newStmt->get_result()->fetch_assoc()['cnt'];
    $newStmt->close();
    $customerActivity['new'][] = $newCount;

    // Returning: customers who booked this month but were created before this month
    $retStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT a.customer_id) AS cnt
         FROM appointments a
         INNER JOIN customers c ON a.customer_id = c.customer_id
         WHERE a.appointment_date >= ? AND a.appointment_date <= ?
           AND c.created_at < ?
           AND a.status != 'cancelled'"
    );
    $retStmt->bind_param('sss', $monthStart, $monthEndCapped, $monthStart);
    $retStmt->execute();
    $retCount = (int)$retStmt->get_result()->fetch_assoc()['cnt'];
    $retStmt->close();
    $customerActivity['returning'][] = $retCount;
}

// ══════════════════════════════════════════
// CHART 4: Inventory consumption this month (top items deducted)
// ══════════════════════════════════════════
$inventoryConsumption = ['labels' => [], 'values' => []];
$invStmt = $conn->prepare(
    "SELECT i.item_name, SUM(ABS(it.quantity_change)) AS used
     FROM inventory_transactions it
     INNER JOIN inventory_items i ON it.item_id = i.item_id
     WHERE it.transaction_type = 'deduct' AND DATE(it.created_at) >= ? AND DATE(it.created_at) <= ?
     GROUP BY i.item_id, i.item_name
     ORDER BY used DESC
     LIMIT 6"
);
$invStmt->bind_param('ss', $thisMonthStart, $today);
$invStmt->execute();
$invResult = $invStmt->get_result();
while ($row = $invResult->fetch_assoc()) {
    $inventoryConsumption['labels'][] = $row['item_name'];
    $inventoryConsumption['values'][] = (int)$row['used'];
}
$invStmt->close();

$conn->close();

echo json_encode([
    'success' => true,
    'metrics' => [
        'revenue_growth'      => $revenueGrowth,
        'avg_daily_bookings'  => $avgDailyBookings,
        'new_customers'       => $newCustomers,
        'top_service_name'    => $topServiceName,
        'top_service_percent' => $topServicePct
    ],
    'charts' => [
        'sales_trend'          => $salesTrend,
        'service_breakdown'    => $serviceBreakdown,
        'customer_activity'    => $customerActivity,
        'inventory_consumption' => $inventoryConsumption
    ],
    'period' => [
        'month_label' => date('F Y'),
        'year'        => $thisYear
    ]
]);
