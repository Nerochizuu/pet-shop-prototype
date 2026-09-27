/**
 * sales-table.js
 *
 * Pulls live transaction data from get_transactions.php
 * and renders it into the admin sales.html table.
 * Also updates the metric cards and revenue breakdown.
 */

let currentSalesType   = 'all';
let currentSalesPeriod = 'month';

function loadTransactions(type = 'all', period = 'month') {
    currentSalesType   = type;
    currentSalesPeriod = period;

    const search = document.getElementById('salesSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ type, period });
    if (search) params.append('search', search);

    fetch(`backend/sales/get-transaction.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load transactions:', data.message);
                return;
            }
            renderTransactionsTable(data.transactions);
            updateSalesMetrics(data.summary);
            updateRevenueBreakdown(data.revenue_by_service);
            updateSalesCount(data.transactions.length);
        })
        .catch(err => console.error('Network error loading transactions:', err));
}

function renderTransactionsTable(transactions) {
    const tbody = document.getElementById('salesTableBody');
    if (!tbody) return;

    if (transactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No transactions found for this period.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = transactions.map(tx => `
        <tr>
            <td>
                <div class="td-name">#TXN-${String(tx.transaction_id).padStart(4, '0')}</div>
            </td>
            <td>
                ${tx.customer_name ? escapeHtml(tx.customer_name) : '<span style="color:#A8CDD4;">—</span>'}
                ${tx.service_name ? `<div class="td-sub">${escapeHtml(tx.service_name)}</div>` : ''}
            </td>
            <td>${escapeHtml(tx.description)}</td>
            <td>${formatDate(tx.transaction_date)}</td>
            <td style="font-weight:500; color:${tx.transaction_type === 'income' ? '#1D9E75' : '#E24B4A'};">
                ${tx.transaction_type === 'income' ? '+' : '-'}&#8369;${formatMoney(tx.amount)}
            </td>
            <td>
                <span class="badge ${tx.transaction_type === 'income' ? 'badge-green' : 'badge-red'}">
                    ${capitalize(tx.transaction_type)}
                </span>
            </td>
        </tr>
    `).join('');
}

function updateSalesMetrics(summary) {
    const incomeEl  = document.getElementById('metricTotalSales');
    const expenseEl = document.getElementById('metricTotalExpenses');
    const profitEl  = document.getElementById('metricNetProfit');
    const todayEl   = document.getElementById('metricTodaySales');

    if (incomeEl)  incomeEl.textContent  = '₱' + formatMoney(summary.total_income);
    if (expenseEl) expenseEl.textContent = '₱' + formatMoney(summary.total_expenses);
    if (profitEl)  profitEl.textContent  = '₱' + formatMoney(summary.net_profit);
}

function updateRevenueBreakdown(services) {
    const el = document.getElementById('revenueByService');
    if (!el || !services.length) return;

    el.innerHTML = services.map(s => `
        <div style="display:flex; justify-content:space-between; padding:7px 0;
             border-bottom:0.5px solid rgba(59,191,206,0.12); font-size:12px;">
            <span style="color:#1B2A4A;">${escapeHtml(s.service_name)}</span>
            <span style="font-weight:500; color:#1B2A4A;">&#8369;${formatMoney(s.total)}
                <span style="font-size:11px; color:#5A8A9A;">(${s.count}x)</span>
            </span>
        </div>
    `).join('');
}

function updateSalesCount(count) {
    const el = document.getElementById('salesResultsCount');
    if (el) el.textContent = `Showing ${count} transactions`;
}

function setSalesFilter(type, el) {
    document.querySelectorAll('#salesTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadTransactions(type, currentSalesPeriod);
}

function setSalesPeriod(period) {
    currentSalesPeriod = period;
    loadTransactions(currentSalesType, period);
}

// ── Add transaction form ──
function submitAddTransaction(e) {
    e.preventDefault();
    const form = document.getElementById('addTransactionForm');
    const formData = new FormData(form);

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch('backend/sales/add-transaction.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                form.reset();
                closeAddTransactionModal();
                loadTransactions(currentSalesType, currentSalesPeriod);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err))
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Save Transaction';
        });
}

// ── Helpers ──
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(num) {
    return parseFloat(num).toLocaleString('en-PH', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

document.addEventListener('DOMContentLoaded', () => loadTransactions('all', 'month'));