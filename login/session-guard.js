/**
 * session-guard.js
 *
 * Loaded in the <head> of EVERY admin page:
 *   <script src="backend/login/session-guard.js"></script>
 *
 *  1. Redirects to the login page when there is no session token
 *     (runs before the page draws, so the dashboard never flashes).
 *  2. Shows the logged-in admin's initials in the topbar avatar.
 *  3. Wires the sidebar "Log out" button (id="logoutBtn").
 *  4. Stops the browser Back button from showing an admin page
 *     after logging out (pages restored from the back/forward cache).
 *
 * sessionStorage clears when the tab/window closes, which is the
 * "no remember me" behaviour.
 */

function getAuthToken() {
    return sessionStorage.getItem('pawpriority_token') || '';
}

function getCurrentAdmin() {
    try { return JSON.parse(sessionStorage.getItem('pawpriority_user') || '{}'); }
    catch (e) { return {}; }
}

function redirectToLogin() {
    window.location.replace('admin login.html');
}

/**
 * PREVIEW MODE — look at the admin pages' design without logging in.
 * Only works on your own machine, never on the deployed site:
 *   • Opened as a file (double-clicked, file://...)  → always preview
 *   • localhost / 127.0.0.1 (Live Server, XAMPP)     → add ?preview to the URL once;
 *     it then stays on for every page in that tab. ?preview=0 turns it off.
 * Pages show a banner; live data won't load unless the PHP backend is running.
 */
function isPreviewMode() {
    if (location.protocol === 'file:') return true;
    const isLocal = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);
    if (!isLocal) {
        sessionStorage.removeItem('pawpriority_preview');
        return false;
    }
    const param = new URLSearchParams(location.search).get('preview');
    if (param === '0') sessionStorage.removeItem('pawpriority_preview');
    else if (param !== null) sessionStorage.setItem('pawpriority_preview', '1');
    return sessionStorage.getItem('pawpriority_preview') === '1';
}

function showPreviewBanner() {
    const bar = document.createElement('div');
    bar.id = 'previewBanner';
    bar.textContent = 'Preview mode — viewing the design without logging in. Live data needs the PHP backend running.';
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;background:#FAEEDA;color:#854F0B;' +
        'font:500 12px system-ui,sans-serif;text-align:center;padding:6px 10px;' +
        'border-bottom:1px solid #EF9F27;';
    document.body.appendChild(bar);
    document.body.style.paddingTop = '28px';
}

(function sessionGuard() {
    const loggedIn = getAuthToken() && sessionStorage.getItem('pawpriority_user');

    if (!loggedIn) {
        if (isPreviewMode()) {
            document.addEventListener('DOMContentLoaded', function () {
                showPreviewBanner();
                const logoutBtn = document.getElementById('logoutBtn');
                if (logoutBtn) {
                    logoutBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        sessionStorage.removeItem('pawpriority_preview');
                        redirectToLogin();
                    });
                }
            });
            return;
        }
        redirectToLogin();
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const userData = getCurrentAdmin();

        const avatar = document.querySelector('.avatar');
        if (avatar && userData.name) {
            const parts    = userData.name.trim().split(/\s+/);
            const initials = parts.length >= 2
                ? parts[0][0] + parts[parts.length - 1][0]
                : parts[0].slice(0, 2);
            avatar.textContent = initials.toUpperCase();
            avatar.title = userData.name + (userData.email ? ' (' + userData.email + ')' : '');
        }

        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function (e) {
                e.preventDefault();
                handleLogout();
            });
        }
    });
})();

// Back button after logout: a page restored from the back/forward
// cache does not re-run scripts, so check the session again here.
window.addEventListener('pageshow', function (e) {
    if (e.persisted && !getAuthToken() && !isPreviewMode()) redirectToLogin();
});

/**
 * Logs out the current admin:
 *   1. Asks for confirmation
 *   2. Calls logout.php to delete the session token on the server
 *   3. Clears sessionStorage and goes to the login page
 *      (even if the server can't be reached, the browser session is cleared)
 */
function handleLogout() {
    if (!confirm('Log out of PawPriority Admin?')) return;

    const formData = new FormData();
    formData.append('token', getAuthToken());

    fetch('backend/user_accounts/logout.php', { method: 'POST', body: formData })
        .catch(function () { /* still log out locally */ })
        .finally(function () {
            sessionStorage.removeItem('pawpriority_token');
            sessionStorage.removeItem('pawpriority_user');
            sessionStorage.removeItem('pawpriority_preview');
            redirectToLogin();
        });
}
