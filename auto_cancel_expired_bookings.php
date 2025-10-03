<?php
require 'config.php';

// Add these:
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Kolkata');

$now = time();

$sql = "SELECT id, date, start_time, created_at 
        FROM bookings 
        WHERE status = 'approved' AND payment_status = 'pending'";

$result = $conn->query($sql);

$cancelled = [];

while ($row = $result->fetch_assoc()) {
    $booking_id = $row['id'];
    $start_ts = strtotime($row['date'] . ' ' . $row['start_time']);
    $created_ts = strtotime($row['created_at']);

    // 24-hour deadline from approval or before start time (whichever is earlier)
    $deadline = ($start_ts - $created_ts < 86400) ? $start_ts : ($created_ts + 86400);

    if ($now > $deadline) {
        // Cancel the booking
        $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = 'Payment not completed before deadline' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();

        // Add notification (fetch organizer_id from bookings, then use users table)
        $notif = $conn->prepare("INSERT INTO notifications (user_id, message) 
                                 SELECT organizer_id, CONCAT('Your booking (ID: ', ?, ') was cancelled due to missed payment deadline.') 
                                 FROM bookings WHERE id = ?");
        $notif->bind_param("ii", $booking_id, $booking_id);
        $notif->execute();
        $notif->close();

        // Send email to organizer (direct join with users)
        $mail_query = $conn->prepare("SELECT u.email, u.name 
                                      FROM bookings b 
                                      JOIN users u ON b.organizer_id = u.id 
                                      WHERE b.id = ?");
        $mail_query->bind_param("i", $booking_id);
        $mail_query->execute();
        $mail_result = $mail_query->get_result()->fetch_assoc();
        $mail_query->close();

        if ($mail_result) {
            $organizer_email = $mail_result['email'];
            $organizer_name = $mail_result['name'];

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'somyasolanki82@gmail.com';
                $mail->Password = 'kpivfarktcpoebxr';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
                $mail->addAddress($organizer_email, $organizer_name);

                $mail->Subject = 'Booking Cancelled - Payment Deadline Missed';
                $mail->Body = "Dear $organizer_name,\n\nYour booking (ID: $booking_id) has been automatically cancelled as payment was not completed within the deadline.\n\nIf you'd like to rebook, please log in and create a new booking.\n\nRegards,\nCage Cricket Team";

                $mail->send();
            } catch (Exception $e) {
                error_log("Email error for booking $booking_id: " . $mail->ErrorInfo);
            }
        }

        $cancelled[] = $booking_id;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Auto-cancelled bookings: " . implode(', ', $cancelled);
}
?>
