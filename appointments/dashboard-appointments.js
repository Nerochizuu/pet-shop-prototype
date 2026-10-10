/**
 * dashboard-appointments.js
 *
 * Loads today's real appointments into the "Today's appointment
 * schedule" card on admin-dashboard.html, and updates the
 * "Today's appointments" metric card to match.
 *
 * Reuses the existing backend/appointments/get_appointments.php
 * endpoint (it already supports a `date` filter) rather than
 * adding a new one.
 */

function loadTodaySchedule() {
    // Local date (Manila), not UTC — toISOString() would give yesterday's
    // date before 8:00 AM Philippine time.
    const now   = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

    const params = new URLSearchParams({ status: 'all', date: today });

    fetch(`backend/appointments/get_appointments.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load today\'s schedule:', data.message);
                return;
            }
            renderTodaySchedule(data.appointments);
            updateTodayMetric(data.counts);
        })
        .catch(err => console.error('Network error loading today\'s schedule:', err));
}

function renderTodaySchedule(appointments) {
    const container = document.getElementById('todayScheduleBody');
    if (!container) return;

    if (appointments.length === 0) {
        container.innerHTML = `
            <div class="appt-row">
                <div style="color:#5A8A9A; font-size:12px; grid-column:1/-1; padding:10px 0;">
                    No appointments scheduled for today.
                </div>
            </div>`;
        return;
    }

    // Show at most 5 on the dashboard card, ordered by time
    const sorted = [...appointments].sort((a, b) => a.appointment_time.localeCompare(b.appointment_time));
    const shown  = sorted.slice(0, 5);

    container.innerHTML = shown.map(appt => `
        <div class="appt-row">
            <div>
                <div class="appt-name">${escapeHtml(appt.customer_name)}</div>
                <div class="appt-pet">${escapeHtml(appt.pet_name)} · ${escapeHtml(appt.pet_breed || '—')}</div>
            </div>
            <div>
                <div>${appt.groomer_name ? escapeHtml(appt.groomer_name) : 'Unassigned'}</div>
                <div class="appt-sub">${escapeHtml(appt.service_name)}</div>
            </div>
            <div>${formatTime(appt.appointment_time)}</div>
            ${dashboardStatusBadge(appt.status)}
        </div>
    `).join('');
}

function updateTodayMetric(counts) {
    const valueEl = document.getElementById('metricTodayAppointments');
    const subEl   = document.getElementById('metricTodayApptSub');
    if (valueEl) valueEl.textContent = counts.all || 0;
    if (subEl)   subEl.textContent   = `${counts.pending || 0} pending · ${counts.confirmed || 0} confirmed`;
}

function dashboardStatusBadge(status) {
    const map = {
        pending:     ['badge-amber', 'Pending'],
        confirmed:   ['badge-blue',  'Confirmed'],
        in_progress: ['badge-blue',  'In progress'],
        completed:   ['badge-green', 'Done'],
        cancelled:   ['badge-red',   'Cancelled']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function formatTime(timeStr) {
    if (!timeStr) return '—';
    const [h, m] = timeStr.split(':');
    const hour   = parseInt(h, 10);
    const ampm   = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${m} ${ampm}`;
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadTodaySchedule);
