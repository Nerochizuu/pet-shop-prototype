/**
 *
 * USB HID barcode scanners act exactly like a keyboard: when you
 * scan a barcode, the scanner "types" each character very fast
 * and finishes with an Enter (or sometimes Tab) keypress.
 *
 * This script listens for keystrokes anywhere on the page,
 * buffers them, and treats a fast burst of characters ending
 * in Enter as a scanned barcode (instead of someone typing
 * normally on a keyboard, which is much slower per keystroke).
 *
 * Usage: include this file in inventory.html, then call
 * initBarcodeScanner({ onScan: function(barcode) {...} })
 */

function initBarcodeScanner(options) {
    const onScan = options.onScan || function () {};
    const maxDelayBetweenKeys = 50; // ms — scanner types much faster than a human

    let buffer = '';
    let lastKeyTime = 0;

    document.addEventListener('keydown', function (e) {
        const now = Date.now();

        // If user is actively typing in a normal text input/search box,
        // don't hijack their keystrokes — only listen when no input
        // is focused, OR when input has data-scanner-target="true"
        const active = document.activeElement;
        const isTypingField = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA');
        const allowedField = active && active.dataset && active.dataset.scannerTarget === 'true';

        if (isTypingField && !allowedField) {
            return;
        }

        // Reset buffer if there's been a pause longer than maxDelayBetweenKeys
        // (real scanners fire keys within a few ms of each other)
        if (now - lastKeyTime > maxDelayBetweenKeys && buffer.length > 0) {
            buffer = '';
        }
        lastKeyTime = now;

        if (e.key === 'Enter') {
            if (buffer.length >= 6) { // barcodes are typically 8-13+ digits
                onScan(buffer);
            }
            buffer = '';
            return;
        }

        // Only accumulate printable characters (digits/letters)
        if (e.key.length === 1) {
            buffer += e.key;
        }
    });
}

