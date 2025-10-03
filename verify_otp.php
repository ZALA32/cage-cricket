<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require 'config.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$page_title = "Verify OTP - Cage Cricket";

// Get user email and OTP expiry time
$email = $_SESSION['pending_email'] ?? '';
$otp_expiry_time = $_SESSION['otp_expiry'] ?? 0;
$remaining_time = max(0, $otp_expiry_time - time());
$_SESSION['resend_attempts'] = $_SESSION['resend_attempts'] ?? 0;

// Resend OTP
if (isset($_GET['resend']) && $_SESSION['resend_attempts'] < 3 && isset($_SESSION['pending_email'], $_SESSION['pending_otp'])) {
    $_SESSION['resend_attempts']++;
    $_SESSION['otp_expiry'] = time() + 120;

    $otp = $_SESSION['pending_otp'];
    $name = $_SESSION['pending_name'] ?? 'User';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'somyasolanki82@gmail.com'; // Use a valid email here
        $mail->Password = 'kpivfarktcpoebxr'; // Use an app-specific password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Resent OTP - Cage Cricket';
        $mail->Body = "Dear $name,\n\nYour new OTP is: $otp\n\nThis OTP is valid for 2 minutes.\n\n- Cage Cricket";
        $mail->send();
    } catch (Exception $e) {
        error_log("Resend OTP email failed: " . $mail->ErrorInfo);
    }
    header("Location: verify_otp.php");
    exit;
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];

    $entered_otp = trim($_POST['otp'] ?? '');
    $email_post = trim($_POST['email'] ?? '');

    if (
        isset($_SESSION['pending_otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email']) &&
        $email_post === $_SESSION['pending_email']
    ) {
        if (time() > $_SESSION['otp_expiry']) {
            $response['message'] = "OTP has expired. Please register again.";
        } elseif ($entered_otp === $_SESSION['pending_otp']) {
            // Update user status to 'verified' in users table
$update = "UPDATE users SET status = 'verified' WHERE email = ?";
            $stmt = $conn->prepare($update);
            $stmt->bind_param("s", $email_post);
            if ($stmt->execute()) {
                $stmt->close();
                session_unset();
                session_destroy();
                $response['success'] = true;
                $response['message'] = "Verification successful! Redirecting to login.";
                $response['redirect'] = "login.php";
            } else {
                $response['message'] = "Database error. Please try again.";
            }
        } else {
            $response['message'] = "Invalid OTP. Please try again.";
        }
    } else {
        $response['message'] = "Session expired or invalid request.";
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

ob_start();
?>

<!-- UI and Timer -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/verify_otp.css">

<section class="register-section py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="register-card shadow-lg animate__animated animate__fadeInUp">
                    <div class="card-body p-5">
                        <h2 class="text-center text-success fw-bold mb-4">🏏 Verify Your Email</h2>
                        <form id="verifyForm">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                            <div class="mb-4 position-relative">
                                <input type="text" name="otp" class="form-control form-control-lg" placeholder="Enter OTP" required>
                                <i class="bi bi-lock position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100 hover-pulse">Verify OTP</button>
                        </form>

                        <!-- OTP Info -->
                        <div class="otp-meta d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mt-4 p-3 rounded-3 text-center shadow-sm">
                            <div class="d-flex align-items-center gap-2 text-success fs-6 fw-semibold">
                                <i class="bi bi-clock-history fs-5"></i>
                                <span>OTP expires in: <span id="countdownText">--:--</span></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 fs-6 fw-semibold">
                                <?php if ($_SESSION['resend_attempts'] < 3): ?>
                                    <i class="bi bi-arrow-clockwise text-success"></i>
                                    <a href="verify_otp.php?resend=1" id="resendLink" class="text-success text-decoration-none hover-underline">Resend OTP</a>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-danger"></i>
                                    <span class="text-danger">Resend Disabled</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-2 text-dark fs-6 fw-semibold">
                                <i class="bi bi-activity"></i>
                                <span>Attempt <?= $_SESSION['resend_attempts'] + 1 ?> of 3</span>
                            </div>
                        </div>

                        <!-- Expired Message Options -->
                        <div id="resendExpiredBox" class="alert alert-danger text-center mt-4 fw-semibold d-none" style="font-size: 1rem;">
                            ⏰ OTP expired.
                            <?php if ($_SESSION['resend_attempts'] < 3): ?>
                                Please try again or <a href="verify_otp.php?resend=1" class="text-danger fw-bold text-decoration-underline">resend OTP</a>.
                            <?php endif; ?>
                        </div>

                        <div id="otpMaxedOutBox" class="alert alert-danger text-center mt-4 fw-semibold d-none" style="font-size: 1rem;">
                            ⛔ Maximum attempts reached.<br>
                            Please <a href="register.php" class="text-danger text-decoration-underline">register again</a>.
                        </div>

                        <!-- Alerts -->
                        <div id="alertContainer" class="my-4"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Inject session data -->
<script>
    window.remainingSeconds = <?= json_encode($remaining_time) ?>;
    window.resendAttempts = <?= json_encode($_SESSION['resend_attempts']) ?>;
</script>
<script src="js/verify_otp.js"></script>

<?php
$page_content = ob_get_clean();
include 'template.php';
?> 
