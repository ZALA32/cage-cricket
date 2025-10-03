document.addEventListener('DOMContentLoaded', () => {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
        customClass: 'custom-tooltip',
        offset: [0, 8]
    }));

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-green');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert && alert.parentNode) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }
        }, 5000);
    });

    // Animate progress circles
    const progressCircles = document.querySelectorAll('.circle-progress');
    progressCircles.forEach(circle => {
        const value = parseInt(circle.dataset.value) || 0;
        const max = parseInt(circle.dataset.max) || 10; // Use dynamic max from data-max
        const radius = 44;
        const circumference = 2 * Math.PI * radius;
        const progress = circle.querySelector('.progress-ring-circle');
        progress.style.strokeDasharray = circumference;
        progress.style.strokeDashoffset = circumference - (value / max) * circumference;
    });

    // Animate counter values
    const counters = document.querySelectorAll('.progress-value');
    counters.forEach(counter => {
        const target = parseInt(counter.textContent);
        let current = 0;
        const increment = target / 50;
        const updateCounter = () => {
            if (current < target) {
                current += increment;
                counter.textContent = Math.ceil(current);
                requestAnimationFrame(updateCounter);
            } else {
                counter.textContent = target;
            }
        };
        requestAnimationFrame(updateCounter);
    });

    // Booking search functionality
    const searchInput = document.getElementById('bookingSearch');
    const clearSearchBtn = document.getElementById('clearSearch');
    const bookingsTable = document.getElementById('bookingsTable');
    const bookingRows = bookingsTable ? bookingsTable.querySelectorAll('tr') : [];

    function filterBookings() {
        const query = searchInput.value.trim().toLowerCase();
        let visibleRows = 0;
        bookingRows.forEach(row => {
            const turfNameElement = row.querySelector('td:nth-child(1) .fw-bold');
            const turfName = turfNameElement ? turfNameElement.textContent.toLowerCase() : '';
            const matches = query === '' || turfName.includes(query);
            row.style.display = matches ? '' : 'none';
            if (matches) visibleRows++;
        });
        clearSearchBtn.style.display = query ? 'block' : 'none';
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterBookings);
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            filterBookings();
        });
    }

    // Smooth scroll to sections
    document.querySelectorAll('.teams-section h2, .bookings-section h2').forEach(header => {
        header.addEventListener('click', () => {
            header.parentElement.scrollIntoView({ behavior: 'smooth' });
        });
    });

    // Custom tooltip styling
    const style = document.createElement('style');
    style.textContent = `
        .custom-tooltip .tooltip-inner {
            background: #198754;
            color: #fff;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        .custom-tooltip .tooltip-arrow::before {
            border-top-color: #198754;
        }
    `;
    document.head.appendChild(style);
});