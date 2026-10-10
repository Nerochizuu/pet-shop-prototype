/**
 * announcements-table.js
 *
 * Pulls live announcement data from get_announcements_admin.php
 * and renders it into the admin announcements.html table.
 * Handles add, edit, pin/unpin, activate/deactivate, and delete.
 */

let allAnnouncements   = [];
let currentAnnFilter   = 'all';
let editingAnnId       = null;
let deleteAnnTargetId  = null;

// Maps a "category" choice to the matching tag label + tag_type +
// default emoji, mirroring the four categories already seeded.
const ANNOUNCEMENT_CATEGORIES = {
    promo:   { tag: 'Promo',  tag_type: 'promo',   emoji: '🎉' },
    info:    { tag: 'Update', tag_type: 'info',    emoji: '📋' },
    alert:   { tag: 'Notice', tag_type: 'alert',   emoji: '⚠️' },
    general: { tag: 'Shop',   tag_type: 'general', emoji: '🛍️' }
};

function getAdminId() {
    const user = JSON.parse(sessionStorage.getItem('pawpriority_user') || '{}');
    return user.user_id || 0;
}

function loadAnnouncementsAdmin(status = 'all') {
    currentAnnFilter = status;

    const search = document.getElementById('annSearchInput')?.value.trim() || '';
    const params  = new URLSearchParams({ status });
    if (search) params.append('search', search);

    fetch(`backend/announcements_admin/get_announcements_admin.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load announcements:', data.message);
                return;
            }
            allAnnouncements = data.announcements;
            renderAnnouncementsTable(data.announcements);
            updateAnnMetrics(data.summary);
            updateAnnCount(data.announcements.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading announcements:', err));
}

function renderAnnouncementsTable(announcements) {
    const tbody = document.getElementById('announcementsTableBody');
    if (!tbody) return;

    if (announcements.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No announcements found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = announcements.map(a => `
        <tr data-id="${a.announcement_id}" style="${a.is_active ? '' : 'opacity:0.55;'}">
            <td>
                <div class="td-name">${a.emoji} ${escapeHtml(a.title)}</div>
                <div class="td-sub">${escapeHtml(truncate(a.body, 80))}</div>
            </td>
            <td><span class="badge ${tagBadgeClass(a.tag_type)}">${escapeHtml(a.tag)}</span></td>
            <td>
                <button class="icon-btn ${a.is_pinned ? 'pinned' : ''}" title="${a.is_pinned ? 'Unpin' : 'Pin'}"
                    onclick="togglePin(${a.announcement_id}, ${a.is_pinned ? 0 : 1})"
                    style="${a.is_pinned ? 'color:#1F8FA0; border-color:#1F8FA0;' : ''}">
                    <i class="ti ti-pin"></i>
                </button>
            </td>
            <td>${formatDate(a.created_at)}</td>
            <td>
                <span class="badge ${a.is_active ? 'badge-green' : 'badge-gray'}">
                    ${a.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" title="Edit" onclick="openAnnModal('edit', ${a.announcement_id})">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button class="icon-btn" title="${a.is_active ? 'Deactivate' : 'Activate'}"
                        onclick="toggleAnnStatus(${a.announcement_id}, ${a.is_active ? 0 : 1})">
                        <i class="ti ti-${a.is_active ? 'eye-off' : 'eye'}"></i>
                    </button>
                    <button class="icon-btn danger" title="Delete" onclick="confirmDeleteAnn(${a.announcement_id}, '${escapeAttr(a.title)}')">
                        <i class="ti ti-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

function tagBadgeClass(tagType) {
    const map = { promo: 'badge-pink', info: 'badge-blue', alert: 'badge-amber', general: 'badge-green' };
    return map[tagType] || 'badge-gray';
}

function updateAnnMetrics(summary) {
    const totalEl    = document.getElementById('metricTotalAnn');
    const activeEl    = document.getElementById('metricActiveAnn');
    const pinnedEl     = document.getElementById('metricPinnedAnn');
    const inactiveEl  = document.getElementById('metricInactiveAnn');
    if (totalEl)    totalEl.textContent    = summary.total;
    if (activeEl)    activeEl.textContent   = summary.active;
    if (pinnedEl)     pinnedEl.textContent   = summary.pinned;
    if (inactiveEl)  inactiveEl.textContent = summary.inactive;
}

function updateAnnCount(shown, total) {
    const el = document.getElementById('annResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} announcements`;
}

function setAnnFilter(status, el) {
    document.querySelectorAll('#annTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadAnnouncementsAdmin(status);
}

// ── Add / Edit modal ──
function openAnnModal(mode, id) {
    const modal = document.getElementById('annModal');
    const title = document.getElementById('annModalTitle');
    editingAnnId = mode === 'edit' ? id : null;

    if (mode === 'edit') {
        title.textContent = 'Edit announcement';
        const ann = allAnnouncements.find(a => a.announcement_id === id);
        if (!ann) return;

        document.getElementById('annTitle').value    = ann.title;
        document.getElementById('annBody').value     = ann.body;
        document.getElementById('annCategory').value = ann.tag_type;
        document.getElementById('annEmoji').value    = ann.emoji;
        document.getElementById('annPinned').checked = ann.is_pinned;
    } else {
        title.textContent = 'New announcement';
        document.getElementById('annTitle').value    = '';
        document.getElementById('annBody').value     = '';
        document.getElementById('annCategory').value = 'info';
        document.getElementById('annEmoji').value    = ANNOUNCEMENT_CATEGORIES.info.emoji;
        document.getElementById('annPinned').checked = false;
    }

    modal.classList.add('open');
}

function closeAnnModal() {
    document.getElementById('annModal').classList.remove('open');
    editingAnnId = null;
}

// Suggest a default emoji when category changes, unless user already customized it
function onAnnCategoryChange() {
    const category = document.getElementById('annCategory').value;
    document.getElementById('annEmoji').value = ANNOUNCEMENT_CATEGORIES[category]?.emoji || '📋';
}

function saveAnnouncement() {
    const titleVal    = document.getElementById('annTitle').value.trim();
    const bodyVal     = document.getElementById('annBody').value.trim();
    const categoryVal = document.getElementById('annCategory').value;
    const emojiVal    = document.getElementById('annEmoji').value.trim() || '📋';
    const pinnedVal   = document.getElementById('annPinned').checked;

    if (!titleVal || !bodyVal) {
        alert('Title and announcement body are required.');
        return;
    }

    const cat = ANNOUNCEMENT_CATEGORIES[categoryVal] || ANNOUNCEMENT_CATEGORIES.info;

    const formData = new FormData();
    formData.append('title',    titleVal);
    formData.append('body',     bodyVal);
    formData.append('tag',      cat.tag);
    formData.append('tag_type', cat.tag_type);
    formData.append('emoji',    emojiVal);

    const isEdit = editingAnnId !== null;
    let url;
    if (isEdit) {
        formData.append('announcement_id', editingAnnId);
        formData.append('action', 'update_info');
        url = 'backend/announcements_admin/update_announcement.php';
    } else {
        formData.append('is_pinned', pinnedVal ? 1 : 0);
        formData.append('posted_by', getAdminId());
        url = 'backend/announcements_admin/add_announcement.php';
    }

    fetch(url, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (!data.success) { alert(data.message); return; }

            // If editing and the pin checkbox changed, apply it as a separate call
            if (isEdit) {
                const original = allAnnouncements.find(a => a.announcement_id === editingAnnId);
                if (original && original.is_pinned !== pinnedVal) {
                    togglePin(editingAnnId, pinnedVal ? 1 : 0, true);
                }
            }

            closeAnnModal();
            loadAnnouncementsAdmin(currentAnnFilter);
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Pin / Status toggles ──
function togglePin(id, newPinned, silent) {
    const formData = new FormData();
    formData.append('announcement_id', id);
    formData.append('action',          'toggle_pin');
    formData.append('is_pinned',       newPinned);

    fetch('backend/announcements_admin/update_announcement.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) { if (!silent) loadAnnouncementsAdmin(currentAnnFilter); }
            else alert(data.message);
        })
        .catch(err => alert('Network error: ' + err));
}

function toggleAnnStatus(id, newActive) {
    const formData = new FormData();
    formData.append('announcement_id', id);
    formData.append('action',          'toggle_status');
    formData.append('is_active',       newActive);

    fetch('backend/announcements_admin/update_announcement.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadAnnouncementsAdmin(currentAnnFilter);
            else alert(data.message);
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Delete ──
function confirmDeleteAnn(id, title) {
    deleteAnnTargetId = id;
    document.getElementById('deleteAnnName').textContent = '"' + title + '"';
    document.getElementById('deleteAnnModal').classList.add('open');
}

function closeDeleteAnnModal() {
    document.getElementById('deleteAnnModal').classList.remove('open');
    deleteAnnTargetId = null;
}

function performDeleteAnn() {
    if (!deleteAnnTargetId) return;

    const formData = new FormData();
    formData.append('announcement_id', deleteAnnTargetId);

    fetch('backend/announcements_admin/delete_announcement.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            closeDeleteAnnModal();
            if (data.success) loadAnnouncementsAdmin(currentAnnFilter);
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

function escapeAttr(str) {
    return escapeHtml(str).replace(/'/g, '&#39;');
}

function truncate(str, len) {
    if (!str) return '';
    return str.length > len ? str.slice(0, len) + '…' : str;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

document.addEventListener('DOMContentLoaded', () => loadAnnouncementsAdmin('all'));
