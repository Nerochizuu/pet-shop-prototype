/**
 * hours-table.js
 *
 * Loads the week's operating hours (reusing the same
 * backend/home/get_hours.php the customer home page uses,
 * since it already returns all 7 days regardless of status)
 * and renders an editable row per day. Saves all changed rows
 * in one batch when "Save changes" is clicked.
 */

let originalHours = [];

function loadHoursAdmin() {
    fetch('backend/home/get_hours.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load hours:', data.message);
                return;
            }
            originalHours = data.hours;
            renderHoursForm(data.hours, data.today);
            updateHoursStatusPreview(data.is_open);
        })
        .catch(err => console.error('Network error loading hours:', err));
}

function renderHoursForm(hours, today) {
    const container = document.getElementById('hoursFormBody');
    if (!container) return;

    container.innerHTML = hours.map(h => `
        <div class="hours-edit-row" data-day="${h.day_of_week}" style="
            display:grid; grid-template-columns: 110px 1fr 1fr 110px;
            align-items:center; gap:12px; padding:10px 0;
            border-bottom:0.5px solid rgba(59,191,206,0.15);
            ${h.day_of_week === today ? 'background:#EAF6F8; border-radius:8px; padding-left:8px; padding-right:8px;' : ''}">
            <div style="font-weight:500; color:#1B2A4A; font-size:13px;">
                ${h.day_name} ${h.day_of_week === today ? '<span style="color:#1F8FA0; font-size:10px;">(Today)</span>' : ''}
            </div>
            <div>
                <input type="time" class="hours-open-input" value="${h.open_raw}" ${h.is_closed ? 'disabled' : ''}
                    style="width:100%;" />
            </div>
            <div>
                <input type="time" class="hours-close-input" value="${h.close_raw}" ${h.is_closed ? 'disabled' : ''}
                    style="width:100%;" />
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#5A8A9A; cursor:pointer;">
                <input type="checkbox" class="hours-closed-input" ${h.is_closed ? 'checked' : ''}
                    onchange="toggleRowInputs(this)" style="width:auto;" />
                Closed
            </label>
        </div>
    `).join('');
}

function toggleRowInputs(checkbox) {
    const row = checkbox.closest('.hours-edit-row');
    const openInput  = row.querySelector('.hours-open-input');
    const closeInput = row.querySelector('.hours-close-input');
    openInput.disabled  = checkbox.checked;
    closeInput.disabled = checkbox.checked;
}

function updateHoursStatusPreview(isOpen) {
    const el = document.getElementById('hoursLivePreview');
    if (!el) return;
    el.innerHTML = isOpen
        ? '<span class="badge badge-green">Currently showing: Open</span>'
        : '<span class="badge badge-gray">Currently showing: Closed</span>';
}

function saveAllHours() {
    const rows = document.querySelectorAll('.hours-edit-row');
    const btn  = document.getElementById('saveHoursBtn');
    btn.disabled    = true;
    btn.textContent = 'Saving...';

    const requests = Array.from(rows).map(row => {
        const day        = row.dataset.day;
        const openTime   = row.querySelector('.hours-open-input').value;
        const closeTime  = row.querySelector('.hours-close-input').value;
        const isClosed   = row.querySelector('.hours-closed-input').checked ? 1 : 0;

        const formData = new FormData();
        formData.append('day_of_week', day);
        formData.append('open_time',   openTime);
        formData.append('close_time',  closeTime);
        formData.append('is_closed',   isClosed);

        return fetch('backend/hours_admin/update_hours.php', { method: 'POST', body: formData })
            .then(res => res.json());
    });

    Promise.all(requests)
        .then(results => {
            const failed = results.filter(r => !r.success);
            if (failed.length > 0) {
                alert('Some days failed to save:\n' + failed.map(f => f.message).join('\n'));
            } else {
                showSavedToast();
            }
            loadHoursAdmin();
        })
        .catch(err => alert('Network error: ' + err))
        .finally(() => {
            btn.disabled    = false;
            btn.textContent = 'Save changes';
        });
}

function showSavedToast() {
    const el = document.getElementById('hoursSavedMsg');
    if (!el) return;
    el.style.display = 'inline';
    setTimeout(() => { el.style.display = 'none'; }, 2500);
}

document.addEventListener('DOMContentLoaded', loadHoursAdmin);
