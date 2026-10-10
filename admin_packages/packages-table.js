/**
 * packages-table.js
 *
 * Pulls live package/service data from get_packages.php and
 * renders it into the admin admin-packages.html card grid.
 * Handles add, edit, delete, and active/inactive toggling.
 */

let allPackages   = [];
let editingPkgId  = null;
let deleteTargetId = null;

// Maps a single "card style" choice to matching badge color + icon background,
// mirroring the four style variants already used in the original design.
const CARD_STYLES = {
    teal:  { badge_color: 'teal',  icon_bg_color: '#E1F5EE' },
    amber: { badge_color: 'amber', icon_bg_color: '#FAEEDA' },
    pink:  { badge_color: 'pink',  icon_bg_color: '#FBEAF0' },
    blue:  { badge_color: 'blue',  icon_bg_color: '#E6F1FB' }
};

function loadPackages() {
    fetch('backend/admin_packages/get_packages.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load packages:', data.message);
                return;
            }
            allPackages = data.packages;
            renderPackagesGrid(allPackages);
            updatePackageMetrics(data.summary);
        })
        .catch(err => console.error('Network error loading packages:', err));
}

function renderPackagesGrid(packages) {
    const grid = document.getElementById('packagesGrid');
    if (!grid) return;

    if (packages.length === 0) {
        grid.innerHTML = `<p style="color:#5A8A9A; font-size:13px; grid-column:1/-1; padding:20px 0;">No packages yet. Click "Add package" to create one.</p>`;
        return;
    }

    grid.innerHTML = packages.map(p => {
        const features    = p.features || [];
        const checkItems  = features.slice(0, -1);
        const durationRow = features.length > 0 ? features[features.length - 1] : null;

        return `
        <div class="pkg-card" data-id="${p.service_id}" style="${p.is_active ? '' : 'opacity:0.55;'}">
            <div class="pkg-card-header">
                <div class="pkg-icon" style="background:${escapeAttr(p.icon_bg_color)};">${p.icon_emoji}</div>
                <div class="pkg-meta">
                    <div class="pkg-name">${escapeHtml(p.name)}</div>
                    ${p.badge_label ? `<span class="pkg-tag tag-${p.badge_color}">${escapeHtml(p.badge_label)}</span>` : ''}
                </div>
                <div class="pkg-actions">
                    <button class="icon-btn" aria-label="Edit package" onclick="openModal('edit', ${p.service_id})">
                        <i class="ti ti-edit" aria-hidden="true"></i>
                    </button>
                    <button class="icon-btn danger" aria-label="Delete package" onclick="confirmDelete(${p.service_id}, '${escapeAttr(p.name)}')">
                        <i class="ti ti-trash" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="pkg-body">
                <p class="pkg-desc">${escapeHtml(p.description || '')}</p>
                <div class="pkg-features">
                    ${checkItems.map(f => `<div class="feat-row"><i class="ti ti-check" aria-hidden="true"></i> ${escapeHtml(f)}</div>`).join('')}
                    ${durationRow ? `<div class="feat-row"><i class="ti ti-clock" aria-hidden="true"></i> ${escapeHtml(durationRow)}</div>` : ''}
                </div>
            </div>
            <div class="pkg-footer">
                <div class="pkg-price">&#8369;${formatMoney(p.price)} <span>${escapeHtml(p.price_unit)}</span></div>
                <div class="status-toggle">
                    <span class="toggle-label">${p.is_active ? 'Active' : 'Inactive'}</span>
                    <div class="toggle ${p.is_active ? 'on' : ''}" onclick="toggleStatus(this, ${p.service_id})"></div>
                </div>
            </div>
        </div>`;
    }).join('');
}

function updatePackageMetrics(summary) {
    const totalEl      = document.getElementById('metricTotalPackages');
    const activeEl      = document.getElementById('metricActivePackages');
    const mostBookedEl  = document.getElementById('metricMostBooked');
    const priceRangeEl  = document.getElementById('metricPriceRange');

    if (totalEl)     totalEl.textContent     = summary.total;
    if (activeEl)     activeEl.textContent    = summary.active;
    if (mostBookedEl) mostBookedEl.textContent = summary.most_booked_name;
    if (priceRangeEl) {
        priceRangeEl.textContent = summary.price_min === summary.price_max
            ? '₱' + formatMoney(summary.price_min)
            : `₱${formatMoney(summary.price_min)}–${formatMoney(summary.price_max)}`;
    }
}

