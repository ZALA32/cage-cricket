document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('verifyForm');
    const alertContainer = document.getElementById('alertContainer');
    const countdownText = document.getElementById('countdownText');
    const otpMetaBox = document.querySelector('.otp-meta');
    const otpExpiredTryBox = document.getElementById('resendExpiredBox');
    const otpMaxedOutBox = document.getElementById('otpMaxedOutBox');
    const resendLink = document.getElementById('resendLink');

    let remainingSeconds = window.remainingSeconds || 0;
    const resendAttempts = window.resendAttempts || 0;

    // Countdown timer
    const timerInterval = setInterval(() => {
        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);

            if (otpMetaBox) otpMetaBox.classList.add('d-none');

            if (resendAttempts < 3 && otpExpiredTryBox) {
                otpExpiredTryBox.classList.remove('d-none');
            } else if (resendAttempts >= 3 && otpMaxedOutBox) {
                otpMaxedOutBox.classList.remove('d-none');
            }

            if (resendLink) {
                resendLink.classList.add('disabled');
                resendLink.style.pointerEvents = 'none';
                resendLink.textContent = 'Resend Disabled';
            }
        } else {
            const min = Math.floor(remainingSeconds / 60);
            const sec = remainingSeconds % 60;
            if (countdownText) countdownText.textContent = `${min}:${sec < 10 ? '0' : ''}${sec}`;
            remainingSeconds--;
        }
    }, 1000);

    // Form submission
    form.addEventListener('submit', e => {
        e.preventDefault();
        const formData = new FormData(form);
        fetch('verify_otp.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${data.success ? 'custom-alert-success' : 'custom-alert-error'} fade show text-center`;
            alertDiv.innerHTML = `${data.message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);
            setTimeout(() => { alertDiv.remove(); }, 2000);
            if (data.success && data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 2000);
            }
        });
    });

    // Resend cooldown (30s)
    if (resendLink) {
        resendLink.addEventListener('click', () => {
            if (remainingSeconds > 0) {
                resendLink.classList.add('disabled');
                resendLink.style.pointerEvents = 'none';
                resendLink.textContent = 'Please wait';
                setTimeout(() => {
                    resendLink.classList.remove('disabled');
                    resendLink.style.pointerEvents = 'auto';
                    resendLink.textContent = 'Resend OTP';
                }, 30000);
            }
        });
    }
});
