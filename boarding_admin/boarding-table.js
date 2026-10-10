/**
 * boarding-table.js
 *
 * Pulls live boarding reservation data from get_boarding.php
 * and renders it into the admin boarding.html table.
 */

let currentBoardingFilter = 'all';

function loadBoarding(status = 'all') {
    currentBoardingFilter = status;

    const search = document.getElementById('boardingSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status });
    if (search) params.append('search', search);

    fetch(`backend/boarding_admin/get_boarding.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load boarding reservations:', data.message);
                return;
            }
            renderBoardingTable(data.reservations);
            updateBoardingMetrics(data.summary);
            updateBoardingTabs(data.summary);
            updateBoardingCount(data.reservations.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading boarding reservations:', err));
}

function renderBoardingTable(reservations) {
    const tbody = document.getElementById('boardingTableBody');
    if (!tbody) return;

    if (reservations.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No boarding reservations found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = reservations.map(r => `
        <tr data-reservation-id="${r.reservation_id}">
            <td>
                <div class="td-name">${escapeHtml(r.owner_name)}</div>
                <div class="td-sub">${escapeHtml(r.email)}</div>
            </td>
            <td>
                <div class="td-name">${escapeHtml(r.pet_name)}</div>
                <div class="td-sub">${escapeHtml(r.pet_breed || '—')}</div>
            </td>
            <td>${escapeHtml(r.room_type)}</td>
            <td>
                <div>${formatDate(r.check_in_date)} → ${formatDate(r.check_out_date)}</div>
                <div class="td-sub">${r.total_nights} night${r.total_nights !== 1 ? 's' : ''}</div>
            </td>
            <td style="font-weight:500;">&#8369;${formatMoney(r.estimated_total)}</td>
            <td>${boardingStatusBadge(r.status)}</td>
            <td>
                <button class="icon-btn" title="View details" onclick='toggleBoardingDetails(${r.reservation_id})'>
                    <i class="ti ti-info-circle"></i>
                </button>
            </td>
            <td>
                <div class="action-btns">
                    ${nextBoardingActionButton(r)}
                    ${r.status !== 'cancelled' && r.status !== 'checked_out' ? `
                        <button class="icon-btn danger" title="Cancel" onclick="updateBoardingStatus(${r.reservation_id}, 'cancelled')">
                            <i class="ti ti-x"></i>
                        </button>` : ''
                    }
                </div>
            </td>
        </tr>
        <tr class="details-row" id="boarding-details-${r.reservation_id}" style="display:none;">
            <td colspan="8" style="background:#F5FAFB; padding:12px 20px; font-size:12px;">
                <div><strong>Contact:</strong> ${escapeHtml(r.contact_number)}</div>
                <div style="margin-top:4px;"><strong>Rate:</strong> &#8369;${formatMoney(r.rate_per_night)} / night</div>
                ${r.addons ? `<div style="margin-top:4px;"><strong>Add-ons:</strong> ${escapeHtml(r.addons)}</div>` : ''}
                ${r.special_instructions ? `<div style="margin-top:4px;"><strong>Special instructions:</strong> ${escapeHtml(r.special_instructions)}</div>` : ''}
                <div style="margin-top:4px; color:#5A8A9A;">Submitted ${formatDateTime(r.created_at)}</div>
            </td>
        </tr>
    `).join('');
}

function nextBoardingActionButton(r) {
    const nextStatus = {
        pending:    { next: 'confirmed',    label: 'Confirm',      icon: 'check' },
        confirmed:  { next: 'checked_in',   label: 'Check in',     icon: 'door-enter' },
        checked_in: { next: 'checked_out',  label: 'Check out',    icon: 'door-exit' }
    };
    const action = nextStatus[r.status];
    if (!action) return '';
    return `
        <button class="icon-btn" title="${action.label}" onclick="updateBoardingStatus(${r.reservation_id}, '${action.next}')">
            <i class="ti ti-${action.icon}"></i>
        </button>`;
}

function boardingStatusBadge(status) {
    const map = {
        pending:     ['badge-amber', 'Pending'],
        confirmed:   ['badge-blue',  'Confirmed'],
        checked_in:  ['badge-teal',  'Checked in'],
        checked_out: ['badge-green', 'Checked out'],
        cancelled:   ['badge-red',   'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function toggleBoardingDetails(id) {
    const row = document.getElementById(`boarding-details-${id}`);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function updateBoardingMetrics(summary) {
    const totalEl     = document.getElementById('metricTotalBoarding');
    const pendingEl    = document.getElementById('metricPendingBoarding');
    const currentEl    = document.getElementById('metricCurrentlyBoarding');
    const checkedOutEl = document.getElementById('metricCheckedOutBoarding');
    if (totalEl)     totalEl.textContent     = summary.total;
    if (pendingEl)    pendingEl.textContent   = summary.pending;
    if (currentEl)    currentEl.textContent   = summary.currently_boarding;
    if (checkedOutEl) checkedOutEl.textContent = summary.checked_out;
}

function updateBoardingTabs(summary) {
    const tabs = {
        'tab-board-all':         `All (${summary.total})`,
        'tab-board-pending':     `Pending (${summary.pending})`,
        'tab-board-confirmed':   `Confirmed (${summary.confirmed})`,
        'tab-board-checked_in':  `Checked in (${summary.checked_in})`,
        'tab-board-checked_out': `Checked out (${summary.checked_out})`,
        'tab-board-cancelled':   `Cancelled (${summary.cancelled})`
    };
    for (const id in tabs) {
        const el = document.getElementById(id);
        if (el) el.textContent = tabs[id];
    }
}

function updateBoardingCount(shown, total) {
    const el = document.getElementById('boardingResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} reservations`;
}

function setBoardingFilter(status, el) {
    document.querySelectorAll('#boardingTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadBoarding(status);
}

function updateBoardingStatus(reservationId, newStatus) {
    const labels = {
        confirmed:    'confirm this reservation',
        checked_in:   'check in this pet',
        checked_out:  'check out this pet (this will record the sale)',
        cancelled:    'cancel this reservation'
    };
    if (!confirm(`Are you sure you want to ${labels[newStatus] || 'update this reservation'}?`)) return;

    const formData = new FormData();
    formData.append('reservation_id', reservationId);
    formData.append('status',         newStatus);

    fetch('backend/boarding_admin/update_boarding.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadBoarding(currentBoardingFilter);
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

function formatDateTime(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
}

document.addEventListener('DOMContentLoaded', () => loadBoarding('all'));
