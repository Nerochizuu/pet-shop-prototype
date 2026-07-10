/**
 * appointments-table.js
 *
 * Pulls live appointment data from get_appointments.php and
 * renders it into the admin appointments.html table, replacing
 * the hardcoded placeholder rows.
 *
 * Include this in appointments.html, right before </body>.
 * Make sure your <table> has: <tbody id="appointmentsTableBody">
 * and your tab buttons have onclick="loadAppointments('pending')" etc.
 */

let currentStatusFilter = 'all';

function loadAppointments(status = 'all') {
    currentStatusFilter = status;

    fetch(`/backend/appointments/get-appointment.php?status=${encodeURIComponent(status)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load appointments:', data.message);
                return;
            }
            renderAppointmentsTable(data.appointments);
            updateTabCounts(data.counts);
        })
        .catch(err => console.error('Network error loading appointments:', err));
}

function renderAppointmentsTable(appointments) {
    const tbody = document.getElementById('appointmentsTableBody');
    if (!tbody) return;

    if (appointments.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding: 24px; color: #5A8A9A;">
                    No appointments found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = appointments.map(appt => `
        <tr data-appointment-id="${appt.appointment_id}">
            <td>
                <div class="td-name">${escapeHtml(appt.customer_name)}</div>
                <div class="td-sub">${escapeHtml(appt.email)}</div>
            </td>
            <td>
                <div class="td-name">${escapeHtml(appt.pet_name)}</div>
                <div class="td-sub">${escapeHtml(appt.pet_breed || '—')}</div>
            </td>
            <td>${escapeHtml(appt.service_name)}</td>
            <td>${appt.groomer_name ? escapeHtml(appt.groomer_name) : '<span style="color:#A8CDD4;">Unassigned</span>'}</td>
            <td>
                <div>${formatDate(appt.appointment_date)}</div>
                <div class="td-sub">${formatTime(appt.appointment_time)}</div>
            </td>
            <td><span class="badge ${appt.booking_type === 'online' ? 'badge-teal' : 'badge-gray'}">${capitalize(appt.booking_type)}</span></td>
            <td>${statusBadge(appt.status)}</td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" title="View" onclick="viewAppointment(${appt.appointment_id})"><i class="ti ti-eye"></i></button>
                    ${appt.status === 'pending' ? `<button class="icon-btn" title="Confirm" onclick="confirmAppointment(${appt.appointment_id})"><i class="ti ti-check"></i></button>` : ''}
                    <button class="icon-btn" title="Assign groomer" onclick="openAssignStaff(${appt.appointment_id})"><i class="ti ti-user-plus"></i></button>
                    <button class="icon-btn danger" title="Cancel" onclick="cancelAppointment(${appt.appointment_id})"><i class="ti ti-x"></i></button>
                </div>
            </td>
        </tr>
    `).join('');
}

function statusBadge(status) {
    const map = {
        pending:     ['badge-amber', 'Pending'],
        confirmed:   ['badge-blue', 'Confirmed'],
        in_progress: ['badge-blue', 'In progress'],
        completed:   ['badge-green', 'Completed'],
        cancelled:   ['badge-red', 'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function updateTabCounts(counts) {
    const tabs = {
        all: 'tab-all', pending: 'tab-pending', in_progress: 'tab-in-progress',
        completed: 'tab-completed', cancelled: 'tab-cancelled'
    };
    for (const key in tabs) {
        const el = document.getElementById(tabs[key]);
        if (el) el.textContent = `${capitalize(key.replace('_', ' '))} (${counts[key] || 0})`;
    }
}

// ── Admin actions ──

function confirmAppointment(id) {
    if (!confirm('Confirm this appointment?')) return;
    postAction(id, 'confirm').then(() => loadAppointments(currentStatusFilter));
}

function cancelAppointment(id) {
    if (!confirm('Cancel this appointment?')) return;
    postAction(id, 'cancel').then(() => loadAppointments(currentStatusFilter));
}

function openAssignStaff(id) {
    const staffId = prompt('Enter staff ID to assign (e.g. 1 = Anna Cruz, 2 = Ben Flores, 3 = Carla Lopez):');
    if (!staffId) return;
    postAction(id, 'assign_staff', { staff_id: staffId }).then(() => loadAppointments(currentStatusFilter));
}

function viewAppointment(id) {
    alert('Appointment #' + id + ' — full detail view can be built into a modal.');
}

function postAction(appointmentId, action, extraFields = {}) {
    const formData = new FormData();
    formData.append('appointment_id', appointmentId);
    formData.append('action', action);
    formData.append('changed_by', 'admin');
    for (const key in extraFields) {
        formData.append(key, extraFields[key]);
    }

    return fetch('/backend/appointments/update_appointment.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (!data.success) alert(data.message);
            return data;
        });
}

// ── Helpers ──

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(timeStr) {
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h, 10);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${m} ${ampm}`;
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Load all appointments on page load
document.addEventListener('DOMContentLoaded', () => loadAppointments('all'));