document.addEventListener('DOMContentLoaded', () => {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
        customClass: 'custom-tooltip',
        offset: [0, 8]
    }));

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
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
        const max = Math.max(10, value);
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
        updateCounter();
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
            const turfName = row.querySelector('td:nth-child(1) .fw-semibold')?.textContent.toLowerCase() || '';
            const teamName = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
            const matches = query === '' || turfName.includes(query) || teamName.includes(query);
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

    // Turf search functionality
    const turfSearchInput = document.getElementById('turfSearch');
    const turfClearSearchBtn = document.getElementById('clearSearch');
    const turfContainer = document.getElementById('turfContainer');
    const turfItems = turfContainer ? turfContainer.querySelectorAll('.turf-item') : [];
    const noResults = document.getElementById('noResults');

    function filterTurfs() {
        const query = turfSearchInput.value.trim().toLowerCase();
        let visibleItems = 0;
        turfItems.forEach(item => {
            const turfName = item.querySelector('.card-title')?.textContent.toLowerCase() || '';
            const turfAddress = item.querySelector('.turf-address')?.textContent.toLowerCase() || '';
            const turfFacility = item.querySelector('.turf-facility')?.textContent.toLowerCase() || '';
            const matches = query === '' || turfName.includes(query) || turfAddress.includes(query) || turfFacility.includes(query);
            item.style.display = matches ? '' : 'none';
            if (matches) visibleItems++;
        });
        noResults.style.display = visibleItems === 0 && query !== '' ? 'block' : 'none';
        turfClearSearchBtn.style.display = query ? 'block' : 'none';
    }

    if (turfSearchInput) {
        turfSearchInput.addEventListener('input', filterTurfs);
    }

    if (turfClearSearchBtn) {
        turfClearSearchBtn.addEventListener('click', () => {
            turfSearchInput.value = '';
            filterTurfs();
        });
    }

    // Smooth scroll to sections
    document.querySelectorAll('.teams-section h2, .bookings-section h2').forEach(header => {
        header.addEventListener('click', () => {
            header.parentElement.scrollIntoView({ behavior: 'smooth' });
        });
    });
});

// Custom tooltip styling
const style = document.createElement('style');
style.textContent = `
    .custom-tooltip .tooltip-inner {
        background: var(--primary-color);
        color: #fff;
        border-radius: 6px;
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    .custom-tooltip .tooltip-arrow::before {
        border-top-color: var(--primary-color);
    }
`;
document.head.appendChild(style);