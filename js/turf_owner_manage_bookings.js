document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts after 10 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            if (alert && alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 10000);
    });

    // Payment Method Chart (guard if canvas not present)
    const chartData = window.chartData || { cash: 0, other: 0 };
    const chartCanvas = document.getElementById('paymentChart');
    if (chartCanvas) {
        const ctx = chartCanvas.getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Cash Payments', 'Online Payments'],
                datasets: [{
                    data: [chartData.cash, chartData.other],
                    backgroundColor: ['#0dcaf0', '#28a745'],
                    borderColor: ['#ffffff', '#ffffff'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 14 } }
                    },
                    title: {
                        display: true,
                        text: 'Payment Methods for Bookings',
                        font: { size: 18 }
                    },
                    tooltip: {
                        enabled: chartData.cash > 0 || chartData.other > 0
                    }
                }
            }
        });
    }

    // ===== Confirm Cash Collection =====
    let activeFormId = null;
    const confirmButtons = document.querySelectorAll('.confirm-cash-btn');
    confirmButtons.forEach(button => {
        button.addEventListener('click', function () {
            activeFormId = 'confirm-cash-form-' + this.getAttribute('data-booking-id');
        });
    });

    const confirmCashSubmit = document.getElementById('confirmCashSubmit');
    if (confirmCashSubmit) {
        confirmCashSubmit.addEventListener('click', function () {
            confirmCashSubmit.disabled = true; // prevent double submit
            if (activeFormId) {
                const form = document.getElementById(activeFormId);
                if (form) { form.submit(); }
            }
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmCashModal'));
            if (modal) { modal.hide(); }
            // Re-enable after a tick in case form submission is blocked by browser
            setTimeout(() => { confirmCashSubmit.disabled = false; }, 1500);
        });
    }

    // ===== Turf Owner Cancel Booking =====
    let activeCancelFormId = null;

    document.querySelectorAll('.cancel-booking-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const bookingId   = this.getAttribute('data-booking-id');
            const bookingName = this.getAttribute('data-booking-name') || '';
            const bookingDate = this.getAttribute('data-booking-date') || '';

            activeCancelFormId = 'cancel-booking-form-' + bookingId;

            const nameEl = document.getElementById('cb-booking-name');
            const dateEl = document.getElementById('cb-booking-date');
            const reasonEl = document.getElementById('cb-reason');

            if (nameEl) nameEl.textContent = bookingName;
            if (dateEl) dateEl.textContent = bookingDate;
            if (reasonEl) { reasonEl.value = ''; reasonEl.classList.remove('is-invalid'); }
        });
    });

    const cancelSubmit = document.getElementById('cancelBookingSubmit');
    if (cancelSubmit) {
        cancelSubmit.addEventListener('click', function () {
            cancelSubmit.disabled = true; // prevent double submit

            const reasonEl = document.getElementById('cb-reason');
            const reason = (reasonEl?.value || '').trim();

            if (!reason) {
                cancelSubmit.disabled = false; // re-enable since invalid
                if (reasonEl) { reasonEl.classList.add('is-invalid'); reasonEl.focus(); }
                return;
            }

            if (activeCancelFormId) {
                const form = document.getElementById(activeCancelFormId);
                if (form) {
                    const hiddenReason = form.querySelector('input[name="reason"]');
                    if (hiddenReason) hiddenReason.value = reason;
                    form.submit();
                } else {
                    cancelSubmit.disabled = false; // safety re-enable
                }
            } else {
                cancelSubmit.disabled = false; // safety re-enable
            }

            const modalEl = document.getElementById('cancelBookingModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        });
    }
});
