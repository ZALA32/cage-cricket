<?php
require 'config.php';
require 'razorpay_config.php';
require 'vendor/autoload.php'; // Razorpay PHP SDK
require_once 'send_bill_email.php'; // ✅ Added for emailing bill
session_start();

use Razorpay\Api\Api;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_organizer') {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['booking_id']) || empty($_GET['razorpay_payment_id'])) {
    error_log("Invalid payment request: booking_id=" . ($_GET['booking_id'] ?? 'missing') . ", razorpay_payment_id=" . ($_GET['razorpay_payment_id'] ?? 'missing'));
    $_SESSION['payment_message'] = "<div class='error'>⚠️ Invalid payment request.</div>";
    header("Location: team_organizer_dashboard.php");
    exit();
}

$booking_id = intval($_GET['booking_id']);
$razorpay_payment_id = $_GET['razorpay_payment_id'];
$organizer_id = $_SESSION['user_id'];

// Verify booking (organizer from users table)
$stmt = $conn->prepare("SELECT b.id, b.total_cost, b.payment_status 
                        FROM bookings b 
                        JOIN users u ON b.organizer_id = u.id
                        WHERE b.id = ? AND b.organizer_id = ? AND b.status = 'approved' AND b.payment_status = 'pending'");
$stmt->bind_param("ii", $booking_id, $organizer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    error_log("Booking validation failed: booking_id=$booking_id, organizer_id=$organizer_id");
    $_SESSION['payment_message'] = "<div class='error'>⚠️ Invalid or already paid booking.</div>";
    header("Location: team_organizer_dashboard.php");
    exit();
}
$booking = $result->fetch_assoc();
$stmt->close();

// Initialize Razorpay API
$api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

try {
    $payment = $api->payment->fetch($razorpay_payment_id);

    error_log("Payment details: id=$razorpay_payment_id, status={$payment->status}, amount=" . ($payment->amount / 100) . ", booking_cost={$booking['total_cost']}");

    if (in_array($payment->status, ['captured', 'authorized']) && abs(($payment->amount / 100) - $booking['total_cost']) < 0.01) {
        $conn->begin_transaction();
        try {
            // Update payments table
            $payment_method = $payment->method;
            $updated_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed', payment_method = ?, transaction_id = ?, updated_at = ? 
                                    WHERE booking_id = ?");
            $stmt->bind_param("sssi", $payment_method, $razorpay_payment_id, $updated_at, $booking_id);
            $stmt->execute();
            $stmt->close();

            // Update bookings table
            $stmt = $conn->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed' WHERE id = ?");
            $stmt->bind_param("i", $booking_id);
            $stmt->execute();
            $stmt->close();

            // Insert notification (users table)
            $notification_message = "Payment for booking ID $booking_id has been completed successfully.";
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $stmt->bind_param("is", $organizer_id, $notification_message);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            // ✅ Email the bill as PDF after successful payment
            sendBillEmail($booking_id, $conn);

            $_SESSION['payment_message'] = "<div class='success'>✅ Payment successful and booking confirmed! The bill has been emailed to you.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Database update error: " . $e->getMessage());
            $_SESSION['payment_message'] = "<div class='error'>⚠️ Error processing payment: Database update failed.</div>";
        }
    } else {
        error_log("Verification failed: status={$payment->status}, payment_amount=" . ($payment->amount / 100) . ", booking_amount={$booking['total_cost']}");
        $_SESSION['payment_message'] = "<div class='error'>⚠️ Payment verification failed: Invalid status or amount mismatch.</div>";
    }
} catch (Exception $e) {
    error_log("Razorpay API error: " . $e->getMessage());
    $_SESSION['payment_message'] = "<div class='error'>⚠️ Error verifying payment: API failure.</div>";
}

header("Location: team_organizer_dashboard.php");
exit();
?>
