window.onload = function () {
    const turfIdInput = document.getElementById('turf-id');
    const turfId = turfIdInput ? parseInt(turfIdInput.value) : null;

    // Initialize Flatpickr
    const datePicker = flatpickr('#date-picker', {
        dateFormat: 'Y-m-d',
        minDate: 'today',
        disableMobile: true,
        onChange: (selectedDates, dateStr) => {
            updateCostBreakdown();
            updateStartTimes(dateStr);
            checkTimeSlots(dateStr);
            loadAvailabilityGrid(dateStr);
        }
    });

    // Auto-dismiss alerts
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            if (alert && alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 10000);
    });

    // Cost Breakdown
    const form = document.getElementById('booking-form');
    const baseCostEl = document.getElementById('base-cost');
    const servicesCostEl = document.getElementById('services-cost');
    const totalCostEl = document.getElementById('total-cost');
    const pricingTypeEl = document.getElementById('pricing-type');
    const discountAmountEl = document.getElementById('discount-amount');
    const discountFeedback = document.querySelector('.discount-feedback');
    const modalCostBreakdown = document.getElementById('modal-cost-breakdown');

    // Audience Capacity Validation
    const totalAudienceInput = document.getElementById('total_audience');
    const capacityWarning = document.querySelector('.capacity-warning');

    totalAudienceInput.addEventListener('input', () => {
        const value = parseInt(totalAudienceInput.value) || 0;
        if (value > maxCapacity) {
            totalAudienceInput.classList.add('is-invalid');
            capacityWarning.classList.add('show');
            totalAudienceInput.setCustomValidity(`Audience count cannot exceed ${maxCapacity}.`);
        } else {
            totalAudienceInput.classList.remove('is-invalid');
            capacityWarning.classList.remove('show');
            totalAudienceInput.setCustomValidity('');
        }
        updateCostBreakdown();
    });

    form.addEventListener('change', updateCostBreakdown);

    function checkPricingType(date) {
        const selectedDate = new Date(date);
        const isWeekend = selectedDate.getDay() === 0 || selectedDate.getDay() === 6;
        return {
            isWeekend: isWeekend,
            label: isWeekend ? 'Weekend (+20%)' : 'Weekday'
        };
    }

    function updateCostBreakdown() {
        const duration = parseInt(form.querySelector('[name="duration"]').value) || 0;
        const services = Array.from(form.querySelectorAll('[name="services[]"]:checked')).map(input => input.value);
        const date = form.querySelector('[name="date"]').value;
        const pricing = checkPricingType(date);
        let baseCost = bookingCost * duration;
        if (pricing.isWeekend) baseCost *= 1.20;

        const servicesCost = services.reduce((sum, key) => sum + servicesCosts[key].cost, 0);
        let totalCost = baseCost + servicesCost;
        let discount = 0;
        let discountMessage = '';

        if (duration >= 3 && !pricing.isWeekend) {
            discount = totalCost * 0.10;
            totalCost *= 0.90;
            discountMessage = ' (10% off for 3+ hours)';
            discountFeedback.classList.add('show');
        } else {
            discountFeedback.classList.remove('show');
        }

        // Update cost breakdown
        baseCostEl.textContent = baseCost.toFixed(2);
        servicesCostEl.textContent = servicesCost.toFixed(2);
        pricingTypeEl.textContent = pricing.label;
        pricingTypeEl.classList.toggle('weekend', pricing.isWeekend);
        discountAmountEl.textContent = discount.toFixed(2) + discountMessage;
        totalCostEl.textContent = totalCost.toFixed(2);

        // Update modal to match cost breakdown format
        modalCostBreakdown.innerHTML = `
            <p>Base Cost: <span>₹${baseCost.toFixed(2)}</span></p>
            <p>Services Cost: <span>₹${servicesCost.toFixed(2)}</span></p>
            <p>Pricing Type: <span>${pricing.label}</span></p>
            <p>Discount: <span>₹${discount.toFixed(2)}${discountMessage}</span></p>
            <p class="total">Total Cost: <span>₹${totalCost.toFixed(2)}</span></p>
        `;
    }

    const startTimeSelect = document.getElementById('start-time');
    const durationSelect = document.getElementById('duration');
    const slotWarning = document.querySelector('.slot-warning');

    function updateStartTimes(date) {
        startTimeSelect.innerHTML = '<option value="">Select start time</option>';
        const isToday = date === currentDate;
        const now = isToday ? new Date(`1970-01-01T${currentTime}:00`) : null;

        availableTimes.forEach(time => {
            if (isToday) {
                const timeDate = new Date(`1970-01-01T${time}:00`);
                if (timeDate <= now) return; // Skip past times for today
            }
            const option = document.createElement('option');
            option.value = time;
            option.textContent = new Date(`1970-01-01T${time}:00`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            startTimeSelect.appendChild(option);
        });
        checkTimeSlots(date);
    }

    function checkTimeSlots(date) {
        if (!date || !turfId) return;

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `check_slots=true&date=${encodeURIComponent(date)}`
        })
            .then(response => response.json())
            .then(data => {
                const conflicts = data.conflicts || [];
                const duration = parseInt(durationSelect.value) || 1;
                const isToday = date === currentDate;
                const now = isToday ? new Date(`1970-01-01T${currentTime}:00`) : null;

                startTimeSelect.querySelectorAll('option:not([value=""])').forEach(option => {
                    const startTime = option.value;
                    const startDt = new Date(`1970-01-01T${startTime}:00`);
                    const endDt = new Date(startDt.getTime() + duration * 60 * 60 * 1000);

                    if (isToday && startDt <= now) {
                        option.disabled = true;
                        option.textContent = startTime + ' (Past)';
                        return;
                    }

                    const hasConflict = conflicts.some(conflict => {
                        const bookedStart = new Date(`1970-01-01T${conflict.start_time}:00`);
                        const bookedEnd = new Date(`1970-01-01T${conflict.end_time}:00`);
                        return (startDt < bookedEnd && endDt > bookedStart);
                    });

                    option.disabled = hasConflict;
                    option.textContent = startTime + (hasConflict ? ' (Booked)' : '');
                    if (hasConflict && startTimeSelect.value === startTime) {
                        slotWarning.classList.add('show');
                        startTimeSelect.classList.add('is-invalid');
                        startTimeSelect.setCustomValidity('This time slot is already booked.');
                    } else if (startTimeSelect.value === startTime) {
                        slotWarning.classList.remove('show');
                        startTimeSelect.classList.remove('is-invalid');
                        startTimeSelect.setCustomValidity('');
                    }
                });
            })
            .catch(err => {
                console.error('Conflict check error:', err);
            });
    }

    function loadAvailabilityGrid(date) {
        const grid = document.getElementById('availability-grid');
        if (!grid || !date || !turfId) {
            grid.innerHTML = '<p>No availability data.</p>';
            return;
        }

        grid.innerHTML = '<p>Loading...</p>';

        fetch('fetch_turf_availability.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `date=${encodeURIComponent(date)}&turf_id=${turfId}`
        })
            .then(res => res.json())
            .then(data => {
                if (!data || !data.slots) {
                    grid.innerHTML = '<p>No availability data.</p>';
                    return;
                }

                grid.innerHTML = '';
                const isToday = date === currentDate;
                const now = isToday ? new Date(`1970-01-01T${currentTime}:00`) : null;

                data.slots.forEach(slot => {
                    const startTime = new Date(`1970-01-01T${slot.start}:00`);
                    if (isToday && startTime <= now) return; // Skip past times for today

                    const block = document.createElement('div');
                    block.classList.add('availability-block', slot.status);
                    let tooltipText;
                    if (slot.status === 'available') {
                        tooltipText = `${slot.start}: Free to book`;
                    } else if (slot.status === 'partial') {
                        tooltipText = `${slot.start}: Pending approval`;
                    } else {
                        tooltipText = `${slot.start}: Unavailable`;
                    }
                    block.setAttribute('data-tooltip', tooltipText);
                    block.textContent = slot.start;
                    grid.appendChild(block);
                });
            })
            .catch(err => {
                console.error('Availability error:', err);
                grid.innerHTML = '<p>Failed to load availability.</p>';
            });
    }

    // Initial trigger on page load
    const initialDate = form.querySelector('[name="date"]').value;
    if (initialDate) {
        updateStartTimes(initialDate);
        loadAvailabilityGrid(initialDate);
        updateCostBreakdown();
    }

    // Event listeners
    startTimeSelect.addEventListener('change', () => {
        checkTimeSlots(form.querySelector('[name="date"]').value);
        updateCostBreakdown();
    });

    durationSelect.addEventListener('change', () => {
        checkTimeSlots(form.querySelector('[name="date"]').value);
        updateCostBreakdown();
    });
};