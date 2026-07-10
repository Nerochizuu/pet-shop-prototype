/**
 * dashboard-inventory.js
 *
 * Loads a live snapshot of inventory into the "Inventory snapshot"
 * card on admin-dashboard.html (the .inv-row list with progress bars).
 *
 * Shows the items closest to running out first (lowest stock relative
 * to reorder level), capped to a handful of rows so the card stays compact.
 */

function loadInventorySnapshot() {
    fetch('backend/inventory/get_inventory.php?status=all')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to load inventory snapshot:', data.message);
                return;
            }
            renderInventorySnapshot(data.items);
            updateLowStockMetric(data.summary);
        })
        .catch(err => console.error('Network error loading inventory snapshot:', err));
}

function renderInventorySnapshot(items) {
    const container = document.getElementById('inventorySnapshotList');
    if (!container) return;

    // Sort by how close to (or past) reorder level they are — most urgent first
    const sorted = [...items].sort((a, b) => {
        const aRatio = a.reorder_level > 0 ? a.current_stock / a.reorder_level : 999;
        const bRatio = b.reorder_level > 0 ? b.current_stock / b.reorder_level : 999;
        return aRatio - bRatio;
    });

    const topItems = sorted.slice(0, 6); // keep the card compact

    if (topItems.length === 0) {
        container.innerHTML = `<div style="padding:12px 0; color:#5A8A9A; font-size:12px;">No inventory items yet.</div>`;
        return;
    }

    container.innerHTML = topItems.map(item => {
        const pct = item.reorder_level > 0
            ? Math.min(100, Math.round((item.current_stock / (item.reorder_level * 2)) * 100))
            : 100;
        const barColor = item.stock_status === 'out_of_stock' ? '#E24B4A'
                        : item.stock_status === 'low' ? '#EF9F27'
                        : '#1D9E75';
        const qtyClass = item.stock_status === 'out_of_stock' ? 'critical'
                        : item.stock_status === 'low' ? 'low' : '';

        return `
            <div class="inv-row">
                <span class="inv-name">${escapeHtmlDash(item.item_name)}</span>
                <div class="inv-bar-wrap"><div class="inv-bar" style="width:${pct}%; background:${barColor};"></div></div>
                <span class="inv-qty ${qtyClass}">${item.current_stock} left</span>
            </div>
        `;
    }).join('');
}

function updateLowStockMetric(summary) {
    const el = document.getElementById('metricLowStockAlerts');
    if (el) el.textContent = summary.low_stock + summary.out_of_stock;
}

function escapeHtmlDash(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadInventorySnapshot);