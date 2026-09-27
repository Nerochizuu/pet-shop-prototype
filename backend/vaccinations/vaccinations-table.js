/**
 * vaccinations-table.js
 *
 * Pulls live vaccination data from get_vaccinations.php and
 * renders it into the admin vaccinations.html table.
 */

let currentVaccFilter = 'all';
let vaccineTypesList   = [];

function loadVaccinations(statusFilter = 'all') {
    currentVaccFilter = statusFilter;

    const search = document.getElementById('vaccSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status: statusFilter });
    if (search) params.append('search', search);

    fetch(`backend/vaccinations/get_vaccinations.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load vaccinations:', data.message);
                return;
            }
            vaccineTypesList = data.vaccine_types;
            populateVaccineDropdown(data.vaccine_types);
            renderVaccinationsTable(data.records);
            updateVaccMetrics(data.counts);
            updateVaccCount(data.records.length, data.counts.all);
        })
        .catch(err => console.error('Network error:', err));
}

function renderVaccinationsTable(records) {
    const tbody = document.getElementById('vaccinationsTableBody');
    if (!tbody) return;

    if (records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No vaccination records found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = records.map(r => `
        <tr data-record-id="${r.record_id}">
            <td>
                <div class="td-name">${escapeHtml(r.pet_name)}</div>
                <div class="td-sub">${escapeHtml(r.pet_breed || '—')}</div>
            </td>
            <td>
                <div>${escapeHtml(r.owner_name)}</div>
                <div class="td-sub">${escapeHtml(r.email)}</div>
            </td>
            <td>${escapeHtml(r.vaccine_name)}</td>
            <td>${formatDate(r.date_given)}</td>
            <td style="color:${r.status === 'overdue' ? '#E24B4A' : r.status === 'due_soon' ? '#EF9F27' : 'inherit'};">
                ${r.next_due_date ? formatDate(r.next_due_date) : '<span style="color:#A8CDD4;">Not set</span>'}
            </td>
            <td>${escapeHtml(r.administered_by || '—')}</td>
            <td>${vaccStatusBadge(r.status)}</td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" title="Edit due date" onclick="openEditModal(${r.record_id}, '${r.next_due_date || ''}')">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button class="icon-btn" title="Send reminder" onclick="sendReminder(${r.record_id}, '${escapeHtml(r.owner_name)}', '${escapeHtml(r.pet_name)}')">
                        <i class="ti ti-send"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

function vaccStatusBadge(status) {
    const map = {
        overdue:    ['badge-red',   'Overdue'],
        due_soon:   ['badge-amber', 'Due soon'],
        up_to_date: ['badge-green', 'Up to date'],
        no_date:    ['badge-gray',  'No date set']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function updateVaccMetrics(counts) {
    const upToDateEl = document.getElementById('metricUpToDate');
    const dueSoonEl  = document.getElementById('metricDueSoon');
    const overdueEl  = document.getElementById('metricOverdue');
    const totalEl    = document.getElementById('metricTotalPets');
    if (upToDateEl) upToDateEl.textContent = counts.up_to_date || 0;
    if (dueSoonEl)  dueSoonEl.textContent  = counts.due_soon   || 0;
    if (overdueEl)  overdueEl.textContent  = counts.overdue    || 0;
    if (totalEl)    totalEl.textContent    = counts.all        || 0;
}

function updateVaccCount(shown, total) {
    const el = document.getElementById('vaccResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} records`;
}

function setVaccFilter(status, el) {
    document.querySelectorAll('#vaccTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadVaccinations(status);
}

function populateVaccineDropdown(types) {
    const select = document.getElementById('vaccineTypeSelect');
    if (!select || select.dataset.populated) return;
    select.innerHTML = types.map(vt =>
        `<option value="${vt.vaccine_type_id}">${escapeHtml(vt.vaccine_name)}</option>`
    ).join('');
    select.dataset.populated = 'true';
}

// ── Edit due date modal ──
function openEditModal(recordId, currentDueDate) {
    document.getElementById('editRecordId').value  = recordId;
    document.getElementById('editDueDate').value   = currentDueDate;
    document.getElementById('editVaccModal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editVaccModal').classList.remove('open');
}

function submitEditVacc(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('editVaccForm'));

    fetch('backend/vaccinations/update-vaccination.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeEditModal();
                loadVaccinations(currentVaccFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Add vaccination record ──
function submitAddVacc(e) {
    e.preventDefault();
    const form     = document.getElementById('addVaccForm');
    const formData = new FormData(form);
    const btn      = form.querySelector('button[type="submit"]');

    btn.disabled    = true;
    btn.textContent = 'Saving...';

    fetch('backend/vaccinations/add-vaccination.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                form.reset();
                closeAddVaccModal();
                loadVaccinations(currentVaccFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err))
        .finally(() => {
            btn.disabled    = false;
            btn.textContent = 'Save Record';
        });
}

function sendReminder(recordId, ownerName, petName) {
    alert(`Reminder sent to ${ownerName} for ${petName}'s upcoming vaccination.\n(Connect to email/SMS service to send real notifications.)`);
}

function openAddVaccModal()  { document.getElementById('addVaccModal').classList.add('open'); }
function closeAddVaccModal() { document.getElementById('addVaccModal').classList.remove('open'); }

// ── Helpers ──
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

document.addEventListener('DOMContentLoaded', () => loadVaccinations('all'));