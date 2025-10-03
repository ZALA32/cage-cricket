<?php
session_start();
require 'config.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['pending_email']) || !isset($_SESSION['otp_expiry'])) {
    $response['message'] = "Session expired. Please register again.";
    echo json_encode($response);
    exit;
}

$email = $_SESSION['pending_email'];
$name = "";

// Fetch name from users table if not stored in session
$stmt = $conn->prepare("SELECT name FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user_result = $stmt->get_result();
if ($user_result && $user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
    $name = $user['name'];
}
$stmt->close();

// Check if previous OTP is still valid
if (time() < $_SESSION['otp_expiry']) {
    $response['message'] = "Please wait until the current OTP expires.";
    echo json_encode($response);
    exit;
}

// Generate new OTP
$new_otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
$_SESSION['pending_otp'] = $new_otp;
$_SESSION['otp_expiry'] = time() + 120;

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
    $mail->addAddress($email, $name);
    $mail->Subject = 'Resent OTP - Cage Cricket';
    $mail->Body = "Dear " . ($name ?: "user") . ",\n\nYour new OTP is: $new_otp\n\nValid for 2 minutes.\n\nRegards,\nCage Cricket Team";

    $mail->send();

    $response['success'] = true;
    $response['message'] = "New OTP sent successfully!";
} catch (Exception $e) {
    $response['message'] = "Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
}

header('Content-Type: application/json');
echo json_encode($response);
