/**
 * confinement-table.js
 *
 * Pulls live confinement admission data from get_confinement.php
 * and renders it into the admin confinement.html table.
 * Also handles the expandable medication log viewer per admission.
 */

let currentConfinementFilter = 'all';

function loadConfinement(status = 'all') {
    currentConfinementFilter = status;

    const search = document.getElementById('confinementSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status });
    if (search) params.append('search', search);

    fetch(`backend/confinement_admin/get_confinement.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load confinement admissions:', data.message);
                return;
            }
            renderConfinementTable(data.admissions);
            updateConfinementMetrics(data.summary);
            updateConfinementTabs(data.summary);
            updateConfinementCount(data.admissions.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading confinement admissions:', err));
}

function renderConfinementTable(admissions) {
    const tbody = document.getElementById('confinementTableBody');
    if (!tbody) return;

    if (admissions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No confinement admissions found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = admissions.map(a => `
        <tr data-admission-id="${a.admission_id}">
            <td>
                <div class="td-name">${escapeHtml(a.owner_name)}</div>
                <div class="td-sub">${escapeHtml(a.email)}</div>
            </td>
            <td>
                <div class="td-name">${escapeHtml(a.pet_name)}</div>
                <div class="td-sub">${escapeHtml(a.pet_breed || '—')}${a.pet_age ? ' · ' + escapeHtml(a.pet_age) : ''}</div>
            </td>
            <td>${escapeHtml(a.ward_type)}</td>
            <td>
                <div>Admitted ${formatDate(a.admission_date)}</div>
                ${a.discharge_date ? `<div class="td-sub">Discharged ${formatDate(a.discharge_date)}</div>` : `<div class="td-sub">&#8369;${formatMoney(a.rate_per_day)}/day</div>`}
            </td>
            <td>${confinementStatusBadge(a.status)}</td>
            <td>
                <button class="icon-btn" title="View medication log" onclick='toggleMedLog(${a.admission_id})'>
                    <i class="ti ti-pill"></i> ${a.medication_count}
                    ${a.pending_medications > 0 ? `<span class="badge badge-amber" style="margin-left:4px;">${a.pending_medications} pending</span>` : ''}
                </button>
            </td>
            <td>
                <button class="icon-btn" title="View details" onclick='toggleConfinementDetails(${a.admission_id})'>
                    <i class="ti ti-info-circle"></i>
                </button>
            </td>
            <td>
                <div class="action-btns">
                    ${nextConfinementActionButton(a)}
                    ${a.status !== 'cancelled' && a.status !== 'discharged' ? `
                        <button class="icon-btn danger" title="Cancel" onclick="updateConfinementStatus(${a.admission_id}, 'cancelled')">
                            <i class="ti ti-x"></i>
                        </button>` : ''
                    }
                </div>
            </td>
        </tr>
        <tr class="details-row" id="conf-details-${a.admission_id}" style="display:none;">
            <td colspan="8" style="background:#F5FAFB; padding:12px 20px; font-size:12px;">
                <div><strong>Contact:</strong> ${escapeHtml(a.contact_number)}</div>
                ${a.pet_weight ? `<div style="margin-top:4px;"><strong>Weight:</strong> ${a.pet_weight} kg</div>` : ''}
                ${a.chief_complaint ? `<div style="margin-top:4px;"><strong>Chief complaint:</strong> ${escapeHtml(a.chief_complaint)}</div>` : ''}
                ${a.current_medications ? `<div style="margin-top:4px;"><strong>Current medications (on intake):</strong> ${escapeHtml(a.current_medications)}</div>` : ''}
                ${a.allergies_notes ? `<div style="margin-top:4px;"><strong>Allergies/notes:</strong> ${escapeHtml(a.allergies_notes)}</div>` : ''}
                <div style="margin-top:4px; color:#5A8A9A;">Submitted ${formatDateTime(a.created_at)}</div>
            </td>
        </tr>
        <tr class="medlog-row" id="medlog-${a.admission_id}" style="display:none;">
            <td colspan="8" style="background:#FFF9F0; padding:12px 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong style="font-size:12px;">Medication Log</strong>
                    <button class="add-btn" style="padding:4px 10px; font-size:11px;" onclick="openAddMedLogModal(${a.admission_id}, '${escapeHtml(a.pet_name)}')">
                        <i class="ti ti-plus"></i> Add Entry
                    </button>
                </div>
                <div id="medlog-content-${a.admission_id}" style="font-size:12px; color:#5A8A9A;">Loading...</div>
            </td>
        </tr>
    `).join('');
}

function nextConfinementActionButton(a) {
    const nextStatus = {
        pending:    { next: 'admitted',   label: 'Admit',    icon: 'door-enter' },
        admitted:   { next: 'under_care', label: 'Under care', icon: 'stethoscope' },
        under_care: { next: 'discharged', label: 'Discharge', icon: 'door-exit' }
    };
    const action = nextStatus[a.status];
    if (!action) return '';
    return `
        <button class="icon-btn" title="${action.label}" onclick="updateConfinementStatus(${a.admission_id}, '${action.next}')">
            <i class="ti ti-${action.icon}"></i>
        </button>`;
}

function confinementStatusBadge(status) {
    const map = {
        pending:    ['badge-amber', 'Pending'],
        admitted:   ['badge-blue',  'Admitted'],
        under_care: ['badge-teal',  'Under care'],
        discharged: ['badge-green', 'Discharged'],
        cancelled:  ['badge-red',   'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function toggleConfinementDetails(id) {
    const row = document.getElementById(`conf-details-${id}`);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function toggleMedLog(admissionId) {
    const row = document.getElementById(`medlog-${admissionId}`);
    if (!row) return;
    const showing = row.style.display !== 'none';
    row.style.display = showing ? 'none' : 'table-row';
    if (!showing) loadMedicationLogs(admissionId);
}

function loadMedicationLogs(admissionId) {
    const container = document.getElementById(`medlog-content-${admissionId}`);
    fetch(`backend/confinement_admin/get_medication_logs.php?admission_id=${admissionId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = 'Failed to load medication log.';
                return;
            }
            if (data.logs.length === 0) {
                container.innerHTML = 'No medication entries yet.';
                return;
            }
            container.innerHTML = `
                <table style="width:100%;">
                    <thead>
                        <tr style="color:#854F0B;">
                            <th style="text-align:left; padding:4px 0;">Date/Time</th>
                            <th style="text-align:left;">Medication</th>
                            <th style="text-align:left;">Dosage</th>
                            <th style="text-align:left;">Route</th>
                            <th style="text-align:left;">By</th>
                            <th style="text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.logs.map(l => `
                            <tr>
                                <td style="padding:4px 0;">${formatDateTime(l.administered_at)}</td>
                                <td>${escapeHtml(l.medication_name)}</td>
                                <td>${escapeHtml(l.dosage)}</td>
                                <td>${escapeHtml(l.route)}</td>
                                <td>${escapeHtml(l.administered_by)}</td>
                                <td><span class="med-status ${l.status === 'Given' ? 'done' : 'pending'}">${l.status}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;
        })
        .catch(() => { container.innerHTML = 'Network error loading medication log.'; });
}

