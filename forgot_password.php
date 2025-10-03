<?php
session_start();
require 'config.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function generateRandomPassword($length = 10) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()'), 0, $length);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];

    $email = isset($_POST['email']) ? mysqli_real_escape_string($conn, $_POST['email']) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = "Invalid email format.";
    } else {
        // Only reference users table, no team_organizer/turf_owner
        $result = mysqli_query($conn, "SELECT id, name, email FROM users WHERE email='$email'");
        if ($result && mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);
            $newPassword = generateRandomPassword(10);
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            if (mysqli_query($conn, "UPDATE users SET password='$hashedPassword' WHERE email='$email'")) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'somyasolanki82@gmail.com';
                    $mail->Password = 'kpivfarktcpoebxr';
                    $mail->SMTPSecure = 'tls';
                    $mail->Port = 587;

                    $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket Support');
                    $mail->addAddress($user['email'], $user['name']);

                    $mail->isHTML(true);
                    $mail->Subject = 'Your New Password - Cage Cricket';
                    $mail->Body = "
                        <p>Hello " . htmlspecialchars($user['name']) . ",</p>
                        <p>Your password has been reset. Please use the following new password to log in:</p>
                        <p style='font-size:18px; font-weight:bold;'>$newPassword</p>
                        <p>After logging in, please change your password for security.</p>
                        <br>
                        <p>Regards,<br>Cage Cricket Support Team</p>
                    ";

                    $mail->send();

                    $response['success'] = true;
                    $response['message'] = "New password sent to your email! Redirecting to login";
                    $response['redirect'] = "login.php?reset=success";
                } catch (Exception $e) {
                    $response['message'] = "Mail error: " . htmlspecialchars($mail->ErrorInfo);
                }
            } else {
                $response['message'] = "Failed to reset password. Please try again.";
            }
        } else {
            $response['message'] = "Email not found!";
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

ob_start();
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/verify_otp.css">

<section class="register-section py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="register-card shadow-lg animate__animated animate__fadeInUp">
                    <div class="card-body p-5">
                        <h2 class="text-center text-success fw-bold mb-4">🔐 Forgot Password</h2>
                        <form id="forgotPasswordForm">
                            <div class="mb-4 position-relative">
                                <input type="email" name="email" class="form-control form-control-lg" placeholder="Enter your registered email" required>
                                <i class="bi bi-envelope position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100 hover-pulse">
                                <span id="submitSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
                                <span id="submitText">Send New Password</span>
                            </button>
                        </form>
                        <div id="alertContainer" class="my-4"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('forgotPasswordForm');
    const alertContainer = document.getElementById('alertContainer');
    const submitButton = form.querySelector('button[type="submit"]');
    const spinner = document.getElementById('submitSpinner');
    const submitText = document.getElementById('submitText');

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);

        submitButton.disabled = true;
        spinner.classList.remove('d-none');
        submitText.textContent = 'Sending';

        fetch('forgot_password.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Unexpected response: ' + text);
            }
            return response.json();
        })
        .then(data => {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${data.success ? 'custom-alert-success' : 'custom-alert-error'} fade show text-center`;
            alertDiv.innerHTML = `${data.message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);

            setTimeout(() => alertDiv.remove(), 4000);

            if (data.success && data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 4000);
            } else {
                submitButton.disabled = false;
                spinner.classList.add('d-none');
                submitText.textContent = 'Send New Password';
            }
        })
        .catch(error => {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'custom-alert custom-alert-error fade show text-center';
            errorDiv.innerHTML = `An unexpected error occurred: ${error.message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(errorDiv);
            setTimeout(() => errorDiv.remove(), 4000);
            submitButton.disabled = false;
            spinner.classList.add('d-none');
            submitText.textContent = 'Send New Password';
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
$page_title = "Forgot Password - Cage Cricket";
include 'template.php';
?>
