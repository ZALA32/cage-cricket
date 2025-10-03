<?php
require 'config.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Get parameters
$date = $_POST['date'] ?? '';
$turf_id = (int)($_POST['turf_id'] ?? 0);

if (empty($date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date) || !$turf_id) {
    echo json_encode(['error' => 'Invalid date or turf ID']);
    exit;
}

// Validate turf ownership using users table (optional, for owner-specific actions)
// Example: If you want to check if the current user is the owner, uncomment below:
/*
session_start();
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'turf_owner') {
    $owner_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT owner_id FROM turfs WHERE turf_id = ?");
    $stmt->bind_param("i", $turf_id);
    $stmt->execute();
    $stmt->bind_result($db_owner_id);
    $stmt->fetch();
    $stmt->close();
    if ($db_owner_id !== $owner_id) {
        echo json_encode(['error' => 'Unauthorized access to turf availability']);
        exit;
    }
}
*/

// Generate 30-minute time slots from 6:00 AM to 11:00 PM
$slots = [];
$start_hour = 6;
$end_hour = 23;
$slot_duration = 30; // minutes

for ($hour = $start_hour; $hour < $end_hour; $hour++) {
    for ($min = 0; $min < 60; $min += $slot_duration) {
        $start_time = sprintf("%02d:%02d", $hour, $min);
        $end_time = date("H:i", strtotime("$start_time +{$slot_duration} minutes"));
        $slots[] = [
            'start' => $start_time,
            'end' => $end_time,
            'status' => 'available' // Default status
        ];
    }
}

// Fetch bookings for the given date and turf
$query = "SELECT b.start_time, b.end_time, b.status, u.name AS organizer_name, u.email AS organizer_email
          FROM bookings b
          JOIN users u ON b.organizer_id = u.id
          WHERE b.turf_id = ? AND b.date = ?
          AND b.status IN ('pending', 'approved', 'confirmed', 'cancelled', 'rejected')";
$stmt = $conn->prepare($query);
$stmt->bind_param("is", $turf_id, $date);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Update slot statuses based on bookings
foreach ($slots as &$slot) {
    $slot_start = new DateTime($slot['start']);
    $slot_end = new DateTime($slot['end']);

    foreach ($bookings as $booking) {
        $booking_start = new DateTime($booking['start_time']);
        $booking_end = new DateTime($booking['end_time']);

        // Check if slot overlaps with booking
        if ($slot_start < $booking_end && $slot_end > $booking_start) {
            if ($booking['status'] === 'approved' || $booking['status'] === 'confirmed') {
                $slot['status'] = 'booked';
                break; // Booked takes precedence
            } elseif ($booking['status'] === 'pending' && $slot['status'] !== 'booked') {
                $slot['status'] = 'partial';
            }
            // 'cancelled' or 'rejected' bookings do not change the 'available' status
        }
    }
}

echo json_encode(['slots' => $slots]);
exit;
?>
