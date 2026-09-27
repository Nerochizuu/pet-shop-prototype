/**
 * orders-table.js
 *
 * Pulls live order data from get_orders.php and renders
 * it into the admin orders.html table.
 */

let currentOrderFilter = 'all';

function loadOrders(status = 'all') {
    currentOrderFilter = status;

    const search = document.getElementById('orderSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status });
    if (search) params.append('search', search);

    fetch(`backend/orders/get_orders.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load orders:', data.message);
                return;
            }
            renderOrdersTable(data.orders);
            updateOrderMetrics(data.summary);
            updateOrderTabs(data.summary);
            updateOrderCount(data.orders.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading orders:', err));
}

function renderOrdersTable(orders) {
    const tbody = document.getElementById('ordersTableBody');
    if (!tbody) return;

    if (orders.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No orders found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = orders.map(o => `
        <tr data-order-id="${o.order_id}">
            <td class="td-name">#ORD-${String(o.order_id).padStart(4, '0')}</td>
            <td>
                <div class="td-name">${escapeHtml(o.customer_name)}</div>
                <div class="td-sub">${escapeHtml(o.customer_email)}</div>
            </td>
            <td>
                <button class="icon-btn" title="View items" onclick='toggleItems(${o.order_id})'>
                    <i class="ti ti-list"></i> ${o.items.length} item${o.items.length !== 1 ? 's' : ''}
                </button>
            </td>
            <td>${formatDate(o.created_at)}</td>
            <td style="font-weight:500;">&#8369;${formatMoney(o.total)}</td>
            <td>${orderStatusBadge(o.status)}</td>
            <td>
                <div class="action-btns">
                    ${nextActionButton(o)}
                    ${o.status !== 'cancelled' && o.status !== 'completed' ? `
                        <button class="icon-btn danger" title="Cancel" onclick="updateOrderStatus(${o.order_id}, 'cancelled')">
                            <i class="ti ti-x"></i>
                        </button>` : ''
                    }
                </div>
            </td>
        </tr>
        <tr class="items-row" id="items-${o.order_id}" style="display:none;">
            <td colspan="7" style="background:#F5FAFB; padding:12px 20px;">
                <table style="width:100%; font-size:12px;">
                    <thead>
                        <tr style="color:#5A8A9A;">
                            <th style="text-align:left; padding:4px 0;">Product</th>
                            <th style="text-align:right;">Qty</th>
                            <th style="text-align:right;">Unit Price</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${o.items.map(i => `
                            <tr>
                                <td style="padding:4px 0;">${escapeHtml(i.product_name)}</td>
                                <td style="text-align:right;">${i.quantity}</td>
                                <td style="text-align:right;">&#8369;${formatMoney(i.unit_price)}</td>
                                <td style="text-align:right;">&#8369;${formatMoney(i.subtotal)}</td>
                            </tr>
                        `).join('')}
                        <tr style="border-top:1px solid rgba(59,191,206,0.2);">
                            <td colspan="3" style="text-align:right; padding-top:6px; color:#5A8A9A;">Delivery fee</td>
                            <td style="text-align:right; padding-top:6px;">&#8369;${formatMoney(o.delivery_fee)}</td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right; font-weight:600;">Total</td>
                            <td style="text-align:right; font-weight:600;">&#8369;${formatMoney(o.total)}</td>
                        </tr>
                    </tbody>
                </table>
                ${o.notes ? `<div style="margin-top:8px; font-size:12px; color:#5A8A9A;"><strong>Notes:</strong> ${escapeHtml(o.notes)}</div>` : ''}
            </td>
        </tr>
    `).join('');
}

function nextActionButton(order) {
    const nextStatus = {
        pending:   { next: 'confirmed', label: 'Confirm', icon: 'check' },
        confirmed: { next: 'ready',     label: 'Mark ready', icon: 'package' },
        ready:     { next: 'completed', label: 'Complete', icon: 'circle-check' }
    };
    const action = nextStatus[order.status];
    if (!action) return '';
    return `
        <button class="icon-btn" title="${action.label}" onclick="updateOrderStatus(${order.order_id}, '${action.next}')">
            <i class="ti ti-${action.icon}"></i>
        </button>`;
}

function orderStatusBadge(status) {
    const map = {
        pending:   ['badge-amber', 'Pending'],
        confirmed: ['badge-blue',  'Confirmed'],
        ready:     ['badge-teal',  'Ready for pickup'],
        completed: ['badge-green', 'Completed'],
        cancelled: ['badge-red',   'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function toggleItems(orderId) {
    const row = document.getElementById(`items-${orderId}`);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function updateOrderMetrics(summary) {
    const totalEl   = document.getElementById('metricTotalOrders');
    const pendingEl = document.getElementById('metricPendingOrders');
    const revenueEl = document.getElementById('metricOrderRevenue');
    const completedEl = document.getElementById('metricCompletedOrders');
    if (totalEl)     totalEl.textContent     = summary.total;
    if (pendingEl)   pendingEl.textContent   = summary.pending;
    if (revenueEl)   revenueEl.textContent   = '₱' + formatMoney(summary.total_revenue);
    if (completedEl) completedEl.textContent = summary.completed;
}

function updateOrderTabs(summary) {
    const tabs = {
        'tab-order-all':       `All (${summary.total})`,
        'tab-order-pending':   `Pending (${summary.pending})`,
        'tab-order-confirmed': `Confirmed (${summary.confirmed})`,
        'tab-order-ready':     `Ready (${summary.ready})`,
        'tab-order-completed': `Completed (${summary.completed})`,
        'tab-order-cancelled': `Cancelled (${summary.cancelled})`
    };
    for (const id in tabs) {
        const el = document.getElementById(id);
        if (el) el.textContent = tabs[id];
    }
}

function updateOrderCount(shown, total) {
    const el = document.getElementById('orderResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} orders`;
}

function setOrderFilter(status, el) {
    document.querySelectorAll('#orderTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadOrders(status);
}

function updateOrderStatus(orderId, newStatus) {
    const labels = {
        confirmed: 'confirm this order',
        ready:     'mark this order as ready for pickup',
        completed: 'mark this order as completed',
        cancelled: 'cancel this order'
    };
    if (!confirm(`Are you sure you want to ${labels[newStatus] || 'update this order'}?`)) return;

    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('status',   newStatus);

    fetch('backend/orders/update_order.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadOrders(currentOrderFilter);
            else alert(data.message);
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Helpers ──
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(num) {
    return parseFloat(num).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
}

document.addEventListener('DOMContentLoaded', () => loadOrders('all'));