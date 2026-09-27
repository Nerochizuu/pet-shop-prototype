/**
 * booking-form.js
 *
 * Wires the customer-facing "Schedule Appointment" modal
 * (the one in packages.html / home.html) to submit_appointment.php.
 *
 * Include this script in packages.html, right before </body>,
 * AFTER the modal HTML itself. Update the form field names below
 * to match your actual <input> name attributes if they differ.
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.appointment-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData();

        // Map each input by its position/label in your modal.
        // Adjust these selectors if you add `name` attributes to your inputs.
        const inputs = form.querySelectorAll('input, select');

        // Assumes order: First Name, Last Name, Email, Contact Number, Date, Time
        // Safer: give each <input> a name="..." attribute matching these keys.
        const fieldMap = {
            first_name: form.querySelector('input[name="first_name"]'),
            last_name: form.querySelector('input[name="last_name"]'),
            email: form.querySelector('input[name="email"]'),
            contact_number: form.querySelector('input[name="contact_number"]'),
            pet_name: form.querySelector('input[name="pet_name"]'),
            pet_breed: form.querySelector('input[name="pet_breed"]'),
            service_id: form.querySelector('select[name="service_id"]'),
            appointment_date: form.querySelector('input[name="appointment_date"]'),
            appointment_time: form.querySelector('input[name="appointment_time"]')
        };

        let missing = [];
        for (const key in fieldMap) {
            const el = fieldMap[key];
            if (!el) {
                missing.push(key);
                continue;
            }
            formData.append(key, el.value.trim());
        }

        if (missing.length > 0) {
            console.warn('Missing form fields (add name attributes to your inputs):', missing);
            alert('Booking form is missing some fields. Please contact support.');
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        fetch('/backend/appointments/submit-appointment.php', {
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
                alert('Network error. Please try again.');
                console.error(err);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
    });
});