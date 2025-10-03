<?php
// ✅ generate_bill.php
require 'config.php';
session_start();

$page_title = "Turf Booking Bill";

if (!isset($_GET['booking_id'])) {
    die("Invalid request.");
}

$booking_id = intval($_GET['booking_id']);

// Fetch booking info (organizer from users table)
$stmt = $conn->prepare("SELECT b.*, t.turf_name, t.turf_address, u.name AS organizer_name, u.email AS organizer_email
                        FROM bookings b
                        JOIN turfs t ON b.turf_id = t.turf_id
                        JOIN users u ON b.organizer_id = u.id
                        WHERE b.id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    die("Booking not found.");
}

$bill_id = "CAGE-" . str_pad($booking_id, 5, "0", STR_PAD_LEFT);
$total = number_format($booking['total_cost'], 2);
// Calculate payment deadline
$booking_time = strtotime($booking['created_at']);
$booking_start = strtotime($booking['date'] . ' ' . $booking['start_time']);

if ($booking_start - $booking_time > 86400) {
    // Booking made more than 24 hours in advance
    $payment_deadline = $booking_time + 86400;
} else {
    // Booking made less than 24 hours before start
    $payment_deadline = $booking_start;
}
$payment_deadline_iso = date('Y-m-d\TH:i:s', $payment_deadline); // For JS


ob_start();
?>

<style>
    :root {
        --primary-color: #198754; /* Lighter green for hover */
        --secondary-color: #146c43; /* Dark green for buttons */
        --background-color: #e6f4ea;
        --section-background: #f0f8f2;
        --text-color: #333333;
        --error-color: #dc3545;
        --border-radius: 12px;
        --shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.3s ease;
        --accent-color: #28a745; /* Kept for consistency, not used for buttons */
    }

    body {
        background: var(--background-color);
        font-family: 'Poppins', sans-serif;
        color: var(--text-color);
    }

    .bill-box {
        background: #fff;
        padding: 3rem;
        margin: 2rem auto;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        position: relative;
        transition: var(--transition);
        border: 1px solid rgba(25, 135, 84, 0.2);
        animation: fadeIn 0.5s ease-in-out;
    }

    .bill-box:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .bill-box h2 {
        color: var(--secondary-color);
        font-weight: 700;
        font-size: 2rem;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .bill-box p {
        margin: 0.75rem 0;
        font-size: 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .bill-box p strong {
        color: var(--primary-color);
        font-weight: 600;
        flex: 0 0 40%;
    }

    .bill-box p span {
        color: var(--text-color);
        flex: 0 0 60%;
        text-align: right;
    }

    .bill-box p:last-child {
        border-bottom: none;
    }

    .bill-box hr {
        border: 0;
        border-top: 2px solid var(--primary-color);
        margin: 2rem 0;
    }

    .bill-box h5 {
        color: var(--secondary-color);
        font-weight: 600;
        font-size: 1.3rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-success, .btn-cash {
        background-color: var(--secondary-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
        border-radius: var(--border-radius);
        transition: var(--transition);
    }

    .btn-success:hover, .btn-cash:hover {
        background-color: var(--primary-color);
        transform: translateY(-2px);
    }

    .button-row {
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .button-row form {
        flex: 1;
    }

    .button-row button {
        width: 100%;
    }

    .fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }
    .alert-warning {
    background: linear-gradient(135deg, #e6f7ed, #d0f0db);  /* greenish theme */
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
        .bill-box {
            padding: 1.5rem;
        }

        .bill-box p {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .bill-box p strong,
        .bill-box p span {
            flex: none;
            text-align: left;
        }

        .button-row {
            flex-direction: column;
            gap: 0.5rem;
        }
    }
</style>

<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-10 col-xl-12 col-xxl-12">
        <!-- Bill Section -->
        <div id="bill-section" class="bill-box fade-in">
            <h2 class="text-center mb-4"><i class="bi bi-receipt me-2"></i>Cage Cricket - Turf Booking Bill</h2>
            <p><strong>Bill ID:</strong> <span><?= $bill_id ?></span></p>
            <p><strong>Organizer:</strong> <span><?= htmlspecialchars($booking['organizer_name']) ?> (<?= htmlspecialchars($booking['organizer_email']) ?>)</span></p>
            <p><strong>Turf:</strong> <span><?= htmlspecialchars($booking['turf_name']) ?></span></p>
            <p><strong>Address:</strong> <span><?= htmlspecialchars($booking['turf_address']) ?></span></p>
            <p><strong>Date & Time:</strong> <span><?= $booking['date'] ?> | <?= $booking['start_time'] ?> - <?= $booking['end_time'] ?></span></p>
            <p><strong>Total Cost:</strong> <span>₹<?= $total ?></span></p>
            <div class="alert alert-warning text-center fw-semibold fade-in mb-4" id="countdown-box">
                ⏳ <span id="countdown">Calculating...</span> left to complete your payment.
                <br>
                <small class="d-block mt-1 text-muted">To confirm your booking, please pay within the given time. If not paid, it may be auto-cancelled.</small>
            </div>

            <h5 class="text-success mb-3"><i class="bi bi-credit-card me-2"></i>Choose Payment Method</h5>
            <div class="button-row">
                <form method="POST" action="initiate_payment.php">
                    <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                    <button type="submit" class="btn btn-success"><i class="bi bi-credit-card-2-front me-2"></i>Pay Online (Razorpay)</button>
                </form>
                <form method="POST" action="mark_cash_paid.php">
                    <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                    <input type="hidden" name="payment_method" value="cash">
                    <button type="submit" class="btn btn-cash"><i class="bi bi-cash-stack me-2"></i>Pay offline (Cash)</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
require 'template.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const deadline = new Date("<?= $payment_deadline_iso ?>").getTime();
    const countdownElement = document.getElementById("countdown");

    function updateCountdown() {
        const now = new Date().getTime();
        const diff = deadline - now;

        if (diff <= 0) {
            countdownElement.innerHTML = "⛔ Payment window expired!";
            document.querySelectorAll("form button").forEach(btn => btn.disabled = true);
            countdownElement.parentElement.classList.remove("alert-warning");
            countdownElement.parentElement.classList.add("alert-danger");
        } else {
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            countdownElement.innerHTML = `${hours}h ${minutes}m ${seconds}s`;
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
});
</script>