<?php
session_start();
require 'config.php';

date_default_timezone_set('Asia/Kolkata'); // important for time comparisons

// ---- Basic auth ----
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner'])) {
    error_log("Unauthorized access attempt: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// ---- Method + CSRF guard ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['action'], $_POST['booking_id'], $_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $response['message'] = "Invalid request!";
    $_SESSION['success_message'] = $response['message'];
    header("Location: turf_owner_dashboard.php");
    exit;
}

$booking_id = (int)$_POST['booking_id'];
$action     = $_POST['action'] === 'approve' ? 'approve' : 'reject';

// ---- Verify booking belongs to owner and is pending ----
$verify_stmt = $conn->prepare("
    SELECT b.id, b.turf_id, b.organizer_id, b.date, b.start_time, b.end_time, b.status,
           t.turf_name
    FROM bookings b
    JOIN turfs t ON b.turf_id = t.turf_id
    WHERE b.id = ? AND t.owner_id = ? AND b.status = 'pending'
");
$verify_stmt->bind_param("ii", $booking_id, $user_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    $verify_stmt->close();
    $response['message'] = "Invalid booking or not authorized to update!";
    $_SESSION['success_message'] = $response['message'];
    header("Location: turf_owner_dashboard.php");
    exit;
}

$booking = $verify_result->fetch_assoc();
$verify_stmt->close();

// ---- HARD STOP: no approve/reject after start time ----
$startTs = strtotime($booking['date'] . ' ' . $booking['start_time']);
$nowTs   = time();
if ($nowTs >= $startTs) {
    $response['message'] = "Cannot " . $action . " this booking. The match start time has already passed.";
    $_SESSION['success_message'] = $response['message'];
    header("Location: turf_owner_dashboard.php");
    exit;
}

// ---------------------------------------------------------------
// APPROVE
// ---------------------------------------------------------------
if ($action === 'approve') {
    // Conflict check
    $conflict_stmt = $conn->prepare("
        SELECT id FROM bookings 
        WHERE turf_id = ? 
          AND date = ? 
          AND status = 'approved' 
          AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time <  ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
              )
        LIMIT 1
    ");
    $conflict_stmt->bind_param(
        "isssssss",
        $booking['turf_id'],
        $booking['date'],
        $booking['start_time'],
        $booking['start_time'],
        $booking['end_time'],
        $booking['end_time'],
        $booking['start_time'],
        $booking['end_time']
    );
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();

    if ($conflict_result->num_rows > 0) {
        $conflict_stmt->close();
        $response['message'] = "Cannot approve this booking. The turf is already booked for an overlapping time slot.";
        $_SESSION['success_message'] = $response['message'];
        header("Location: turf_owner_dashboard.php");
        exit;
    }
    $conflict_stmt->close();

    // Approve booking
    $new_status = 'approved';
    $update_stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
    $update_stmt->bind_param("si", $new_status, $booking_id);

    if ($update_stmt->execute()) {
        $update_stmt->close();

        // Notify organizer
        $notification_message = "Your booking for " . htmlspecialchars($booking['turf_name']) . " on " . $booking['date'] . " has been approved.";
        $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        $notify_stmt->bind_param("is", $booking['organizer_id'], $notification_message);
        $notify_stmt->execute();
        $notify_stmt->close();

        // Send approval email (users table only)
        $email_stmt = $conn->prepare("
            SELECT name, email 
            FROM users
            WHERE id = ?
        ");
        $email_stmt->bind_param("i", $booking['organizer_id']);
        $email_stmt->execute();
        $user_result = $email_stmt->get_result();

        if ($user_result->num_rows > 0) {
            $user = $user_result->fetch_assoc();
            $recipient_email = $user['email'];
            $recipient_name  = $user['name'];

            $bookingDateTime = strtotime($booking['date'] . ' ' . $booking['start_time']);
            $approvalTime    = time();
            $timeUntilStart  = $bookingDateTime - $approvalTime;
            $deadlineMessage = $timeUntilStart < 86400
                ? "Please complete payment before your booking starts."
                : "Please complete payment within 24 hours of approval.";

            require_once 'PHPMailer/PHPMailer.php';
            require_once 'PHPMailer/SMTP.php';
            require_once 'PHPMailer/Exception.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'somyasolanki82@gmail.com';
                $mail->Password = 'kpivfarktcpoebxr';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
                $mail->addAddress($recipient_email, $recipient_name);
                $mail->Subject = 'Booking Approved - Cage Cricket';
                $mail->Body = "Dear $recipient_name,\n\nYour booking for '" . $booking['turf_name'] . "' on " . $booking['date'] . " has been approved.\n\n$deadlineMessage\n\nThank you,\nCage Cricket Team";
                $mail->send();
            } catch (Exception $e) {
                error_log("PHPMailer approval email error: " . $mail->ErrorInfo);
            }
        }
        $email_stmt->close();

        // Auto-reject conflicting pending bookings
        $auto_reject_stmt = $conn->prepare("
            UPDATE bookings 
               SET status = 'rejected', cancellation_reason = 'Conflict with an approved booking'
             WHERE turf_id = ? 
               AND date = ? 
               AND status = 'pending' 
               AND id != ? 
               AND (
                    (start_time <= ? AND end_time > ?) OR
                    (start_time <  ? AND end_time >= ?) OR
                    (start_time >= ? AND end_time <= ?)
               )
        ");
        $auto_reject_stmt->bind_param(
            "isissssss",
            $booking['turf_id'],
            $booking['date'],
            $booking['id'],
            $booking['start_time'],
            $booking['start_time'],
            $booking['end_time'],
            $booking['end_time'],
            $booking['start_time'],
            $booking['end_time']
        );
        $auto_reject_stmt->execute();
        $rejected_count = $auto_reject_stmt->affected_rows;
        $auto_reject_stmt->close();

        if ($rejected_count > 0) {
            $rejected_stmt = $conn->prepare("
                SELECT DISTINCT organizer_id FROM bookings 
                WHERE turf_id = ? AND date = ? AND status = 'rejected'
                  AND (
                        (start_time <= ? AND end_time > ?) OR
                        (start_time <  ? AND end_time >= ?) OR
                        (start_time >= ? AND end_time <= ?)
                      )
            ");
            $rejected_stmt->bind_param(
                "isssssss",
                $booking['turf_id'],
                $booking['date'],
                $booking['start_time'],
                $booking['start_time'],
                $booking['end_time'],
                $booking['end_time'],
                $booking['start_time'],
                $booking['end_time']
            );
            $rejected_stmt->execute();
            $rejected_result = $rejected_stmt->get_result();

            require_once 'PHPMailer/PHPMailer.php';
            require_once 'PHPMailer/SMTP.php';
            require_once 'PHPMailer/Exception.php';

            $rejection_note = "Your booking for " . htmlspecialchars($booking['turf_name']) . " on " . $booking['date'] . " was automatically rejected due to a conflict with an approved booking.";

            while ($row = $rejected_result->fetch_assoc()) {
                $organizer_id = (int)$row['organizer_id'];
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notify_stmt->bind_param("is", $organizer_id, $rejection_note);
                $notify_stmt->execute();
                $notify_stmt->close();

                $email_stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
                $email_stmt->bind_param("i", $organizer_id);
                $email_stmt->execute();
                $user_result = $email_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user = $user_result->fetch_assoc();
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'somyasolanki82@gmail.com';
                        $mail->Password = 'kpivfarktcpoebxr';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;

                        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
                        $mail->addAddress($user['email'], $user['name']);
                        $mail->Subject = 'Booking Rejected - Cage Cricket';
                        $mail->Body = "Hi " . $user['name'] . ",\n\n" . $rejection_note . "\n\nPlease try booking another slot.\n\nThanks,\nCage Cricket Team";
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("PHPMailer auto-reject error: " . $mail->ErrorInfo);
                    }
                }
                $email_stmt->close();
            }
            $rejected_stmt->close();
        }

        $response['success'] = true;
        $response['message'] = "Booking has been approved successfully!";
    } else {
        $err = $update_stmt->error;
        $update_stmt->close();
        $response['message'] = "Error updating booking status: " . $err;
    }

    $_SESSION['success_message'] = $response['message'];
    header("Location: turf_owner_dashboard.php");
    exit;
}

// ---------------------------------------------------------------
// REJECT
// ---------------------------------------------------------------
$new_status = 'rejected';
$update_stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
$update_stmt->bind_param("si", $new_status, $booking_id);

if ($update_stmt->execute()) {
    $update_stmt->close();

    $notification_message = "Your booking for " . htmlspecialchars($booking['turf_name']) . " on " . $booking['date'] . " has been rejected.";
    $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
    $notify_stmt->bind_param("is", $booking['organizer_id'], $notification_message);
    $notify_stmt->execute();
    $notify_stmt->close();

    // Send rejection email (users table only)
    $email_stmt = $conn->prepare("
        SELECT name, email 
        FROM users 
        WHERE id = ?
    ");
    $email_stmt->bind_param("i", $booking['organizer_id']);
    $email_stmt->execute();
    $user_result = $email_stmt->get_result();
    if ($user_result->num_rows > 0) {
        $user = $user_result->fetch_assoc();
        require_once 'PHPMailer/PHPMailer.php';
        require_once 'PHPMailer/SMTP.php';
        require_once 'PHPMailer/Exception.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'somyasolanki82@gmail.com';
            $mail->Password = 'kpivfarktcpoebxr';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
            $mail->addAddress($user['email'], $user['name']);
            $mail->Subject = 'Booking Rejected - Cage Cricket';
            $mail->Body = "Dear " . $user['name'] . ",\n\nWe regret to inform you that your booking for '" . $booking['turf_name'] . "' on " . $booking['date'] . " has been manually rejected by the turf owner.\n\nPlease try booking another slot.\n\nRegards,\nCage Cricket Team";
            $mail->send();
        } catch (Exception $e) {
            error_log("PHPMailer manual rejection error: " . $mail->ErrorInfo);
        }
    }
    $email_stmt->close();

    $response['success'] = true;
    $response['message'] = "Booking has been rejected successfully!";
} else {
    $err = $update_stmt->error;
    $update_stmt->close();
    $response['message'] = "Error updating booking status: " . $err;
}

$_SESSION['success_message'] = $response['message'];
header("Location: turf_owner_dashboard.php");
exit;
