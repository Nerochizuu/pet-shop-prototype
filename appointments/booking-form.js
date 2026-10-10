/**
 * booking-form.js
 *
 * Wires the customer-facing "Schedule Appointment" modal in
 * packages.html (form id="appointmentForm") to submit_appointment.php.
 *
 * Place this script tag right before </body> in packages.html,
 * AFTER the modal HTML and AFTER the existing inline <script> block
 * (so openModal/closeModal are already defined).
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('appointmentForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(form); // reads all name="..." fields automatically

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        fetch('backend/appointments/submit_appointment.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    form.reset();
                    if (typeof closeModal === 'function') closeModal();
                } else {
                    alert('Could not submit booking: ' + data.message);
                }
            })
            .catch(err => {
                alert('Network error. Please make sure the server is running and try again.');
                console.error(err);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
    });
});
