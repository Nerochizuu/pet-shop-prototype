/**
 * analytics-charts.js
 *
 * Loads live analytics data from get_analytics.php and renders
 * the metric cards + all 4 charts. Replaces the original static
 * Chart.js data with real figures computed from the database.
 */

const brown = '#1F8FA0', light = '#3BBFCE', mid = '#8ED8E2';
const gridColor = 'rgba(59,191,206,0.1)';
const tickStyle = { font: { size: 11 }, color: '#5A8A9A' };

function loadAnalytics() {
    fetch('backend/analytics/get_analytics.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load analytics:', data.message);
                return;
            }
            updateMetricCards(data.metrics);
            renderSalesTrendChart(data.charts.sales_trend);
            renderServiceChart(data.charts.service_breakdown);
            renderCustomerChart(data.charts.customer_activity);
            renderInventoryChart(data.charts.inventory_consumption);

            const periodEl = document.getElementById('currentPeriodLabel');
            if (periodEl) periodEl.textContent = data.period.month_label;
        })
        .catch(err => console.error('Network error loading analytics:', err));
}

function updateMetricCards(m) {
    const growthEl   = document.getElementById('metricRevenueGrowth');
    const bookingsEl = document.getElementById('metricAvgBookings');
    const newCustEl  = document.getElementById('metricNewCustomers');
    const topSvcEl   = document.getElementById('metricTopService');
    const topSvcSubEl = document.getElementById('metricTopServiceSub');

    if (growthEl) {
        const sign = m.revenue_growth >= 0 ? '+' : '';
        growthEl.textContent = `${sign}${m.revenue_growth}%`;
    }
    if (bookingsEl)  bookingsEl.textContent  = m.avg_daily_bookings;
    if (newCustEl)   newCustEl.textContent   = m.new_customers;
    if (topSvcEl)    topSvcEl.textContent    = m.top_service_name;
    if (topSvcSubEl) topSvcSubEl.textContent = `${m.top_service_percent}% of revenue`;
}

let salesTrendChartInstance, serviceChartInstance, customerChartInstance, inventoryChartInstance;

function renderSalesTrendChart(values) {
    const ctx = document.getElementById('salesTrendChart');
    if (!ctx) return;
    if (salesTrendChartInstance) salesTrendChartInstance.destroy();

    salesTrendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
            datasets: [{
                label: 'Sales', data: values,
                borderColor: '#1F8FA0', backgroundColor: 'rgba(59,191,206,0.1)',
                tension: 0.4, fill: true, pointBackgroundColor: brown
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: tickStyle },
                y: { grid: { color: gridColor }, ticks: { ...tickStyle, callback: v => '₱' + (v / 1000).toFixed(0) + 'k' } }
            }
        }
    });
}

function renderServiceChart(breakdown) {
    const ctx = document.getElementById('serviceChart');
    if (!ctx) return;
    if (serviceChartInstance) serviceChartInstance.destroy();

    const labels = breakdown.length ? breakdown.map(s => s.name) : ['No data yet'];
    const values = breakdown.length ? breakdown.map(s => s.percent) : [0];

    serviceChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: [brown, light, mid, '#1B2A4A', '#5A8A9A', '#A8CDD4'], borderRadius: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: tickStyle },
                y: { grid: { color: gridColor }, ticks: { ...tickStyle, callback: v => v + '%' } }
            }
        }
    });
}

function renderCustomerChart(activity) {
    const ctx = document.getElementById('customerChart');
    if (!ctx) return;
    if (customerChartInstance) customerChartInstance.destroy();

    customerChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: activity.labels,
            datasets: [
                { label: 'New', data: activity.new, backgroundColor: brown, borderRadius: 4 },
                { label: 'Returning', data: activity.returning, backgroundColor: light, borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 }, color: '#5A8A9A' } } },
            scales: {
                x: { grid: { display: false }, ticks: tickStyle },
                y: { grid: { color: gridColor }, ticks: tickStyle }
            }
        }
    });
}

function renderInventoryChart(consumption) {
    const ctx = document.getElementById('inventoryChart');
    if (!ctx) return;
    if (inventoryChartInstance) inventoryChartInstance.destroy();

    const labels = consumption.labels.length ? consumption.labels : ['No data yet'];
    const values = consumption.values.length ? consumption.values : [0];

    inventoryChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{ label: 'Used this month', data: values, backgroundColor: brown, borderRadius: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: tickStyle },
                y: { grid: { color: gridColor }, ticks: tickStyle }
            }
        }
    });
}

// ── Export report buttons ──
function exportReport(type) {
    window.location.href = `backend/analytics/export_report.php?type=${type}`;
}

document.addEventListener('DOMContentLoaded', loadAnalytics);
