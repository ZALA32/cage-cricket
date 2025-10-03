document.addEventListener('DOMContentLoaded', () => {
    const togglePasswordIcons = document.querySelectorAll('.toggle-password');
    togglePasswordIcons.forEach(icon => {
        icon.addEventListener('click', () => {
            const targetId = icon.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.classList.toggle('bi-eye-slash', !isPassword);
            icon.classList.toggle('bi-eye', isPassword);
        });
    });

    const form = document.getElementById('registerForm');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const alertContainer = document.getElementById('alertContainer');

    const removeErrors = () => {
        document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
    };

    const showError = (message, field = null) => {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'custom-alert custom-alert-error fade show text-center';
        errorDiv.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        alertContainer.innerHTML = '';
        alertContainer.appendChild(errorDiv);

        if (field) {
            field.classList.add('input-error');
            field.focus();
        }
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        alertContainer.innerHTML = '';
        removeErrors();

        const submitButton = form.querySelector('button[type="submit"]');
        const name = form.querySelector('input[name="name"]');
        const email = form.querySelector('input[name="email"]');
        const contact = form.querySelector('input[name="contact_no"]');
        const role = form.querySelector('select[name="role"]');

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        // Validate each field
        if (name.value.trim() === '') {
            showError('Name cannot be empty!', name);
            return;
        }
        if (!emailPattern.test(email.value)) {
            showError('Invalid email format!', email);
            return;
        }
        if (!/^\d{10}$/.test(contact.value)) {
            showError('Contact number must be 10 digits.', contact);
            return;
        }
        if (password.value.trim() === '') {
            showError('Password cannot be empty!', password);
            return;
        }
        if (confirmPassword.value.trim() === '') {
            showError('Please confirm your password.', confirmPassword);
            return;
        }
        if (password.value !== confirmPassword.value) {
            showError('Passwords do not match!', confirmPassword);
            return;
        }
        if (!role.value) {
            showError('Please select a role!', role);
            return;
        }

        // ✅ All validations passed — show spinner and submit
        submitButton.disabled = true;
        submitButton.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Registering`;

        const formData = new FormData(form);

        fetch('register.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${data.success ? 'custom-alert-success' : 'custom-alert-error'} fade show text-center`;
            alertDiv.innerHTML = `${data.message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);

            if (data.success && data.redirect) {
                form.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
                setTimeout(() => window.location.href = data.redirect, 1500);
            } else {
                submitButton.disabled = false;
                submitButton.innerHTML = "Register Now";
            }
        })
        .catch(() => {
            showError('An error occurred. Please try again.');
            submitButton.disabled = false;
            submitButton.innerHTML = "Register Now";
        });
    });
});
