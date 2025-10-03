<?php
session_start();
require 'config.php';
require_once 'send_bill_email.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['team_organizer', 'admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
    $user_id = $_SESSION['user_id'];
    $method = 'cash';
    $updated_at = date('Y-m-d H:i:s');

    // Verify booking exists, belongs to the user, and is for a future date
    // Use users table for organizer validation
    $checkBooking = $conn->prepare("SELECT b.id, b.date 
                                    FROM bookings b 
                                    JOIN users u ON b.organizer_id = u.id 
                                    WHERE b.id = ? AND b.organizer_id = ? AND b.date >= CURDATE()");
    $checkBooking->bind_param("ii", $booking_id, $user_id);
    $checkBooking->execute();
    $booking = $checkBooking->get_result()->fetch_assoc();
    $checkBooking->close();

    if (!$booking && $_SESSION['role'] !== 'admin') {
        $_SESSION['dashboard_message'] = "⚠️ Invalid booking ID, unauthorized access, or booking date has passed.";
        header("Location: team_organizer_dashboard.php");
        exit;
    }

    // Update bookings table: Keep payment_status as pending for cash
    $updateBooking = $conn->prepare("UPDATE bookings SET payment_status = 'pending', status = 'confirmed' WHERE id = ?");
    $updateBooking->bind_param("i", $booking_id);
    $updateBooking->execute();
    $updateBooking->close();

    // Check if payment record exists
    $check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ?");
    $check->bind_param("i", $booking_id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        // Insert new payment: Mark as pending for cash
        $insertPayment = $conn->prepare("INSERT INTO payments (booking_id, payment_status, payment_method, updated_at) VALUES (?, 'pending', ?, ?)");
        $insertPayment->bind_param("iss", $booking_id, $method, $updated_at);
        $insertPayment->execute();
        $insertPayment->close();
    } else {
        // Update existing payment: Mark as pending for cash
        $updatePayment = $conn->prepare("UPDATE payments SET payment_status = 'pending', payment_method = ?, updated_at = ? WHERE booking_id = ?");
        $updatePayment->bind_param("ssi", $method, $updated_at, $booking_id);
        $updatePayment->execute();
        $updatePayment->close();
    }

    // Send bill email to organizer (uses users table for email/name)
    sendBillEmail($booking_id, $conn);

    $_SESSION['dashboard_message'] = "✅ Cash payment recorded as pending. Booking confirmed and bill emailed.";
    header("Location: team_organizer_dashboard.php");
    exit;
} else {
    $_SESSION['dashboard_message'] = "⚠️ Invalid access.";
    header("Location: team_organizer_dashboard.php");
    exit;
}
?>