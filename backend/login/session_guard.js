/**
 * session-guard.js
 *
 * Include this script at the TOP of every admin page
 * (right after the <head> CSS links, before </head> or
 * as the first script in <body>).
 *
 * On every page load it checks sessionStorage for a valid
 * token. If none is found it immediately redirects to the
 * login page — preventing unauthorized access.
 *
 * Since sessionStorage is cleared when the browser tab/window
 * is closed, this also handles the "no remember me" requirement.
 *
 * Usage: add this to every admin HTML page:
 *   <script src="backend/login/session-guard.js"></script>
 *
 * Then in your JS files, get the logged-in user with:
 *   const user = JSON.parse(sessionStorage.getItem('pawpriority_user'));
 */

(function sessionGuard() {
    const token = sessionStorage.getItem('pawpriority_token');
    const user  = sessionStorage.getItem('pawpriority_user');

    if (!token || !user) {
        // Not logged in — redirect to login page
        window.location.replace('admin login.html');
        return;
    }

    // Populate the topbar avatar and name from session
    document.addEventListener('DOMContentLoaded', function () {
        try {
            const userData = JSON.parse(user);

            // Update avatar initials
            const avatar = document.querySelector('.avatar');
            if (avatar && userData.name) {
                const parts    = userData.name.trim().split(' ');
                const initials = parts.length >= 2
                    ? parts[0][0] + parts[parts.length - 1][0]
                    : parts[0].slice(0, 2);
                avatar.textContent = initials.toUpperCase();
            }

            // Wire up logout if a logout button exists
            const logoutBtn = document.getElementById('logoutBtn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', handleLogout);
            }

        } catch (e) {
            console.error('Session parse error:', e);
        }
    });
})();

/**
 * Logs out the current admin:
 *   1. Calls logout.php to invalidate the server-side session token
 *   2. Clears sessionStorage
 *   3. Redirects to login page
 */
function handleLogout() {
    const token    = sessionStorage.getItem('pawpriority_token');
    const formData = new FormData();
    formData.append('token', token);

    fetch('backend/user_accounts/logout.php', { method: 'POST', body: formData })
        .finally(() => {
            sessionStorage.removeItem('pawpriority_token');
            sessionStorage.removeItem('pawpriority_user');
            window.location.replace('admin login.html');
        });
}