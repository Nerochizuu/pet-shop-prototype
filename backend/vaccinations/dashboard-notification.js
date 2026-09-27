/**
 * dashboard-notifications.js
 *
 * Loads live notifications into the admin dashboard's
 * notifications card. Covers:
 *   - Overdue vaccinations (high priority)
 *   - Vaccinations due within 30 days
 *   - New pending bookings (last 24 hours)
 *   - Low / out-of-stock inventory items
 *
 * Include in admin-dashboard.html right before </body>.
 */

function loadDashboardNotifications() {
    fetch('backend/vaccinations/get-notifications.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            renderNotifications(data.notifications);
            updateNotifBadge(data.counts.total);
        })
        .catch(err => console.error('Error loading notifications:', err));
}

function renderNotifications(notifications) {
    const container = document.getElementById('notificationsPanel');
    if (!container) return;

    if (notifications.length === 0) {
        container.innerHTML = `
            <div style="padding:12px 0; color:#5A8A9A; font-size:12px; text-align:center;">
                No new notifications.
            </div>`;
        return;
    }

    container.innerHTML = notifications.slice(0, 5).map(n => `
        <div class="notif-item">
            <div class="notif-icon" style="background:${n.color}; color:${n.text_color};">
                <i class="ti ti-${n.icon}" aria-hidden="true"></i>
            </div>
            <div>
                <div class="notif-text">${escapeHtml(n.message)}</div>
                <div class="notif-time">${escapeHtml(n.meta || '')}</div>
            </div>
        </div>
    `).join('');
}

function updateNotifBadge(count) {
    const dot = document.querySelector('.notif-dot');
    if (dot) {
        dot.style.display = count > 0 ? 'block' : 'none';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadDashboardNotifications);