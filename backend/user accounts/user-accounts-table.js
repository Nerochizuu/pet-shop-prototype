/**
 * user-accounts-table.js
 *
 * Pulls live user account data from get_users.php and renders
 * it into the admin user-accounts.html table.
 * Handles create, reset password, activate/deactivate actions.
 */

let currentUserFilter = 'all';

// Get logged-in admin ID from sessionStorage
function getAdminId() {
    const user = JSON.parse(sessionStorage.getItem('pawpriority_user') || '{}');
    return user.user_id || 0;
}

function loadUsers(roleFilter = 'all') {
    currentUserFilter = roleFilter;

    const search = document.getElementById('userSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ role: roleFilter });
    if (search) params.append('search', search);

    fetch(`backend/user accounts/get-users.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load users:', data.message);
                return;
            }
            renderUsersTable(data.users);
            updateUserMetrics(data.summary);
            updateUserCount(data.users.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading users:', err));
}

function renderUsersTable(users) {
    const tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    if (users.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No accounts found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = users.map(u => `
        <tr data-user-id="${u.user_id}">
            <td>
                <div class="td-name">${escapeHtml(u.full_name)}</div>
                <div class="td-sub">${escapeHtml(u.position || '—')}</div>
            </td>
            <td>${escapeHtml(u.email)}</td>
            <td>
                <span class="badge ${u.role === 'admin' ? 'badge-pink' : 'badge-blue'}">
                    ${capitalize(u.role)}
                </span>
            </td>
            <td>${u.last_login ? formatDateTime(u.last_login) : '<span style="color:#A8CDD4;">Never</span>'}</td>
            <td>${formatDate(u.created_at)}</td>
            <td>
                <span class="badge ${u.is_active ? 'badge-green' : 'badge-gray'}">
                    ${u.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" title="Edit info" onclick="openEditUserModal(${u.user_id}, '${escapeHtml(u.full_name)}', '${escapeHtml(u.position || '')}')">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button class="icon-btn" title="Reset password" onclick="openResetModal(${u.user_id}, '${escapeHtml(u.full_name)}')">
                        <i class="ti ti-key"></i>
                    </button>
                    <button class="icon-btn ${u.is_active ? 'danger' : ''}" title="${u.is_active ? 'Deactivate' : 'Reactivate'}"
                        onclick="toggleUserStatus(${u.user_id}, ${u.is_active ? 1 : 0}, '${escapeHtml(u.full_name)}')">
                        <i class="ti ti-${u.is_active ? 'user-off' : 'user-check'}"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

function updateUserMetrics(summary) {
    const totalEl    = document.getElementById('metricTotalAccounts');
    const adminsEl   = document.getElementById('metricAdmins');
    const staffEl    = document.getElementById('metricStaff');
    const inactiveEl = document.getElementById('metricInactive');
    if (totalEl)    totalEl.textContent    = summary.total;
    if (adminsEl)   adminsEl.textContent   = summary.admins;
    if (staffEl)    staffEl.textContent    = summary.staff;
    if (inactiveEl) inactiveEl.textContent = summary.inactive;
}

function updateUserCount(shown, total) {
    const el = document.getElementById('userResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} accounts`;
}

function setUserFilter(role, el) {
    document.querySelectorAll('#userTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadUsers(role);
}

// ── Create account ──
function submitCreateUser(e) {
    e.preventDefault();
    const form     = document.getElementById('createUserForm');
    const formData = new FormData(form);
    formData.append('created_by', getAdminId());

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled    = true;
    btn.textContent = 'Creating...';

    fetch('backend/user accounts/create-user.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                form.reset();
                closeCreateUserModal();
                loadUsers(currentUserFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err))
        .finally(() => {
            btn.disabled    = false;
            btn.textContent = 'Create Account';
        });
}

// ── Reset password ──
function openResetModal(userId, name) {
    document.getElementById('resetUserId').value   = userId;
    document.getElementById('resetUserName').textContent = name;
    document.getElementById('resetPasswordModal').classList.add('open');
}

function closeResetModal() {
    document.getElementById('resetPasswordModal').classList.remove('open');
    document.getElementById('resetPasswordForm').reset();
}

function submitResetPassword(e) {
    e.preventDefault();
    const newPass    = document.getElementById('newPassword').value;
    const confirmPass = document.getElementById('confirmPassword').value;

    if (newPass !== confirmPass) {
        alert('Passwords do not match.');
        return;
    }

    const formData = new FormData();
    formData.append('user_id',      document.getElementById('resetUserId').value);
    formData.append('action',       'reset_password');
    formData.append('new_password', newPass);
    formData.append('admin_id',     getAdminId());

    fetch('backend/user accounts/update-user.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.success) closeResetModal();
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Toggle account status ──
function toggleUserStatus(userId, currentlyActive, name) {
    const action = currentlyActive ? 'deactivate' : 'reactivate';
    if (!confirm(`${capitalize(action)} account for "${name}"?`)) return;

    const formData = new FormData();
    formData.append('user_id',   userId);
    formData.append('action',    'toggle_status');
    formData.append('is_active', currentlyActive ? 0 : 1);
    formData.append('admin_id',  getAdminId());

    fetch('backend/user accounts/update-user.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadUsers(currentUserFilter);
            else alert(data.message);
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Edit user info ──
function openEditUserModal(userId, name, position) {
    document.getElementById('editUserId').value       = userId;
    document.getElementById('editUserName').value     = name;
    document.getElementById('editUserPosition').value = position;
    document.getElementById('editUserModal').classList.add('open');
}

function closeEditUserModal() {
    document.getElementById('editUserModal').classList.remove('open');
}

function submitEditUser(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('editUserForm'));
    formData.append('action',   'update_info');
    formData.append('admin_id', getAdminId());

    fetch('backend/user accounts/update-user.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeEditUserModal();
                loadUsers(currentUserFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Modal controls ──
function openCreateUserModal()  { document.getElementById('createUserModal').classList.add('open'); }
function closeCreateUserModal() { document.getElementById('createUserModal').classList.remove('open'); }

// ── Helpers ──
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric'
    });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

document.addEventListener('DOMContentLoaded', () => loadUsers('all'));