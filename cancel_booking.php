<?php
session_start();
require_once 'config.php';
require_once 'send_bill_email.php';
date_default_timezone_set('Asia/Kolkata');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch booking details including payment method and turf owner info from users table
$booking_query = $conn->prepare("
    SELECT b.*, p.payment_status, p.payment_method, t.turf_name, t.turf_email, t.turf_contact, t.owner_id,
           u_owner.name AS owner_name, u_owner.email AS owner_email
    FROM bookings b
    LEFT JOIN payments p ON b.id = p.booking_id
    LEFT JOIN turfs t ON b.turf_id = t.turf_id
    LEFT JOIN users u_owner ON t.owner_id = u_owner.id
    WHERE b.id = ? AND b.organizer_id = ?
");
$booking_query->bind_param("ii", $booking_id, $user_id);
$booking_query->execute();
$result = $booking_query->get_result();
$booking = $result->fetch_assoc();
$booking_query->close();

if (!$booking || !in_array($booking['status'], ['approved', 'confirmed'])) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Invalid booking or not authorized!</div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

// Check refund eligibility (exclude cash payments and check time)
$current_time = new DateTime();
$booking_time = new DateTime($booking['date'] . ' ' . $booking['start_time']);
$is_cancel_disabled = (new DateTime()) > $booking_time;
$interval = $current_time->diff($booking_time);
$hours_until_booking = ($interval->days * 24) + $interval->h;
$cancel_deadline = clone $booking_time;
$cancel_deadline->modify('-24 hours'); // Deadline is 24 hours before match start
$cancel_deadline_ts = $cancel_deadline->getTimestamp();
$now_ts = time();
$remaining_seconds = max(0, $cancel_deadline_ts - $now_ts);

$is_refunded = $booking['payment_status'] === 'completed' &&
               $hours_until_booking >= 24 &&
               $booking['payment_method'] !== 'cash';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');
    $conn->begin_transaction();
    try {
        // Update booking status to cancelled
        $update_booking = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = ? WHERE id = ?");
        $update_booking->bind_param("si", $reason, $booking_id);
        $update_booking->execute();
        $update_booking->close();

        // Update payment status to refunded if eligible (non-cash payment)
        if ($is_refunded) {
            // Verify payment record exists
            $payment_check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ?");
            $payment_check->bind_param("i", $booking_id);
            $payment_check->execute();
            $payment_result = $payment_check->get_result();
            if ($payment_result->num_rows === 0) {
                $conn->rollback();
                $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>No payment record found for this booking!</div>";
                header("Location: team_organizer_dashboard.php");
                exit;
            }
            $payment_check->close();

            $update_payment = $conn->prepare("UPDATE payments SET payment_status = 'refunded', updated_at = NOW() WHERE booking_id = ?");
            $update_payment->bind_param("i", $booking_id);
            $update_payment->execute();
            $update_payment->close();
        }

        // Send cancellation email (uses users table for organizer and owner info)
        require_once 'send_bill_email.php';
        sendCancellationEmail($booking_id, $conn, $is_refunded ? $booking['total_cost'] : 0);

        // Add notification for organizer (users.id)
        $notif_msg = "Your booking for {$booking['turf_name']} on {$booking['date']} has been cancelled.";
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        $notif_stmt->bind_param("is", $user_id, $notif_msg);
        $notif_stmt->execute();
        $notif_stmt->close();

        // Add notification for turf owner (users.id)
        $owner_id = $booking['owner_id'];
        if ($owner_id) {
            $owner_notif_msg = "Booking for {$booking['turf_name']} on {$booking['date']} has been cancelled by the organizer.";
            $owner_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $owner_notif_stmt->bind_param("is", $owner_id, $owner_notif_msg);
            $owner_notif_stmt->execute();
            $owner_notif_stmt->close();
        }

        $conn->commit();
        $_SESSION['dashboard_message'] = "<div class='alert alert-success text-center'>Booking cancelled successfully." . ($is_refunded ? " Refund of ₹" . number_format($booking['total_cost'], 2) . " will be processed within 5-7 business days." : "") . "</div>";
        header("Location: team_organizer_dashboard.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error cancelling booking: " . $conn->error . " | Exception: " . $e->getMessage();
        error_log($error_message);
        $error = "<div class='alert alert-danger text-center'>$error_message</div>";
    }
}

// Set page title and content for template
$page_title = "Cancel Booking";
ob_start();
?>

<style>
    :root {
        --primary-color: #198754;
        --secondary-color: #146c43;
        --background-color: #e6f4ea;
        --section-background: #f0f8f2;
        --text-color: #333333;
        --error-color: #dc3545;
        --border-radius: 12px;
        --shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.3s ease;
        --accent-color: #28a745;
    }

    body {
        background: var(--background-color);
        font-family: 'Poppins', sans-serif;
        color: var(--text-color);
    }

    .cancel-box {
        width: 100%;
        background: #fff;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        animation: fadeIn 0.5s ease-in-out;
    }

    .cancel-header {
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 2.5rem;
        text-align: center;
        border-bottom: 4px solid #fff;
    }

    .cancel-header h2 {
        margin: 0;
        font-weight: 600;
        font-size: 2rem;
    }

    .cancel-body {
        padding: 3rem;
    }

    .terms-box {
        border-left: 5px solid var(--primary-color);
        background: var(--section-background);
        padding: 1.5rem;
        border-radius: var(--border-radius);
        margin-bottom: 2rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .terms-box h4 {
        color: var(--secondary-color);
        margin-bottom: 1rem;
    }

    .terms-box ul {
        margin: 0;
        padding-left: 1.5rem;
    }

    .terms-box li {
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        line-height: 1.6;
    }

    .card-details {
        background: #fff;
        border-radius: var(--border-radius);
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        position: relative;
        transition: var(--transition);
        border: 1px solid rgba(25, 135, 84, 0.2);
    }

    .card-details:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .card-details h5 {
        color: var(--secondary-color);
        font-weight: 700;
        font-size: 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-details p {
        margin: 0.75rem 0;
        font-size: 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .card-details p strong {
        color: var(--primary-color);
        font-weight: 600;
        flex: 0 0 40%;
    }

    .card-details p span {
        color: var(--text-color);
        flex: 0 0 60%;
        text-align: right;
    }

    .card-details p:last-child {
        border-bottom: none;
    }

    .card-details .refund-eligible {
        color: var(--accent-color);
        font-weight: 600;
    }

    .card-details .refund-not-eligible {
        color: var(--error-color);
        font-weight: 600;
    }

    .form-label {
        color: var(--primary-color);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    textarea.form-control {
        resize: vertical;
        min-height: 120px;
        border: 2px solid var(--primary-color);
        border-radius: var(--border-radius);
        padding: 0.75rem;
        transition: var(--transition);
    }

    textarea.form-control:focus {
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
    }

    .btn-cancel-confirm {
        background-color: var(--error-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
        border-radius: var(--border-radius);
        transition: var(--transition);
    }

    .btn-cancel-confirm:hover {
        background-color: #b02a37;
        transform: translateY(-2px);
    }

    .btn-dark-green {
        background-color: var(--secondary-color);
        color: white;
        border: 2px solid var(--secondary-color);
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
        border-radius: var(--border-radius);
        transition: var(--transition);
    }

    .btn-dark-green:hover {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        transform: translateY(-2px);
    }

    .fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }
    .alert-green {
    background: linear-gradient(135deg, #e6f7ed, #d0f0db);
    color: #1b4d3e;
    border-left: 5px solid var(--primary-color);
    border-radius: var(--border-radius);
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    font-size: 1rem;
    padding: 1rem 1.25rem;
}
.alert-danger {
    background-color: #f8d7da;
    color: #842029;
    border-left: 5px solid var(--error-color);
    border-radius: var(--border-radius);
    padding: 1rem 1.25rem;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    font-size: 1rem;
}


    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 576px) {
        .card-details p {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .card-details p strong,
        .card-details p span {
            flex: none;
            text-align: left;
        }
    }
</style>

<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-10 col-xl-12 col-xxl-12">
        <div class="cancel-box fade-in">
            <div class="cancel-header">
                <h2><i class="bi bi-x-circle-fill me-2"></i>Cancel Your Booking</h2>
            </div>
            <div class="cancel-body">
                <?php if (isset($error)) echo $error; ?>

                <!-- Cancellation Policy -->
                <div class="terms-box fade-in">
                    <h4 class="fw-bold"><i class="bi bi-info-circle me-2"></i>Cancellation Policy</h4>
                    <ul>
                    <li><strong>Cancellation Window:</strong> Bookings can be cancelled anytime before the match begins. However, to be eligible for a refund, cancellation must occur at least 24 hours in advance.</li>
                    <li><strong>Refund Eligibility:</strong> Refund is only available if you cancel at least 24 hours before the match and your payment was made online (non-cash).</li>
                    <li><strong>Non-Refundable Payments:</strong> Cash payments are non-refundable but can still be cancelled before match start.</li>
                    <li><strong>Refund Timeline:</strong> Refunds (if eligible) will be processed to your original payment method within 5–7 business days.</li>
                    </ul>
                </div>

                <!-- Booking Details -->
                <div class="card-details fade-in">
                    <h5 class="fw-bold mb-3"><i class="bi bi-calendar-check me-2"></i>Booking Details</h5>
                    <p><strong>Turf Name:</strong> <span><?php echo htmlspecialchars($booking['turf_name']); ?></span></p>
                    <p><strong>Turf Contact:</strong> <span><?php echo htmlspecialchars($booking['turf_contact']); ?></span></p>
                    <p><strong>Turf Email:</strong> <span><?php echo htmlspecialchars($booking['turf_email']); ?></span></p>
                    <p><strong>Date:</strong> <span><?php echo htmlspecialchars($booking['date']); ?></span></p>
                    <p><strong>Time:</strong> <span><?php echo htmlspecialchars($booking['start_time']) . ' - ' . htmlspecialchars($booking['end_time']); ?></span></p>
                    <p><strong>Payment Method:</strong> <span><?php echo htmlspecialchars($booking['payment_method'] ?? 'None'); ?></span></p>
                    <p><strong>Refund Eligibility:</strong> <span class="<?php echo $is_refunded ? 'refund-eligible' : 'refund-not-eligible'; ?>">
                        <?php echo $is_refunded ? 'Eligible (₹' . number_format($booking['total_cost'], 2) . ')' : 'Not Eligible'; ?>
                    </span></p>
                </div>

                <!-- Cancellation Form -->
                 <!-- Countdown Timer -->
                  <div id="cancelCountdown" class="alert alert-green text-center fw-bold mb-4"></div>
                  <div class="text-muted small mt-2 text-center">
                        Refund window closes at: <strong><?php echo date('d M Y, h:i A', $cancel_deadline_ts); ?></strong>
                    </div>

                  <?php if ($is_cancel_disabled): ?>
                    <div class="alert alert-danger text-center fw-bold mb-4">
                        ❌ Cancellation not allowed. The match has already started.
                    </div>
                <?php endif; ?>
                <form action="" method="POST" class="fade-in" id="cancel-form">

                    <div class="mb-4">
                        <label for="reason" class="form-label"><i class="bi bi-chat-text me-2"></i>Reason for Cancellation</label>
<textarea class="form-control" id="reason" name="reason" rows="5" required placeholder="Please provide a reason for cancelling your booking" <?php echo $is_cancel_disabled ? 'disabled' : ''; ?> autofocus></textarea>

                    </div>
                    <div class="d-flex justify-content-between gap-3">
                       <button type="button" class="btn btn-cancel-confirm w-50" id="openCancelModal" <?php echo $is_cancel_disabled ? 'disabled' : ''; ?>>
                            <i class="bi bi-x-circle-fill me-2"></i>Confirm Cancellation
                        </button>
                        <a href="team_organizer_dashboard.php" class="btn btn-dark-green w-50"><i class="bi bi-arrow-left-circle me-2"></i>Back to Dashboard</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Cancel Confirmation Modal -->
<div class="modal fade" id="cancelConfirmationModal" tabindex="-1" aria-labelledby="cancelConfirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="cancelConfirmationModalLabel">
          <i class="bi bi-exclamation-octagon-fill me-2"></i>Confirm Cancellation
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <p class="text-muted">Are you sure you want to cancel this booking?<br>This action cannot be undone.</p>
        <div class="d-flex justify-content-center gap-3 mt-4">
          <button type="submit" form="cancel-form" class="btn btn-cancel-confirm" data-bs-toggle="tooltip" title="Submit cancellation request">
            <i class="bi bi-x-circle-fill me-2"></i>Yes, Cancel Booking
            </button>
          <button type="button" class="btn btn-dark-green" data-bs-dismiss="modal">
            <i class="bi bi-arrow-left-circle me-2"></i>No, Go Back
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$page_content = ob_get_clean();
require_once 'template.php';
?>
<script>
  const remainingSeconds = <?php echo $remaining_seconds; ?>;

function updateCountdown() {
    const countdownEl = document.getElementById("cancelCountdown");
    if (!countdownEl) return;

    let secondsLeft = remainingSeconds;

    function renderCountdown() {
        if (secondsLeft <= 0) {
            countdownEl.innerHTML = "⚠️ Refund deadline is over. You can still cancel before match time, but no refund will be given.";
            countdownEl.classList.remove("alert-green");
            countdownEl.classList.add("alert-danger");
            return false; // stop rendering
        }

        const hrs = Math.floor(secondsLeft / 3600);
        const mins = Math.floor((secondsLeft % 3600) / 60);
        const secs = secondsLeft % 60;

        countdownEl.innerHTML = `⏳ Time left to cancel with refund: ${hrs}h ${mins}m ${secs}s`;
        return true;
    }

    // First render immediately
    renderCountdown();

    const interval = setInterval(() => {
        secondsLeft--;
        if (!renderCountdown()) {
            clearInterval(interval);
        }
    }, 1000);
}

updateCountdown();

document.addEventListener('DOMContentLoaded', () => {
    const modalTrigger = document.getElementById('openCancelModal');
    if (modalTrigger && !modalTrigger.disabled) {
        modalTrigger.addEventListener('click', () => {
            const cancelModal = new bootstrap.Modal(document.getElementById('cancelConfirmationModal'));
            cancelModal.show();
        });
    }

    // Enable tooltips globally (Bootstrap 5)
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
});


</script>