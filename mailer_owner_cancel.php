<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/** Always fetch the latest payment row for a booking (your truth source). */
function cc_get_latest_payment(mysqli $conn, int $booking_id): ?array {
    $sql = "SELECT id, booking_id, payment_status, payment_method, transaction_id, created_at, updated_at
            FROM payments
            WHERE booking_id = ?
            ORDER BY id DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Send email to organizer after TURF OWNER cancels the booking.
 * Figures out refund text using the latest payment row.
 *
 * @param mysqli $conn
 * @param int    $booking_id
 * @param string $reason
 * @return bool
 */
function sendOwnerCancellationEmail(mysqli $conn, int $booking_id, string $reason): bool
{
    // Fetch booking + organizer + turf details (also fetch cancellation_reason to display exactly what was saved)
    $stmt = $conn->prepare("
        SELECT 
            b.id, b.booking_name, b.date, b.start_time, b.end_time, b.total_cost, 
            b.cancellation_reason,
            t.turf_name, t.turf_address,
            u.name  AS organizer_name,
            u.email AS organizer_email
        FROM bookings b
        JOIN turfs t ON b.turf_id = t.turf_id
        JOIN users u ON b.organizer_id = u.id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        error_log("sendOwnerCancellationEmail: Booking not found for id={$booking_id}");
        return false;
    }

    // Latest payment status after the cancel logic has run
    $payment = cc_get_latest_payment($conn, $booking_id);
    $method  = strtolower($payment['payment_method'] ?? '');
    $pstat   = strtolower($payment['payment_status'] ?? '');

    // Build refund text (matches your owner-cancel business rules):
    // - If pstat=refunded and method!=cash -> refunded to original method
    // - If method=cash -> no online refund (handle offline)
    // - If pending/none -> no payment captured yet
    $refundText = '';
    if ($pstat === 'refunded' && $method !== 'cash') {
        $label = $method ? ucfirst($method) : 'Original Method';
        $refundText = "A refund has been processed to your <strong>{$label}</strong> payment method.";
    } elseif ($pstat === 'completed' && $method === 'cash') {
        $refundText = "You paid in <strong>Cash</strong>; the venue will handle any adjustment or refund offline.";
    } elseif ($pstat === 'completed' && $method !== 'cash') {
        // In rare cases if payment stayed completed (shouldn’t if owner cancelled and we marked refunded),
        // give a softer message:
        $refundText = "Your payment was online. The refund is being processed to your original payment method.";
    } elseif ($pstat === 'pending') {
        $refundText = "No payment was captured yet.";
    }

    // Use the reason saved in DB if present (authoritative), else use the passed one
    $finalReason = trim((string)($booking['cancellation_reason'] ?? '')) ?: $reason;

    $bookingCode = "CAGE-" . str_pad($booking_id, 5, "0", STR_PAD_LEFT);
    $totalCost   = number_format((float)$booking['total_cost'], 2);

    // ---- Send Email ----
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'somyasolanki82@gmail.com';  // <-- your existing sender
        $mail->Password   = 'kpivfarktcpoebxr';          // <-- app password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
        $mail->addAddress($booking['organizer_email'], $booking['organizer_name']);
        $mail->isHTML(true);

        $mail->Subject = "[Cage Cricket] Booking Cancelled by Turf Owner - {$bookingCode}";
        // Simple, friendly HTML
        $mail->Body = <<<HTML
Hi {$booking['organizer_name']},<br><br>

We’re sorry — your booking has been <strong>cancelled by the turf owner</strong>.<br><br>

<strong>Booking:</strong> {$booking['booking_name']} ({$bookingCode})<br>
<strong>Turf:</strong> {$booking['turf_name']}<br>
<strong>Address:</strong> {$booking['turf_address']}<br>
<strong>Date &amp; Time:</strong> {$booking['date']} | {$booking['start_time']}–{$booking['end_time']}<br>
<strong>Total Cost:</strong> ₹{$totalCost}<br><br>

<strong>Reason from the turf owner:</strong><br>
{$finalReason}<br><br>

{$refundText}<br><br>

If you have any questions, reply to this email and we’ll help you out.<br><br>
Regards,<br>
Cage Cricket Team 🏏
HTML;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("sendOwnerCancellationEmail: Mail error => " . $mail->ErrorInfo);
        return false;
    }
}