function updateConfinementMetrics(summary) {
    const totalEl    = document.getElementById('metricTotalConfinement');
    const pendingEl   = document.getElementById('metricPendingConfinement');
    const confinedEl  = document.getElementById('metricCurrentlyConfined');
    const dischargedEl = document.getElementById('metricDischargedConfinement');
    if (totalEl)     totalEl.textContent     = summary.total;
    if (pendingEl)    pendingEl.textContent   = summary.pending;
    if (confinedEl)   confinedEl.textContent  = summary.currently_confined;
    if (dischargedEl) dischargedEl.textContent = summary.discharged;
}

function updateConfinementTabs(summary) {
    const tabs = {
        'tab-conf-all':        `All (${summary.total})`,
        'tab-conf-pending':    `Pending (${summary.pending})`,
        'tab-conf-admitted':   `Admitted (${summary.admitted})`,
        'tab-conf-under_care': `Under care (${summary.under_care})`,
        'tab-conf-discharged': `Discharged (${summary.discharged})`,
        'tab-conf-cancelled':  `Cancelled (${summary.cancelled})`
    };
    for (const id in tabs) {
        const el = document.getElementById(id);
        if (el) el.textContent = tabs[id];
    }
}

function updateConfinementCount(shown, total) {
    const el = document.getElementById('confinementResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} admissions`;
}

function setConfinementFilter(status, el) {
    document.querySelectorAll('#confinementTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadConfinement(status);
}

function updateConfinementStatus(admissionId, newStatus) {
    const labels = {
        admitted:   'admit this pet',
        under_care: 'move this pet to under-care',
        discharged: 'discharge this pet (this will calculate and record the final bill)',
        cancelled:  'cancel this admission'
    };
    if (!confirm(`Are you sure you want to ${labels[newStatus] || 'update this admission'}?`)) return;

    const formData = new FormData();
    formData.append('admission_id', admissionId);
    formData.append('status',       newStatus);

    fetch('backend/confinement_admin/update_confinement.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (newStatus === 'discharged') alert(data.message);
                loadConfinement(currentConfinementFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Add medication entry (admin-side) ──
function openAddMedLogModal(admissionId, petName) {
    document.getElementById('medLogAdmissionId').value = admissionId;
    document.getElementById('medLogPetNameDisplay').textContent = petName;
    document.getElementById('medLogPetNameHidden').value = petName;
    document.getElementById('adminMedLogModal').classList.add('open');
}
function closeAddMedLogModal() {
    document.getElementById('adminMedLogModal').classList.remove('open');
    document.getElementById('adminMedLogForm').reset();
}
function submitAdminMedLog(e) {
    e.preventDefault();
    const admissionId = document.getElementById('medLogAdmissionId').value;
    const formData     = new FormData(document.getElementById('adminMedLogForm'));
    formData.append('admission_id', admissionId);

    fetch('backend/confinement/add_medication_log.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeAddMedLogModal();
                loadMedicationLogs(admissionId);
                loadConfinement(currentConfinementFilter);
            } else {
                alert(data.message);
            }
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

document.addEventListener('DOMContentLoaded', () => loadConfinement('all'));