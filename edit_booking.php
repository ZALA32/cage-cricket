<?php
ob_start();
require 'config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['team_organizer', 'admin'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';
$errors = [];

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Get current IST time
$current_date = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$current_date_str = $current_date->format('Y-m-d');
$current_time = $current_date->format('H:i');

// Check if booking_id is provided
if (!isset($_GET['booking_id']) || !is_numeric($_GET['booking_id'])) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger'>Invalid booking selected.</div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

$booking_id = (int)$_GET['booking_id'];

// Fetch booking details, turf info, and owner info from users table
$query = "SELECT b.*, t.turf_name, t.booking_cost, t.turf_capacity, t.turf_area, t.turf_photo, t.turf_address, t.is_active, t.owner_id, u.name AS owner_name, u.email AS owner_email
          FROM bookings b 
          JOIN turfs t ON b.turf_id = t.turf_id 
          JOIN users u ON t.owner_id = u.id
          WHERE b.id = ? AND b.organizer_id = ? AND t.is_active = 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$booking_result = $stmt->get_result();
if ($booking_result->num_rows === 0) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger'>Booking not found or you do not have permission to edit it.</div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}
$booking = $booking_result->fetch_assoc();
$turf_id = $booking['turf_id'];
$turf = [
    'turf_id' => $booking['turf_id'],
    'turf_name' => $booking['turf_name'],
    'booking_cost' => $booking['booking_cost'],
    'turf_capacity' => $booking['turf_capacity'],
    'turf_area' => $booking['turf_area'],
    'turf_photo' => $booking['turf_photo'],
    'turf_address' => $booking['turf_address'],
    'is_active' => $booking['is_active'],
    'owner_id' => $booking['owner_id'],
    'owner_name' => $booking['owner_name'],
    'owner_email' => $booking['owner_email']
];
$stmt->close();

// Define available services and their costs
$services_options = [
    'refreshments' => ['name' => 'Refreshments', 'cost' => 500],
    'scoreboard' => ['name' => 'Scoreboard', 'cost' => 300],
    'lighting' => ['name' => 'Extra Lighting', 'cost' => 400]
];

// Pre-populate selected services
$selected_services = !empty($booking['services']) ? array_map('trim', explode(',', $booking['services'])) : [];
$selected_services = array_filter($selected_services, fn($s) => array_key_exists(strtolower($s), $services_options));

// Generate start times
$start_times = [];
$start = new DateTime('06:00');
$end = new DateTime('22:00');
$interval = new DateInterval('PT30M');
$period = new DatePeriod($start, $interval, $end);
foreach ($period as $time) {
    $start_times[] = $time->format('H:i');
}

// Duration options
$durations = [1, 2, 3, 4];

// Function to check if a date is a weekend
function isWeekend($date) {
    $dateObj = new DateTime($date);
    $day = $dateObj->format('N');
    return $day >= 6;
}

