<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_organizer') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Validate POST input
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['turf_id'], $_POST['booking_id'], $_POST['rating'])) {
    $turf_id = intval($_POST['turf_id']);
    $booking_id = intval($_POST['booking_id']);
    $rating = intval($_POST['rating']);
    $feedback = isset($_POST['feedback']) ? trim($_POST['feedback']) : null;

    // Ensure rating is between 1 and 5
    if ($rating < 1 || $rating > 5) {
        $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Invalid rating value!</div>";
        header("Location: view_booking.php?id=$booking_id");
        exit;
    }

    // Validate turf_id exists and owner is in users table
    $turfCheck = $conn->prepare("SELECT t.turf_id FROM turfs t JOIN users u ON t.owner_id = u.id WHERE t.turf_id = ?");
    $turfCheck->bind_param("i", $turf_id);
    $turfCheck->execute();
    if ($turfCheck->get_result()->num_rows === 0) {
        $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Invalid turf!</div>";
        header("Location: team_organizer_dashboard.php");
        exit;
    }
    $turfCheck->close();

    // Validate booking_id exists and is paid, organizer is in users table
    $bookingCheck = $conn->prepare("SELECT b.id FROM bookings b JOIN users u ON b.organizer_id = u.id WHERE b.id = ? AND b.organizer_id = ? AND b.payment_status = 'paid'");
    $bookingCheck->bind_param("ii", $booking_id, $user_id);
    $bookingCheck->execute();
    if ($bookingCheck->get_result()->num_rows === 0) {
        $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Invalid or unpaid booking!</div>";
        header("Location: team_organizer_dashboard.php");
        exit;
    }
    $bookingCheck->close();

    // Check if a rating already exists
    $checkStmt = $conn->prepare("SELECT id FROM turf_ratings WHERE turf_id = ? AND user_id = ? AND booking_id = ?");
    $checkStmt->bind_param("iii", $turf_id, $user_id, $booking_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $existing = $result->fetch_assoc();
    $checkStmt->close();

    try {
        if ($existing) {
            // Update the rating
            $updateStmt = $conn->prepare("UPDATE turf_ratings SET rating = ?, feedback = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStmt->bind_param("isi", $rating, $feedback, $existing['id']);
            $updateStmt->execute();
            $updateStmt->close();
            $_SESSION['dashboard_message'] = "<div class='alert alert-success text-center'>Your turf rating was updated successfully!</div>";
        } else {
            // Insert new rating
            $insertStmt = $conn->prepare("INSERT INTO turf_ratings (turf_id, user_id, booking_id, rating, feedback) VALUES (?, ?, ?, ?, ?)");
            $insertStmt->bind_param("iiiis", $turf_id, $user_id, $booking_id, $rating, $feedback);
            $insertStmt->execute();
            $insertStmt->close();
            $_SESSION['dashboard_message'] = "<div class='alert alert-success text-center'>Thank you for rating this turf!</div>";
        }
    } catch (Exception $e) {
        $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Error submitting rating: " . htmlspecialchars($e->getMessage()) . "</div>";
        header("Location: team_organizer_dashboard.php");
        exit;
    }

    // Redirect back to booking page
    header("Location: view_booking.php?id=$booking_id");
    exit;
} else {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger text-center'>Invalid form submission!</div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}
?>