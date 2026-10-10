/**
 * custom-packages-table.js
 *
 * Pulls live custom package request data from
 * get_custom_packages.php and renders it into the admin
 * custom-packages.html table.
 */

let currentCustomPkgFilter = 'all';

function loadCustomPackages(status = 'all') {
    currentCustomPkgFilter = status;

    const search = document.getElementById('customPkgSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status });
    if (search) params.append('search', search);

    fetch(`backend/custom_packages_admin/get_custom_packages.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load custom package orders:', data.message);
                return;
            }
            renderCustomPkgTable(data.orders);
            updateCustomPkgMetrics(data.summary);
            updateCustomPkgTabs(data.summary);
            updateCustomPkgCount(data.orders.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading custom package orders:', err));
}

function renderCustomPkgTable(orders) {
    const tbody = document.getElementById('customPkgTableBody');
    if (!tbody) return;

    if (orders.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No custom package requests found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = orders.map(o => `
        <tr data-order-id="${o.order_id}">
            <td>
                <div class="td-name">${escapeHtml(o.customer_name)}</div>
                <div class="td-sub">${escapeHtml(o.email)}</div>
            </td>
            <td>${o.pet_name ? escapeHtml(o.pet_name) : '<span style="color:#A8CDD4;">—</span>'}</td>
            <td>
                <button class="icon-btn" title="View services" onclick='toggleServices(${o.order_id})'>
                    <i class="ti ti-list"></i> ${o.service_count} service${o.service_count !== 1 ? 's' : ''}
                </button>
            </td>
            <td>
                ${o.preferred_date ? formatDate(o.preferred_date) : '<span style="color:#A8CDD4;">Not specified</span>'}
                ${o.preferred_time ? `<div class="td-sub">${formatTime(o.preferred_time)}</div>` : ''}
            </td>
            <td style="font-weight:500;">&#8369;${formatMoney(o.total_price)}</td>
            <td>${customPkgStatusBadge(o.status)}</td>
            <td>
                <div class="action-btns">
                    ${nextCustomPkgActionButton(o)}
                    ${o.status !== 'cancelled' && o.status !== 'completed' ? `
                        <button class="icon-btn danger" title="Cancel" onclick="updateCustomPkgStatus(${o.order_id}, 'cancelled')">
                            <i class="ti ti-x"></i>
                        </button>` : ''
                    }
                </div>
            </td>
        </tr>
        <tr class="services-row" id="services-${o.order_id}" style="display:none;">
            <td colspan="7" style="background:#F5FAFB; padding:12px 20px;">
                <table style="width:100%; font-size:12px;">
                    <thead>
                        <tr style="color:#5A8A9A;">
                            <th style="text-align:left; padding:4px 0;">Service</th>
                            <th style="text-align:right;">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${o.services.map(s => `
                            <tr>
                                <td style="padding:4px 0;">${escapeHtml(s.name)}</td>
                                <td style="text-align:right;">&#8369;${formatMoney(s.price)}</td>
                            </tr>
                        `).join('')}
                        <tr style="border-top:1px solid rgba(59,191,206,0.2);">
                            <td style="text-align:right; font-weight:600; padding-top:6px;">Total</td>
                            <td style="text-align:right; font-weight:600; padding-top:6px;">&#8369;${formatMoney(o.total_price)}</td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top:8px; font-size:12px;"><strong>Contact:</strong> ${escapeHtml(o.contact_number || '—')}</div>
                ${o.notes ? `<div style="margin-top:4px; font-size:12px; color:#5A8A9A;"><strong>Notes:</strong> ${escapeHtml(o.notes)}</div>` : ''}
                <div style="margin-top:4px; font-size:12px; color:#5A8A9A;">Submitted ${formatDateTime(o.created_at)}</div>
            </td>
        </tr>
    `).join('');
}

function nextCustomPkgActionButton(o) {
    const nextStatus = {
        pending:   { next: 'confirmed', label: 'Confirm',  icon: 'check' },
        confirmed: { next: 'completed', label: 'Complete', icon: 'circle-check' }
    };
    const action = nextStatus[o.status];
    if (!action) return '';
    return `
        <button class="icon-btn" title="${action.label}" onclick="updateCustomPkgStatus(${o.order_id}, '${action.next}')">
            <i class="ti ti-${action.icon}"></i>
        </button>`;
}

function customPkgStatusBadge(status) {
    const map = {
        pending:   ['badge-amber', 'Pending'],
        confirmed: ['badge-blue',  'Confirmed'],
        completed: ['badge-green', 'Completed'],
        cancelled: ['badge-red',   'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function toggleServices(orderId) {
    const row = document.getElementById(`services-${orderId}`);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function updateCustomPkgMetrics(summary) {
    const totalEl     = document.getElementById('metricTotalCustomPkg');
    const pendingEl    = document.getElementById('metricPendingCustomPkg');
    const completedEl  = document.getElementById('metricCompletedCustomPkg');
    const valueEl      = document.getElementById('metricValueCustomPkg');
    if (totalEl)     totalEl.textContent     = summary.total;
    if (pendingEl)    pendingEl.textContent   = summary.pending;
    if (completedEl)  completedEl.textContent = summary.completed;
    if (valueEl)      valueEl.textContent      = '₱' + formatMoney(summary.total_value);
}

function updateCustomPkgTabs(summary) {
    const tabs = {
        'tab-custompkg-all':       `All (${summary.total})`,
        'tab-custompkg-pending':   `Pending (${summary.pending})`,
        'tab-custompkg-confirmed': `Confirmed (${summary.confirmed})`,
        'tab-custompkg-completed': `Completed (${summary.completed})`,
        'tab-custompkg-cancelled': `Cancelled (${summary.cancelled})`
    };
    for (const id in tabs) {
        const el = document.getElementById(id);
        if (el) el.textContent = tabs[id];
    }
}

function updateCustomPkgCount(shown, total) {
    const el = document.getElementById('customPkgResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} requests`;
}

function setCustomPkgFilter(status, el) {
    document.querySelectorAll('#customPkgTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadCustomPackages(status);
}

function updateCustomPkgStatus(orderId, newStatus) {
    const labels = {
        confirmed: 'confirm this custom package request',
        completed: 'mark this request as completed (this will record the sale)',
        cancelled: 'cancel this request'
    };
    if (!confirm(`Are you sure you want to ${labels[newStatus] || 'update this request'}?`)) return;

    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('status',   newStatus);

    fetch('backend/custom_packages_admin/update_custom_package.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadCustomPackages(currentCustomPkgFilter);
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
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h, 10);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${m} ${ampm}`;
}

function formatDateTime(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
}

document.addEventListener('DOMContentLoaded', () => loadCustomPackages('all'));
