document.addEventListener('DOMContentLoaded', () => {
    // Show/hide password
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

    // Auto fade reset alert after 4s
    const resetAlert = document.getElementById('resetAlert');
    if (resetAlert) {
        setTimeout(() => {
            resetAlert.remove();
        }, 2000);
    }

    // Handle login via AJAX
    const form = document.getElementById('loginForm');
    const alertContainer = document.getElementById('alertContainer');

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);

        fetch('login.php', {
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
            setTimeout(() => {
                alertDiv.remove();
            }, 2000);
            if (data.success && data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 2000);
            }
        })
        .catch(() => {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'custom-alert custom-alert-error fade show text-center';
            errorDiv.innerHTML = 'An error occurred. Please try again.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            alertContainer.innerHTML = '';
            alertContainer.appendChild(errorDiv);
            setTimeout(() => {
                errorDiv.remove();
            }, 2000);
        });
    });
});