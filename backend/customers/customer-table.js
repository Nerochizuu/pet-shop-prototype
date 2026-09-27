/**
 * customers-table.js
 *
 * Pulls live customer data from get_customers.php and renders
 * it into the admin customers.html table. Also handles the
 * "Add customer" form submission via add_customer.php.
 */

let currentCustomerFilter = 'all';

function loadCustomers(statusFilter = 'all') {
    currentCustomerFilter = statusFilter;

    const search = document.getElementById('customerSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status: statusFilter });
    if (search) params.append('search', search);

    fetch(`backend/customers/get-customer.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load customers:', data.message);
                return;
            }
            renderCustomersTable(data.customers);
            updateCustomerMetrics(data.summary);
            updateCustomerCount(data.customers.length, data.summary.total);
        })
        .catch(err => console.error('Network error loading customers:', err));
}

function renderCustomersTable(customers) {
    const tbody = document.getElementById('customersTableBody');
    if (!tbody) return;

    if (customers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center; padding:24px; color:#5A8A9A;">
                    No customers found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = customers.map(c => `
        <tr data-customer-id="${c.customer_id}">
            <td>
                <div class="td-name">${escapeHtml(c.full_name)}</div>
                <div class="td-sub">Since ${formatDate(c.member_since)}</div>
            </td>
            <td>
                <div>${escapeHtml(c.email)}</div>
                <div class="td-sub">${escapeHtml(c.contact_number)}</div>
            </td>
            <td>
                ${c.pets.length > 0
                    ? c.pets.map(p => `<span class="badge badge-blue" style="margin:2px;">${escapeHtml(p.pet_name)}</span>`).join(' ')
                    : '<span style="color:#A8CDD4;">None yet</span>'
                }
            </td>
            <td>${formatDate(c.last_visit) || '—'}</td>
            <td>${c.total_visits}</td>
            <td>
                <span class="badge ${c.is_active ? 'badge-green' : 'badge-amber'}">
                    ${c.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" title="View" onclick="viewCustomer(${c.customer_id})">
                        <i class="ti ti-eye"></i>
                    </button>
                    <button class="icon-btn" title="Edit" onclick="editCustomer(${c.customer_id})">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button class="icon-btn danger" title="${c.is_active ? 'Deactivate' : 'Reactivate'}"
                        onclick="toggleCustomerStatus(${c.customer_id}, ${c.is_active})">
                        <i class="ti ti-${c.is_active ? 'user-off' : 'user-check'}"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

function updateCustomerMetrics(summary) {
    const totalEl   = document.getElementById('metricTotalCustomers');
    const activeEl  = document.getElementById('metricActiveCustomers');
    const petsEl    = document.getElementById('metricTotalPets');
    if (totalEl)  totalEl.textContent  = summary.total;
    if (activeEl) activeEl.textContent = summary.active;
}

function updateCustomerCount(shown, total) {
    const el = document.getElementById('customerResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} customers`;
}

function setCustomerFilter(status, el) {
    document.querySelectorAll('#customerTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadCustomers(status);
}

// ── Admin actions ──

function viewCustomer(id) {
    alert('Customer #' + id + ' — full profile view can be added here.');
}

function editCustomer(id) {
    alert('Customer #' + id + ' — edit form can be added here.');
}

function toggleCustomerStatus(id, currentlyActive) {
    const action = currentlyActive ? 'deactivate' : 'reactivate';
    if (!confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} this customer?`)) return;

    const formData = new FormData();
    formData.append('customer_id', id);
    formData.append('is_active', currentlyActive ? 0 : 1);

    fetch('backend/customers/update-customer.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadCustomers(currentCustomerFilter);
            else alert(data.message);
        });
}

// ── Add customer form ──

function submitAddCustomer(e) {
    e.preventDefault();
    const form = document.getElementById('addCustomerForm');
    const formData = new FormData(form);

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch('backend/customers/add-customer.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                form.reset();
                closeAddCustomerModal();
                loadCustomers(currentCustomerFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err))
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Save Customer';
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
    if (!dateStr) return null;
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Load on page ready
document.addEventListener('DOMContentLoaded', () => loadCustomers('all'));