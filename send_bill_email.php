<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoload
require_once 'config.php'; // DB connection

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Always fetch the latest payment row for a booking. */
function getLatestPayment(mysqli $conn, int $booking_id): ?array {
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

/** Build a readable line about payment for PDFs/emails. */
function formatPaymentStatusForDisplay(?array $payment, ?string $booking_payment_status): string {
    if ($payment) {
        $ps = strtolower($payment['payment_status'] ?? '');
        $method = strtolower($payment['payment_method'] ?? '');
        if ($ps === 'completed') {
            return ($method === 'cash')
                ? "Cash to be paid at the venue on the day of play"
                : "Paid (" . ucfirst($method ?: 'Online') . ")";
        }
        if ($ps === 'refunded') {
            return "Refunded (" . ucfirst($method ?: 'Online') . ")";
        }
        // pending or unknown
        return ($method === 'cash') ? "To be paid at the venue on the day of play" : "Pending";
    }
    return ucfirst($booking_payment_status ?? 'pending');
}

function sendBillEmail($booking_id, $conn) {
    // Fetch booking basics
    $stmt = $conn->prepare("
        SELECT 
            b.id, b.turf_id, b.organizer_id, b.booking_name, b.date, b.start_time, b.end_time,
            b.total_audience, b.services, b.total_cost, b.status, b.created_at, b.payment_status, b.cancellation_reason,
            t.turf_name, t.turf_address,
            u.name AS organizer_name, u.email AS organizer_email
        FROM bookings b
        JOIN turfs t ON b.turf_id = t.turf_id
        JOIN users u ON b.organizer_id = u.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        error_log("❌ No booking found for ID: $booking_id");
        return false;
    }

    $payment = getLatestPayment($conn, (int)$booking['id']);
    $bill_id = "CAGE-" . str_pad($booking_id, 5, "0", STR_PAD_LEFT);
    $total   = number_format((float)$booking['total_cost'], 2);
    $paymentStatusForPdf = formatPaymentStatusForDisplay($payment, $booking['payment_status']);
    $method = strtolower($payment['payment_method'] ?? '');

    // ----- PDF HTML -----
    ob_start(); ?>
    <html>
    <head>
        <style>
            body { font-family: 'DejaVu Sans', sans-serif; background: #e6f4ea; padding: 2rem; }
            h2 { color: #198754; }
            .payment-status { color: #28a745; font-weight: bold; }
            .note { color: #dc3545; }
        </style>
    </head>
    <body>
        <h2>Cage Cricket - Turf Booking Bill</h2>
        <p><strong>Bill ID:</strong> <?= htmlspecialchars($bill_id) ?></p>
        <p><strong>Organizer:</strong> <?= htmlspecialchars($booking['organizer_name']) ?> (<?= htmlspecialchars($booking['organizer_email']) ?>)</p>
        <p><strong>Turf:</strong> <?= htmlspecialchars($booking['turf_name']) ?></p>
        <p><strong>Address:</strong> <?= htmlspecialchars($booking['turf_address']) ?></p>
        <p><strong>Date &amp; Time:</strong> <?= htmlspecialchars($booking['date']) ?> | <?= htmlspecialchars($booking['start_time']) ?> - <?= htmlspecialchars($booking['end_time']) ?></p>
        <p><strong>Total Cost:</strong> ₹<?= htmlspecialchars($total) ?></p>
        <p><strong>Payment Status:</strong> <span class="payment-status"><?= htmlspecialchars($paymentStatusForPdf) ?></span></p>
        <?php if ($method === 'cash' && (!isset($payment['payment_status']) || strtolower($payment['payment_status']) !== 'refunded')) { ?>
            <p class="note">Please bring the payment in cash to the venue on the day of play.</p>
        <?php } ?>
        <br>
        <p style="color: #28a745">Thank you for booking with Cage Cricket!</p>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    // Dompdf
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isUnicodeEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfOutput = $dompdf->output();
    if (!is_dir(__DIR__ . '/bills')) { @mkdir(__DIR__ . '/bills', 0775, true); }
    $pdfPath = __DIR__ . "/bills/{$bill_id}.pdf";
    file_put_contents($pdfPath, $pdfOutput);

    // Email with bill
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'somyasolanki82@gmail.com';
        $mail->Password = 'kpivfarktcpoebxr'; // app password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
        $mail->addAddress($booking['organizer_email'], $booking['organizer_name']);
        $mail->isHTML(true);
        $mail->Subject = "[Cage Cricket] Bill for your Turf Booking - {$bill_id}";

        $detailsLines = [
            "<strong>Turf:</strong> " . htmlspecialchars($booking['turf_name']),
            "<strong>Date &amp; Time:</strong> " . htmlspecialchars($booking['date']) . " | " . htmlspecialchars($booking['start_time']) . " - " . htmlspecialchars($booking['end_time']),
            "<strong>Total Cost:</strong> ₹" . htmlspecialchars($total),
            "<strong>Payment Status:</strong> " . htmlspecialchars($paymentStatusForPdf),
        ];
        $mail->Body = "Hi " . htmlspecialchars($booking['organizer_name']) . ",<br><br>"
            . "Thank you for your booking with Cage Cricket! Please find attached the bill for your turf booking.<br><br>"
            . "<strong>Booking Details:</strong><br>- " . implode("<br>- ", $detailsLines) . "<br><br>";

        if ($method === 'cash') {
            $mail->Body .= "Please bring the payment in cash to the venue on the day of play.<br><br>";
        }
        $mail->Body .= "Regards,<br>Cage Cricket Team 🏏";

        $mail->addAttachment($pdfPath);
        $mail->send();
        @unlink($pdfPath);
        return true;
    } catch (Exception $e) {
        error_log("❌ Email send error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send cancellation email.
 * IMPORTANT: This version stays compatible with your existing cancel_booking.php
 * call: sendCancellationEmail($booking_id, $conn, $is_refunded ? $booking['total_cost'] : 0)
 *
 * @param int   $booking_id
 * @param mysqli $conn
 * @param mixed $refund_amount_or_flag  If > 0 => eligible refund of that amount, else => not eligible.
 */
function sendCancellationEmail($booking_id, $conn, $refund_amount_or_flag = 0) {
    // Booking basics
    $stmt = $conn->prepare("
        SELECT b.id, b.total_cost, b.date, b.start_time, b.end_time,
               t.turf_name, t.turf_address,
               u.name AS organizer_name, u.email AS organizer_email
        FROM bookings b
        JOIN turfs t ON b.turf_id = t.turf_id
        JOIN users u ON b.organizer_id = u.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$booking) return false;

    $booking_code = "CAGE-" . str_pad($booking_id, 5, "0", STR_PAD_LEFT);

    // Latest payment row (truth source for method/refunded)
    $payment = getLatestPayment($conn, (int)$booking['id']);
    $method  = strtolower($payment['payment_method'] ?? '');
    $pstatus = strtolower($payment['payment_status'] ?? '');

    // Interpret the 3rd param exactly as you intended in cancel_booking.php
    $eligible_refund = (is_numeric($refund_amount_or_flag) && (float)$refund_amount_or_flag > 0);
    $refund_amount   = $eligible_refund ? (float)$refund_amount_or_flag : 0.0;

    // Build refund text (priority order):
    // 1) If already refunded in DB -> say already refunded.
    // 2) Else if eligible_refund (from your page logic) -> promise refund to original method.
    // 3) Else -> no refund (deadline passed or cash).
    if ($pstatus === 'refunded') {
        $label = $method ? ucfirst($method) : 'Original Method';
        $refund_text = "Your payment has already been <strong>refunded</strong>" .
                       ($method ? " to your <strong>{$label}</strong>" : "") . ".";
    } elseif ($eligible_refund) {
        if ($method === 'cash') {
            $refund_text = "You paid in <strong>Cash</strong>, so no refund is required.";
        } else {
            $refund_text = "A refund of <strong>₹" . number_format($refund_amount, 2) . "</strong> will be processed to your original payment method within 2–3 business days.";
        }
    } else {
        if ($method === 'cash') {
            $refund_text = "You paid in <strong>Cash</strong>, so no refund is required.";
        } else {
            $refund_text = "Refund deadline has passed. You can cancel, but no refund will be issued.";
        }
    }

    // Send email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'somyasolanki82@gmail.com';
        $mail->Password = 'kpivfarktcpoebxr';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
        $mail->addAddress($booking['organizer_email'], $booking['organizer_name']);
        $mail->isHTML(true);

        $mail->Subject = "[Cage Cricket] Booking Cancelled - {$booking_code}";
        $mail->Body = <<<EOD
Hi {$booking['organizer_name']},<br><br>
Your booking for "<strong>{$booking['turf_name']}</strong>" on <strong>{$booking['date']}</strong> from <strong>{$booking['start_time']} to {$booking['end_time']}</strong> has been successfully <strong>cancelled</strong>.<br><br>
{$refund_text}<br><br>
If you have any questions or concerns, feel free to reach out.<br><br>
Regards,<br>
Cage Cricket Team 🏏
EOD;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("❌ Email send error: " . $mail->ErrorInfo);
        return false;
    }
}