// ── Client-side search filter (small dataset, no need for a server round trip) ──
function filterPackages(query) {
    const q = query.trim().toLowerCase();
    if (!q) { renderPackagesGrid(allPackages); return; }
    const filtered = allPackages.filter(p =>
        p.name.toLowerCase().includes(q) ||
        (p.description || '').toLowerCase().includes(q) ||
        (p.badge_label || '').toLowerCase().includes(q)
    );
    renderPackagesGrid(filtered);
}

// ── Add / Edit modal ──
function openModal(mode, serviceId) {
    const modal = document.getElementById('pkgModal');
    const title = document.getElementById('modalTitle');
    editingPkgId = mode === 'edit' ? serviceId : null;

    if (mode === 'edit') {
        title.textContent = 'Edit package';
        const pkg = allPackages.find(p => p.service_id === serviceId);
        if (!pkg) return;

        document.getElementById('pkgName').value = pkg.name;
        document.getElementById('pkgTag').value  = pkg.badge_label || '';
        document.getElementById('pkgDesc').value = pkg.description || '';
        document.getElementById('pkgInclusions').value = (pkg.features || []).join('\n');
        document.getElementById('pkgPrice').value = pkg.price;
        document.getElementById('pkgUnit').value  = pkg.price_unit;
        document.getElementById('pkgIcon').value  = pkg.icon_emoji;
        document.getElementById('pkgStyle').value = pkg.badge_color;
    } else {
        title.textContent = 'Add package';
        document.getElementById('pkgName').value = '';
        document.getElementById('pkgTag').value  = '';
        document.getElementById('pkgDesc').value = '';
        document.getElementById('pkgInclusions').value = '';
        document.getElementById('pkgPrice').value = '';
        document.getElementById('pkgUnit').value  = '/ session';
        document.getElementById('pkgIcon').value  = '🐾';
        document.getElementById('pkgStyle').value = 'teal';
    }

    modal.classList.add('open');
}

function closeModal() {
    document.getElementById('pkgModal').classList.remove('open');
    editingPkgId = null;
}

function savePackage() {
    const name       = document.getElementById('pkgName').value.trim();
    const tag        = document.getElementById('pkgTag').value.trim();
    const desc       = document.getElementById('pkgDesc').value.trim();
    const inclusions = document.getElementById('pkgInclusions').value;
    const price      = document.getElementById('pkgPrice').value;
    const unit       = document.getElementById('pkgUnit').value;
    const icon       = document.getElementById('pkgIcon').value.trim() || '🐾';
    const style      = document.getElementById('pkgStyle').value;

    if (!name || !price) {
        alert('Package name and price are required.');
        return;
    }

    const styleColors = CARD_STYLES[style] || CARD_STYLES.teal;

    const formData = new FormData();
    formData.append('service_name',  name);
    formData.append('description',   desc);
    formData.append('features',      inclusions);
    formData.append('price',         price);
    formData.append('price_unit',    unit);
    formData.append('badge_label',   tag);
    formData.append('badge_color',   styleColors.badge_color);
    formData.append('icon_emoji',    icon);
    formData.append('icon_bg_color', styleColors.icon_bg_color);

    const isEdit = editingPkgId !== null;
    if (isEdit) {
        formData.append('service_id', editingPkgId);
        formData.append('action', 'update_info');
    }

    const url = isEdit
        ? 'backend/admin_packages/update_package.php'
        : 'backend/admin_packages/add_package.php';

    fetch(url, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeModal();
                loadPackages();
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Delete modal ──
function confirmDelete(serviceId, name) {
    deleteTargetId = serviceId;
    document.getElementById('deleteName').textContent = '"' + name + '"';
    document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTargetId = null;
}

function performDelete() {
    if (!deleteTargetId) return;

    const formData = new FormData();
    formData.append('service_id', deleteTargetId);

    fetch('backend/admin_packages/delete_package.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            closeDeleteModal();
            if (data.success) {
                loadPackages();
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Active/Inactive toggle ──
function toggleStatus(el, serviceId) {
    const willBeActive = !el.classList.contains('on');

    const formData = new FormData();
    formData.append('service_id', serviceId);
    formData.append('action',     'toggle_status');
    formData.append('is_active',  willBeActive ? 1 : 0);

    fetch('backend/admin_packages/update_package.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                el.classList.toggle('on');
                const label = el.previousElementSibling;
                label.textContent = willBeActive ? 'Active' : 'Inactive';
                el.closest('.pkg-card').style.opacity = willBeActive ? '1' : '0.55';
                loadPackages(); // refresh metrics
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

function escapeAttr(str) {
    return escapeHtml(str).replace(/'/g, '&#39;');
}

function formatMoney(num) {
    return parseFloat(num).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

document.addEventListener('DOMContentLoaded', loadPackages);