// Handle AJAX request for time slot conflicts
if (isset($_POST['check_slots']) && isset($_POST['date'])) {
    $date = trim($_POST['date']);
    $conflicts = [];
    if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
        $query = "SELECT start_time, end_time FROM bookings 
                  WHERE turf_id = ? AND date = ? 
                  AND status IN ('approved', 'confirmed') 
                  AND id != ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isi", $turf_id, $date, $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $conflicts[] = ['start_time' => $row['start_time'], 'end_time' => $row['end_time']];
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['conflicts' => $conflicts]);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['check_slots'])) {
    $booking_name = trim($_POST['booking_name'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);
    $total_audience = trim($_POST['total_audience'] ?? '');
    $selected_services = $_POST['services'] ?? [];

    // Validate start time for current date
    if ($date === $current_date_str && $start_time <= $current_time) {
        $errors[] = "Cannot book a time slot earlier than the current time ($current_time) on the current date.";
    }

    // Calculate end time
    if ($start_time && $duration) {
        $start_dt = new DateTime($start_time);
        $start_dt->modify("+{$duration} hours");
        $end_time = $start_dt->format('H:i');
        if (strtotime($end_time) > strtotime('23:00')) {
            $errors[] = "Booking cannot extend beyond 11:00 PM.";
        }
    } else {
        $end_time = '';
    }

    // Validation
    if (empty($booking_name)) {
        $errors[] = "Booking name is required.";
    }
    if (empty($date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
        $errors[] = "Valid date is required.";
    } elseif (strtotime($date) < strtotime($current_date_str)) {
        $errors[] = "Date cannot be in the past.";
    }
    if (empty($start_time)) {
        $errors[] = "Start time is required.";
    }
    if (!in_array($duration, $durations)) {
        $errors[] = "Valid duration is required.";
    }
    if (empty($total_audience) || !is_numeric($total_audience) || $total_audience <= 0) {
        $errors[] = "Valid audience count is required.";
    } elseif ($total_audience > $turf['turf_capacity']) {
        $errors[] = "Audience count exceeds turf capacity ({$turf['turf_capacity']}).";
    }
    foreach ($selected_services as $service) {
        if (!array_key_exists($service, $services_options)) {
            $errors[] = "Invalid service selected.";
        }
    }

    // Calculate cost
    if (empty($errors)) {
        $base_cost = $turf['booking_cost'] * $duration;
        if (isWeekend($date)) {
            $base_cost *= 1.20;
        }
        $services_cost = 0;
        foreach ($selected_services as $service) {
            $services_cost += $services_options[$service]['cost'];
        }
        $total_cost = $base_cost + $services_cost;
        if ($duration >= 3 && !isWeekend($date)) {
            $total_cost *= 0.90;
        }
        $services_text = !empty($selected_services) ? implode(', ', array_map(fn($s) => $services_options[$s]['name'], $selected_services)) : '';
    }

    // Check for booking conflicts
    if (empty($errors)) {
        $query = "SELECT id FROM bookings 
                  WHERE turf_id = ? AND date = ? 
                  AND status IN ('approved', 'confirmed') 
                  AND id != ? 
                  AND (
                      (start_time < ? AND end_time > ?) OR
                      (start_time < ? AND end_time > ?) OR
                      (start_time >= ? AND end_time <= ?)
                  )";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isssssssi", $turf_id, $date, $booking_id, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "Turf is already booked for this time slot.";
        }
        $stmt->close();
    }

    // Update booking if no errors
    if (empty($errors)) {
        $query = "UPDATE bookings SET turf_id = ?, organizer_id = ?, booking_name = ?, date = ?, start_time = ?, end_time = ?, total_audience = ?, services = ?, total_cost = ?, status = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        $status = 'pending'; // Reset to pending for re-approval
        $stmt->bind_param("iissssisdsi", $turf_id, $user_id, $booking_name, $date, $start_time, $end_time, $total_audience, $services_text, $total_cost, $status, $booking_id);
        if ($stmt->execute()) {
            // Update payment record
            $query = "UPDATE payments SET payment_status = 'pending' WHERE booking_id = ?";
            $stmt_payment = $conn->prepare($query);
            $stmt_payment->bind_param("i", $booking_id);
            $stmt_payment->execute();
            $stmt_payment->close();

            // Insert notification for organizer (users.id)
            $notification_message = "Your updated booking for {$turf['turf_name']} on $date is pending approval.";
            $query = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
            $stmt_notify = $conn->prepare($query);
            $stmt_notify->bind_param("is", $user_id, $notification_message);
            $stmt_notify->execute();
            $stmt_notify->close();

            // Notify turf owner (users.id)
            $owner_id = $turf['owner_id'];
            $owner_notify_message = "A booking for {$turf['turf_name']} on $date has been updated and is awaiting your approval.";
            $query = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
            $stmt_owner_notify = $conn->prepare($query);
            $stmt_owner_notify->bind_param("is", $owner_id, $owner_notify_message);
            $stmt_owner_notify->execute();
            $stmt_owner_notify->close();

            header("Location: team_organizer_dashboard.php");
            exit;
        } else {
            $errors[] = "Error updating booking: " . $stmt->error;
        }
        $stmt->close();
    }
}

$page_title = "Edit Booking - Cage Cricket";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/book_turf.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-10 col-xl-12 col-xxl-12 fade-in">
                <!-- Header Section -->
                <div class="header-section">
                    <div>
                        <h1>Edit Booking for <?php echo htmlspecialchars($turf['turf_name']); ?></h1>
                        <p>Update Your Booking Details</p>
                    </div>
                </div>

                <!-- Turf Info -->
                <div class="turf-info">
                    <div class="image-container">
                        <?php 
                        $turf_photo = file_exists($turf['turf_photo']) ? $turf['turf_photo'] : 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1400&q=80';
                        ?>
                        <img src="<?php echo htmlspecialchars($turf_photo); ?>" alt="<?php echo htmlspecialchars($turf['turf_name']); ?>" loading="lazy">
                        <div class="image-overlay"></div>
                    </div>
                    <div class="turf-details">
                        <h4><?php echo htmlspecialchars($turf['turf_name']); ?></h4>
                        <div class="details-list">
                            <div class="detail-item">
                                <span class="label">Location:</span>
                                <span class="value"><?php echo htmlspecialchars($turf['turf_address']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Area:</span>
                                <span class="value"><?php echo htmlspecialchars($turf['turf_area']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Cost:</span>
                                <span class="value">₹<?php echo number_format($turf['booking_cost'], 2); ?>/hr<?php if (isset($_POST['date']) && isWeekend($_POST['date'])) echo ' (+20% on weekends)'; ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Capacity:</span>
                                <span class="value"><?php echo htmlspecialchars($turf['turf_capacity']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Facilities Section -->
                <div class="facilities-section">
                    <h4>Facilities Available</h4>
                    <div class="facility-grid">
                        <div class="facility-item"><i class="bi bi-car-front-fill"></i> Parking Available</div>
                        <div class="facility-item"><i class="bi bi-house-door-fill"></i> Changing Rooms</div>
                        <div class="facility-item"><i class="bi bi-droplet-fill"></i> Drinking Water</div>
                        <div class="facility-item"><i class="bi bi-lightbulb-fill"></i> Floodlights</div>
                    </div>
                </div>

                <!-- Why Book Section -->
                <div class="why-book">
                    <h4>Why Book This Turf?</h4>
                    <div class="features-grid">
                        <div class="feature-card"><i class="bi bi-check-circle-fill"></i><span>State-of-the-art facilities for cricket enthusiasts</span></div>
                        <div class="feature-card"><i class="bi bi-geo-alt-fill"></i><span>Prime location in <?php echo htmlspecialchars($turf['turf_address']); ?></span></div>
                        <div class="feature-card"><i class="bi bi-clock-fill"></i><span>Flexible 1-4 hour booking options</span></div>
                        <div class="feature-card"><i class="bi bi-cup-straw"></i><span>Enhance your event with services like refreshments</span></div>
                    </div>
                </div>

                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="error-message">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Booking Form -->
                <div class="booking-card">
                    <div class="booking-form-container">
                        <h4><i class="bi bi-ticket-perforated"></i> Edit Your Booking</h4>
                        <div class="offers-section mb-3 fade-in-section">
                            <div class="offer-item">
                                <i class="bi bi-star-fill"></i>
                                <span>Book for 3+ hours on weekdays and get 10% OFF your total bill!</span>
                            </div>
                            <div class="offer-item">
                                <i class="bi bi-star-fill"></i>
                                <span>Play on weekends! 20% premium due to high demand.</span>
                            </div>
                        </div>
                        <form method="POST" id="booking-form" class="row g-3">
                            <input type="hidden" id="turf-id" value="<?php echo htmlspecialchars($turf['turf_id']); ?>">
                            <input type="hidden" id="booking-id" value="<?php echo htmlspecialchars($booking_id); ?>">

                            <!-- Event Details -->
                            <div class="col-12 fade-in-section">
                                <h5 class="section-title"><i class="bi bi-info-circle"></i> Event Details</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="bi bi-pencil-square"></i> Event Name <span class="required-asterisk">*</span></label>
                                        <input type="text" name="booking_name" value="<?php echo htmlspecialchars($_POST['booking_name'] ?? $booking['booking_name']); ?>" class="form-control" placeholder="e.g., Team Practice" title="Enter your event name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="bi bi-person-arms-up"></i> Total Audience <span class="required-asterisk">*</span></label>
                                        <input type="number" name="total_audience" id="total_audience" value="<?php echo htmlspecialchars($_POST['total_audience'] ?? $booking['total_audience']); ?>" class="form-control" placeholder="e.g., <?php echo min(5, $turf['turf_capacity']); ?>" min="1" max="<?php echo $turf['turf_capacity']; ?>" title="Number of attendees (max <?php echo $turf['turf_capacity']; ?>)" required>
                                        <small class="capacity-warning"><i class="bi bi-exclamation-triangle"></i> Audience count exceeds the turf capacity (<?php echo $turf['turf_capacity']; ?>).</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Booking Details -->
                            <div class="col-12 fade-in-section">
                                <h5 class="section-title"><i class="bi bi-calendar-event"></i> Booking Details</h5>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label"><i class="bi bi-calendar-date"></i> Date <span class="required-asterisk">*</span></label>
                                        <input type="text" name="date" id="date-picker" value="<?php echo htmlspecialchars($_POST['date'] ?? $booking['date']); ?>" class="form-control flatpickr-input" placeholder="Select date" title="Choose your booking date" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><i class="bi bi-clock-fill"></i> Start Time <span class="required-asterisk">*</span></label>
                                        <select name="start_time" id="start-time" class="form-select" title="Select the start time for your booking" required>
                                            <option value="">Select start time</option>
                                            <?php foreach ($start_times as $time): ?>
                                                <option value="<?php echo $time; ?>" <?php echo (isset($_POST['start_time']) && $_POST['start_time'] === $time) || (!isset($_POST['start_time']) && $booking['start_time'] === $time) ? 'selected' : ''; ?>>
                                                    <?php echo date('h:i A', strtotime($time)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="slot-warning"><i class="bi bi-exclamation-triangle"></i> This time slot is already booked or in the past.</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><i class="bi bi-stopwatch"></i> Duration <span class="required-asterisk">*</span></label>
                                        <select name="duration" id="duration" class="form-select" title="Select how long you need the turf" required>
                                            <option value="">Select duration</option>
                                            <?php foreach ($durations as $hour): ?>
                                                <?php
                                                $booking_duration = isset($_POST['duration']) ? (int)$_POST['duration'] : (strtotime($booking['end_time']) - strtotime($booking['start_time'])) / 3600;
                                                ?>
                                                <option value="<?php echo $hour; ?>" <?php echo (isset($_POST['duration']) && (int)$_POST['duration'] === $hour) || (!isset($_POST['duration']) && $booking_duration === $hour) ? 'selected' : ''; ?>>
                                                    <?php echo $hour; ?> Hour<?php echo $hour > 1 ? 's' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="discount-feedback"><i class="bi bi-check-circle"></i> Eligible for 10% discount!</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Live Availability -->
                            <div class="col-12 fade-in-section">
                                <h5 class="section-title"><i class="bi bi-grid"></i> Live Availability</h5>
                                <div class="live-availability-section">
                                    <div id="availability-legend" class="mb-2">
                                        <span class="legend-item"><span class="legend-color available"></span> Available: Free to book</span>
                                        <span class="legend-item"><span class="legend-color partial"></span> Pending: Another request is pending approval for this slot. You can still proceed, but approval depends on owner’s decision</span>
                                        <span class="legend-item"><span class="legend-color booked"></span> Booked: Unavailable</span>
                                    </div>
                                    <div id="availability-grid">
                                        <div class="loading-spinner"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Services -->
                            <div class="col-12 fade-in-section">
                                <h5 class="section-title"><i class="bi bi-gear"></i> Additional Services (Optional)</h5>
                                <div class="services-checkboxes">
                                    <?php foreach ($services_options as $key => $service): ?>
                                        <div class="form-check form-check-inline">
                                            <input type="checkbox" name="services[]" value="<?php echo $key; ?>" class="form-check-input" id="service_<?php echo $key; ?>" <?php echo in_array($key, $_POST['services'] ?? $selected_services) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="service_<?php echo $key; ?>">
                                                <?php echo htmlspecialchars($service['name']); ?> (₹<?php echo number_format($service['cost'], 2); ?>)
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Cost Breakdown -->
                            <div class="col-12 fade-in-section">
                                <h5 class="section-title"><i class="bi bi-currency-rupee"></i> Cost Breakdown</h5>
                                <div class="cost-breakdown-content">
                                    <p>Base Cost: <span>₹<span id="base-cost">0.00</span></span></p>
                                    <p>Services Cost: <span>₹<span id="services-cost">0.00</span></span></p>
                                    <p>Pricing Type: <span id="pricing-type" class="pricing-type-value">-</span></p>
                                    <p>Discount: <span>₹<span id="discount-amount">0.00</span></span></p>
                                    <p class="total">Total Cost: <span>₹<span id="total-cost">0.00</span></span></p>
                                </div>
                            </div>

                            <!-- Form Buttons -->
                            <div class="col-12 fade-in-section">
                                <div class="button-container">
                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#confirmModal">
                                        <i class="bi bi-check-circle-fill"></i> Update Booking
                                    </button>
                                    <a href="team_organizer_dashboard.php" class="btn btn-primary">
                                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Confirmation Modal -->
                <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="confirmModalLabel"><i class="bi bi-check-circle-fill"></i> Confirm Booking Update</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                Are you sure you want to update this booking for <?php echo htmlspecialchars($turf['turf_name']); ?>?
                                <div id="modal-cost-breakdown" class="mt-3">
                                    <p>Base Cost: <span>₹<span id="modal-base-cost">0.00</span></span></p>
                                    <p>Services Cost: <span>₹<span id="modal-services-cost">0.00</span></span></p>
                                    <p>Pricing Type: <span id="modal-pricing-type">-</span></p>
                                    <p>Discount: <span>₹<span id="modal-discount-amount">0.00</span></span></p>
                                    <p class="total">Total Cost: <span>₹<span id="modal-total-cost">0.00</span></span></p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                                <button type="button" class="btn btn-success" onclick="document.getElementById('booking-form').submit()"><i class="bi bi-check-circle-fill"></i> Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Pass PHP variables to JavaScript
        const maxCapacity = <?php echo json_encode($turf['turf_capacity']); ?>;
        const bookingCost = <?php echo json_encode($turf['booking_cost']); ?>;
        const servicesCosts = <?php echo json_encode($services_options); ?>;
        const availableTimes = <?php echo json_encode($start_times); ?>;
        const currentDate = <?php echo json_encode($current_date_str); ?>;
        const currentTime = <?php echo json_encode($current_time); ?>;
        const bookingId = <?php echo json_encode($booking_id); ?>;
    </script>
    <script src="js/book_turf.js"></script>
</body>
</html>

<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
?>