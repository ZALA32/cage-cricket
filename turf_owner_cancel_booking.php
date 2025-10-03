<?php
session_start();
require 'config.php';
require_once 'mailer_owner_cancel.php'; // sends email with reason + refund info

date_default_timezone_set('Asia/Kolkata');

// Auth & CSRF
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'turf_owner') {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['dashboard_message'] = "Invalid request method.";
    header("Location: turf_owner_manage_bookings.php");
    exit;
}
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['dashboard_message'] = "⚠️ Invalid request (CSRF).";
    header("Location: turf_owner_manage_bookings.php");
    exit;
}

// Inputs
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
$reason     = trim($_POST['reason'] ?? '');

if ($booking_id <= 0) {
    $_SESSION['dashboard_message'] = "Invalid booking id.";
    header("Location: turf_owner_manage_bookings.php");
    exit;
}
if ($reason === '') {
    $_SESSION['dashboard_message'] = "Please provide a cancellation reason.";
    header("Location: turf_owner_manage_bookings.php");
    exit;
}
$reason = mb_substr($reason, 0, 1000);
$owner_id = (int)$_SESSION['user_id'];

try {
    // Load booking + turf owner check + latest payment
    $sql = "SELECT 
                b.id, b.turf_id, b.user_id AS organizer_id, b.booking_name, b.date, b.start_time, b.end_time, 
                b.status AS booking_status, b.total_cost,
                t.owner_id, t.turf_name,
                p.id AS payment_row_id, p.payment_status, p.payment_method, p.transaction_id
            FROM bookings b
            JOIN turfs t ON b.turf_id = t.turf_id
            LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE booking_id = b.id)
            WHERE b.id = ? AND t.owner_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $booking_id, $owner_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        $_SESSION['dashboard_message'] = "Booking not found or you do not own the turf.";
        header("Location: turf_owner_manage_bookings.php");
        exit;
    }

    // Prevent double-cancel / reject
    if (in_array($booking['booking_status'], ['cancelled', 'rejected'])) {
        $_SESSION['dashboard_message'] = "This booking is already {$booking['booking_status']}.";
        header("Location: turf_owner_manage_bookings.php");
        exit;
    }

    // Timing guard
    $nowTs    = time();
    $startTs  = strtotime($booking['date'].' '.$booking['start_time']);
    if ($nowTs >= $startTs) {
        $_SESSION['dashboard_message'] = "You can’t cancel after the match start time.";
        header("Location: turf_owner_manage_bookings.php");
        exit;
    }

    // Transaction: cancel booking, refund, notify
    $conn->begin_transaction();

    // 1) Cancel booking
    $upd = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = ? WHERE id = ?");
    $upd->bind_param("si", $reason, $booking_id);
    if (!$upd->execute()) throw new Exception("Failed to cancel booking.");
    $upd->close();

    // 2) Payments
    $refundPerformed = false;
    if (!empty($booking['payment_row_id']) && $booking['payment_status'] === 'completed') {
        if (strtolower($booking['payment_method'] ?? '') !== 'cash') {
            $updPay = $conn->prepare("UPDATE payments SET payment_status = 'refunded', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updPay->bind_param("i", $booking['payment_row_id']);
            if (!$updPay->execute()) throw new Exception("Failed to update payment as refunded.");
            $updPay->close();
            $refundPerformed = true;
        }
    }

    // 3) Notify organizer
    $msg = "Your booking for {$booking['booking_name']} on {$booking['date']} has been cancelled by the turf owner. Reason: {$reason}";
    $insN = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, CURRENT_TIMESTAMP)");
    $insN->bind_param("is", $booking['organizer_id'], $msg);
    if (!$insN->execute()) throw new Exception("Failed to create notification.");
    $insN->close();

    $conn->commit();

    // 4) Email
    $sent = sendOwnerCancellationEmail($conn, $booking_id, $reason);
    if (!$sent) error_log("Owner cancellation email failed for booking {$booking_id}");

    // Success message
    if ($refundPerformed) {
        $_SESSION['dashboard_message'] = "✅ Booking cancelled and payment marked as refunded.";
    } elseif (!empty($booking['payment_row_id']) && $booking['payment_status'] === 'completed' && strtolower($booking['payment_method'] ?? '') === 'cash') {
        $_SESSION['dashboard_message'] = "✅ Booking cancelled. Payment was cash; please handle refund/adjustment offline.";
    } else {
        $_SESSION['dashboard_message'] = "✅ Booking cancelled.";
    }

} catch (Exception $e) {
    if ($conn->errno || $conn->error) $conn->rollback();
    $_SESSION['dashboard_message'] = "❌ " . $e->getMessage();
}

header("Location: turf_owner_manage_bookings.php");
exit;
