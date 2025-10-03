<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_organizer') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id']; // users.id
$message = '';

if (!isset($_GET['id'])) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>No booking ID provided!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

$booking_id = intval($_GET['id']);

// Fetch booking details using the updated schema (from `users` instead of `team_organizer`)
$stmt = $conn->prepare("
    SELECT b.*, t.turf_name, t.turf_address, t.turf_photo, t.turf_capacity, t.booking_cost,
           p.payment_status AS payment_status, p.payment_method, p.transaction_id, p.updated_at
    FROM bookings b
    JOIN turfs t ON b.turf_id = t.turf_id
    LEFT JOIN payments p ON b.id = p.booking_id
    JOIN users u ON b.organizer_id = u.id
    WHERE b.id = ? AND u.id = ?
");
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$booking_result = $stmt->get_result();

if ($booking_result->num_rows === 0) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Booking not found or you do not have access to this booking!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

$booking = $booking_result->fetch_assoc();
$stmt->close();

// Check if rating exists (only if payment completed)
$rating_exists = false;
$existing_rating = null;
$existing_feedback = null;

if ($booking['payment_status'] === 'completed') {
    $ratingStmt = $conn->prepare("SELECT rating, feedback FROM turf_ratings WHERE turf_id = ? AND user_id = ? AND booking_id = ?");
    $ratingStmt->bind_param("iii", $booking['turf_id'], $user_id, $booking_id);
    $ratingStmt->execute();
    $result = $ratingStmt->get_result();
    if ($result->num_rows > 0) {
        $rating_exists = true;
        $row = $result->fetch_assoc();
        $existing_rating = $row['rating'];
        $existing_feedback = $row['feedback'];
    }
    $ratingStmt->close();
}

// Add emoji and color logic for booking status
$booking_status = strtolower($booking['status']);
$booking_status_emoji = '';
$booking_status_class = '';
switch ($booking_status) {
    case 'approved':
    case 'confirmed':
        $booking_status_emoji = '✅';
        $booking_status_class = 'status-approved';
        break;
    case 'pending':
        $booking_status_emoji = '⏳';
        $booking_status_class = 'status-pending';
        break;
    case 'cancelled':
        $booking_status_emoji = '❌';
        $booking_status_class = 'status-cancelled';
        break;
    case 'rejected':
        $booking_status_emoji = '🚫';
        $booking_status_class = 'status-rejected';
        break;
    default:
        $booking_status_emoji = '';
        $booking_status_class = '';
}

// Add emoji and color logic for payment status
$payment_status = $booking['payment_status'];
$payment_status_emoji = '';
$payment_status_class = '';
$payment_status_text = '';
switch ($payment_status) {
    case 'completed':
        $payment_status_emoji = '✅';
        $payment_status_class = 'payment-paid';
        $payment_status_text = 'Paid';
        break;
    case 'pending':
        $payment_status_emoji = '⏳';
        $payment_status_class = 'payment-pending';
        $payment_status_text = 'Pending';
        break;
    case 'refunded':
        $payment_status_emoji = '💸';
        $payment_status_class = 'payment-refunded';
        $payment_status_text = 'Refunded';
        break;
    default:
        $payment_status_emoji = '❌';
        $payment_status_class = 'payment-pending';
        $payment_status_text = 'Pending';
}

$page_title = "Booking Summary - Cage Cricket";
ob_start();
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">

<!-- (Your CSS unchanged — keeping all the styles you wrote) -->

<div class="row justify-content-center m-0">
    <div class="col-12 p-0">
        <div class="summary-box">
            <div class="summary-header">
                <h2><i class="bi bi-list-check"></i> Booking Summary #<?php echo htmlspecialchars($booking['id']); ?></h2>
            </div>
            <div class="summary-body">

                <!-- Turf Section -->
                <div class="card mb-3 slide-in">
                    <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-bootstrap"></i> Turf Details</h3>
                        <hr>
                        <div class="turf-photo-container">
                            <?php if ($booking['turf_photo']): ?>
                                <img src="<?php echo htmlspecialchars($booking['turf_photo']); ?>" alt="Photo of <?php echo htmlspecialchars($booking['turf_name']); ?>" class="turf-photo">
                            <?php else: ?>
                                <div class="rounded shadow-sm bg-light d-flex align-items-center justify-content-center text-muted" style="width: 400px; height: 250px;">No Image</div>
                            <?php endif; ?>
                        </div>
                        <p><strong>Name:</strong> <span><?php echo htmlspecialchars($booking['turf_name']); ?></span></p>
                        <p><strong>Location:</strong> <span><?php echo htmlspecialchars($booking['turf_address']); ?></span></p>
                        <p><strong>Capacity:</strong> <span><?php echo htmlspecialchars($booking['turf_capacity']); ?> People</span></p>
                        <p><strong>Hourly Rate:</strong> <span>₹<?php echo number_format($booking['booking_cost'], 2); ?></span></p>
                    </div>
                </div>

                <!-- Booking Info -->
                <div class="card mb-3 slide-in">
                    <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-calendar-event"></i> Booking Information</h3>
                        <hr>
                        <p><strong>Event:</strong> <span><?php echo htmlspecialchars($booking['booking_name']); ?></span></p>
                        <p><strong>Date:</strong> <span><?php echo htmlspecialchars($booking['date']); ?></span></p>
                        <p><strong>Time:</strong> <span><?php echo htmlspecialchars($booking['start_time'] . " to " . $booking['end_time']); ?></span></p>
                        <p><strong>Expected Audience:</strong> <span><?php echo htmlspecialchars($booking['total_audience']); ?></span></p>
                        <p><strong>Extra Services:</strong> <span><?php echo htmlspecialchars($booking['services'] ?? 'None'); ?></span></p>
                        <p><strong>Total Cost:</strong> <span>₹<?php echo number_format($booking['total_cost'], 2); ?></span></p>
                        <p><strong>Status:</strong> <span class="status <?php echo $booking_status_class; ?>"><?php echo $booking_status_emoji . ' ' . ucfirst($booking['status']); ?></span></p>
                        <p><strong>Booked On:</strong> <span><?php echo date('d M Y, h:i A', strtotime($booking['created_at'])); ?></span></p>
                    </div>
                </div>

                <!-- Payment Info -->
                <div class="card mb-3 slide-in">
                    <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-credit-card"></i> Payment Details</h3>
                        <hr>
                        <p><strong>Payment Status:</strong> <span class="payment-status <?php echo $payment_status_class; ?>"><?php echo $payment_status_emoji . ' ' . $payment_status_text; ?></span></p>
                        <?php if ($booking['payment_status'] === 'completed'): ?>
                            <p><strong>Method:</strong> <span><?php echo htmlspecialchars($booking['payment_method'] ?? 'Cash'); ?></span></p>
                            <p><strong>Transaction ID:</strong> <span><?php echo htmlspecialchars($booking['transaction_id'] ?? 'Offline Payment'); ?></span></p>
                            <p><strong>Paid On:</strong> <span><?php echo date('d M Y, h:i A', strtotime($booking['updated_at'] ?? $booking['created_at'])); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Turf Rating -->
                <?php if ($booking['payment_status'] === 'completed'): ?>
                    <div class="card mb-3 slide-in">
                        <div class="card-body">
                            <h3 class="card-title"><i class="bi bi-star-fill"></i> Turf Rating</h3>
                            <hr>
                            <?php if ($rating_exists): ?>
                                <p><strong>Your Rating:</strong> <span><?php echo str_repeat("⭐", $existing_rating) . " (" . $existing_rating . "/5)"; ?></span></p>
                                <?php if (!empty($existing_feedback)): ?>
                                    <p><strong>Your Feedback:</strong> <span><?php echo htmlspecialchars($existing_feedback); ?></span></p>
                                <?php endif; ?>
                                <a href="#" class="btn btn-option btn-outline-secondary disabled"><i class="bi bi-star-fill"></i> Already Rated</a>
                            <?php else: ?>
                                <form action="submit_rating.php" method="POST" class="d-flex flex-column align-items-start gap-3">
                                    <input type="hidden" name="turf_id" value="<?php echo htmlspecialchars($booking['turf_id']); ?>">
                                    <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($booking['id']); ?>">
                                    <label for="rating" class="fw-bold">Your Rating (1 to 5):</label>
                                    <select name="rating" id="rating" class="form-select" style="max-width: 200px;" required>
                                        <option value="" selected disabled>Select</option>
                                        <option value="1">⭐ 1 - Poor</option>
                                        <option value="2">⭐ 2 - Fair</option>
                                        <option value="3">⭐ 3 - Good</option>
                                        <option value="4">⭐ 4 - Very Good</option>
                                        <option value="5">⭐ 5 - Excellent</option>
                                    </select>
                                    <label for="feedback" class="fw-bold mt-3">Your Feedback:</label>
                                    <textarea name="feedback" id="feedback" rows="4" class="form-control" style="max-width: 600px;" placeholder="Write your thoughts (optional)..."></textarea>
                                    <button type="submit" class="btn btn-rate"><i class="bi bi-star-fill"></i> Submit Rating</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            if (alert && alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 8000);
    });
});
</script>

<?php
$page_content = ob_get_clean();
require 'template.php';
?>
