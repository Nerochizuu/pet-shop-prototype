/**
 * inventory-table.js
 *
 * Pulls live inventory data from get_inventory.php and renders
 * it into the admin inventory.html table. Also wires up the
 * USB HID barcode scanner so scanning updates stock in real time.
 */

let currentInvFilter = 'all';

function loadInventory(statusFilter = 'all') {
    currentInvFilter = statusFilter;

    const search = document.getElementById('invSearchInput')?.value.trim() || '';
    const params = new URLSearchParams({ status: statusFilter });
    if (search) params.append('search', search);

    fetch(`backend/inventory/get_inventory.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load inventory:', data.message);
                return;
            }
            renderInventoryTable(data.items);
            updateInventoryMetrics(data.summary);
            updateInvResultsCount(data.items.length, data.summary.total_items);
        })
        .catch(err => console.error('Network error loading inventory:', err));
}

function renderInventoryTable(items) {
    const tbody = document.getElementById('inventoryTableBody');
    if (!tbody) return;

    if (items.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding: 24px; color: #5A8A9A;">
                    No inventory items found.
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = items.map(item => `
        <tr data-item-id="${item.item_id}" data-barcode="${escapeHtml(item.barcode)}">
            <td class="td-name">${escapeHtml(item.item_name)}</td>
            <td>${escapeHtml(item.category)}</td>
            <td>${item.current_stock}</td>
            <td>${escapeHtml(item.unit)}</td>
            <td>${item.reorder_level}</td>
            <td>&#8369;${formatMoney(item.unit_cost)}</td>
            <td>${invStatusBadge(item.stock_status)}</td>
            <td>
                <div class="action-btns">
                    <button class="icon-btn" aria-label="Edit" onclick="editItem(${item.item_id})"><i class="ti ti-edit" aria-hidden="true"></i></button>
                    <button class="icon-btn" aria-label="Restock" onclick="manualAdjust(${item.item_id}, 'restock', '${escapeHtml(item.item_name)}')"><i class="ti ti-plus" aria-hidden="true"></i></button>
                    <button class="icon-btn" aria-label="Deduct" onclick="manualAdjust(${item.item_id}, 'deduct', '${escapeHtml(item.item_name)}')"><i class="ti ti-minus" aria-hidden="true"></i></button>
                </div>
            </td>
        </tr>
    `).join('');
}

function invStatusBadge(status) {
    const map = {
        good:         ['badge-green', 'Good'],
        low:          ['badge-amber', 'Low'],
        out_of_stock: ['badge-red', 'Out of stock']
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

function updateInventoryMetrics(summary) {
    const totalEl = document.getElementById('metricTotalItems');
    const lowEl = document.getElementById('metricLowStock');
    const outEl = document.getElementById('metricOutOfStock');
    const valEl = document.getElementById('metricStockValue');

    if (totalEl) totalEl.textContent = summary.total_items;
    if (lowEl) lowEl.textContent = summary.low_stock;
    if (outEl) outEl.textContent = summary.out_of_stock;
    if (valEl) valEl.textContent = '\u20B1' + formatMoney(summary.total_value);
}

function updateInvResultsCount(shown, total) {
    const el = document.getElementById('invResultsCount');
    if (el) el.textContent = `Showing ${shown} of ${total} items`;
}

function setInvFilter(status, el) {
    document.querySelectorAll('#inventoryTabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadInventory(status);
}

// ── Manual restock/deduct via the table buttons (not barcode) ──
function manualAdjust(itemId, action, itemName) {
    const qty = prompt(`How many units to ${action} for "${itemName}"?`, '1');
    if (!qty || isNaN(qty) || parseInt(qty) <= 0) return;

    const formData = new FormData();
    formData.append('item_id', itemId);
    formData.append('action', action);
    formData.append('quantity', qty);
    formData.append('performed_by', 'admin');

    // manual_adjust.php is a thin wrapper since scan_handler.php expects a barcode;
    // simplest is to look up barcode from the row first
    const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
    const barcode = row ? row.dataset.barcode : null;
    if (!barcode) {
        alert('Could not find barcode for this item.');
        return;
    }
    formData.set('barcode', barcode);

    fetch('backend/inventory/scan_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showScanToast(data.message + ` New stock: ${data.stock_after}`, 'success');
                loadInventory(currentInvFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

function editItem(itemId) {
    alert('Item #' + itemId + ' — edit form modal can be added here.');
}

// ── USB HID Barcode Scanner integration ──
function handleScannedBarcode(barcode) {
    const action = confirm(
        `Scanned barcode: ${barcode}\n\nClick OK to RESTOCK this item, or Cancel to DEDUCT stock.`
    ) ? 'restock' : 'deduct';

    const qty = prompt(`Quantity to ${action}:`, '1');
    if (!qty || isNaN(qty) || parseInt(qty) <= 0) return;

    const formData = new FormData();
    formData.append('barcode', barcode);
    formData.append('action', action);
    formData.append('quantity', qty);
    formData.append('performed_by', 'admin');

    fetch('backend/inventory/scan_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showScanToast(`${data.item_name}: ${data.action} ${data.quantity} — new stock ${data.stock_after}`, 'success');
                loadInventory(currentInvFilter);
            } else if (data.unknown) {
                if (confirm(`Barcode ${barcode} is not registered. Add it as a new item now?`)) {
                    promptNewItem(barcode);
                }
            } else {
                showScanToast(data.message, 'error');
            }
        })
        .catch(err => showScanToast('Network error: ' + err, 'error'));
}

function promptNewItem(barcode) {
    const itemName = prompt('Item name:');
    if (!itemName) return;
    const unit = prompt('Unit (e.g. Bottle, Piece, Pack):', 'Piece') || 'Piece';
    const unitCost = prompt('Unit cost (₱):', '0') || '0';
    const initialStock = prompt('Initial stock quantity:', '0') || '0';
    const reorderLevel = prompt('Reorder level (low stock alert threshold):', '5') || '5';

    const formData = new FormData();
    formData.append('barcode', barcode);
    formData.append('item_name', itemName);
    formData.append('unit', unit);
    formData.append('unit_cost', unitCost);
    formData.append('current_stock', initialStock);
    formData.append('reorder_level', reorderLevel);

    fetch('backend/inventory/add_item.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showScanToast(data.message, 'success');
                loadInventory(currentInvFilter);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Network error: ' + err));
}

// ── Small toast notification instead of plain alert() for scan feedback ──
function showScanToast(message, type = 'success') {
    let toast = document.getElementById('scanToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'scanToast';
        toast.style.position = 'fixed';
        toast.style.bottom = '24px';
        toast.style.right = '24px';
        toast.style.padding = '12px 18px';
        toast.style.borderRadius = '10px';
        toast.style.fontSize = '13px';
        toast.style.fontFamily = 'system-ui, sans-serif';
        toast.style.color = '#fff';
        toast.style.zIndex = '9999';
        toast.style.boxShadow = '0 6px 20px rgba(0,0,0,0.15)';
        toast.style.transition = 'opacity 0.3s';
        document.body.appendChild(toast);
    }
    toast.style.background = type === 'success' ? '#1F8FA0' : '#E24B4A';
    toast.textContent = message;
    toast.style.opacity = '1';
    clearTimeout(toast._hideTimeout);
    toast._hideTimeout = setTimeout(() => { toast.style.opacity = '0'; }, 3500);
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

document.addEventListener('DOMContentLoaded', () => loadInventory('all'));