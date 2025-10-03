<?php
session_start();
require 'config.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set page title for template.php
$page_title = "Register - Cage Cricket";

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $response = ['success' => false, 'message' => ''];

    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));

    if (empty($name)) {
        $response['message'] = "Name cannot be empty.";
        echo json_encode($response);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = "Invalid email format.";
        echo json_encode($response);
        exit;
    }

    $contact_no = mysqli_real_escape_string($conn, trim($_POST['contact_no']));
    if (!preg_match('/^\d{10}$/', $contact_no)) {
        $response['message'] = "Invalid contact number. It must be 10 digits.";
        echo json_encode($response);
        exit;
    }

    // ✅ Confirm password check (new)
    if ($_POST['password'] !== $_POST['confirm_password']) {
        $response['message'] = "Passwords do not match.";
        echo json_encode($response);
        exit;
    }

    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $allowed_roles = ['turf_owner', 'team_organizer'];
    if (!in_array($role, $allowed_roles)) {
        $response['message'] = "Invalid role selected.";
        echo json_encode($response);
        exit;
    }

    $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['pending_otp'] = $otp;
    $_SESSION['pending_email'] = $email;
    $_SESSION['otp_expiry'] = time() + 120; // valid for 2 minutes
    $_SESSION['resend_attempts'] = 0; // Track OTP resend attempts
    $_SESSION['pending_name'] = $name; // ✅ store name for email
    error_log("Generated OTP: $otp"); // ✅ Log actual OTP

    if ($role === 'admin') {
        $response['message'] = "Admin registration is not allowed!";
    } else {
        if (mysqli_query($conn, "START TRANSACTION")) {
            $check_email = "SELECT * FROM users WHERE email = '$email'";
            $result = mysqli_query($conn, $check_email);

            if ($result && mysqli_num_rows($result) > 0) {
                $response['message'] = "Email already exists!";
                mysqli_query($conn, "ROLLBACK");
            } else {
                // Insert into users table
                $stmt_user = $conn->prepare("INSERT INTO users (name, email, contact_no, password, role, status) 
                    VALUES (?, ?, ?, ?, ?, 'pending')");
                $stmt_user->bind_param("sssss", $name, $email, $contact_no, $password, $role);

                if ($stmt_user->execute()) {
                    $user_id = $stmt_user->insert_id;
                    $stmt_user->close();

                    mysqli_query($conn, "COMMIT");

                    // Send OTP using PHPMailer
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'somyasolanki82@gmail.com';
                        $mail->Password = 'kpivfarktcpoebxr'; // App password
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;

                        $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
                        $mail->addAddress($email, $name);
                        $mail->Subject = 'OTP Verification - Cage Cricket';
                        $mail->Body = "Dear $name,\n\nYour OTP for verification is: $otp\n\nRegards,\nCage Cricket Team";

                        $mail->send();

                        $response['success'] = true;
                        $response['message'] = "Registration successful! Redirecting to OTP verification";
                        $response['redirect'] = "verify_otp.php?email=" . urlencode($email);
                    } catch (Exception $e) {
                        $response['message'] = "OTP email could not be sent. Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
                    }
                } else {
                    mysqli_query($conn, "ROLLBACK");
                    $response['message'] = "Registration failed. Please try again.";
                }
            }
        } else {
            $response['message'] = "Transaction error. Please try again.";
        }
    }

    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

ob_start();
?>

<!-- Animate.css for animations -->
<link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
<!-- Bootstrap Icons for eye icon -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="css/register.css">

<!-- Registration Section -->
<section class="register-section py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="register-card shadow-lg animate__animated animate__fadeInUp">
                    <div class="card-body p-5">
                        <h2 class="text-center text-success fw-bold mb-4">🏏Join Cage Cricket</h2>
                        <form id="registerForm">
                            <div class="mb-4 position-relative">
                                <input type="text" name="name" class="form-control form-control-lg" placeholder="Your Name" required>
                                <i class="bi bi-person position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <div class="mb-4 position-relative">
                                <input type="email" name="email" class="form-control form-control-lg" placeholder="Your Email" required>
                                <i class="bi bi-envelope position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <div class="mb-4 position-relative">
                                <input type="tel" name="contact_no" class="form-control form-control-lg" placeholder="Your Contact Number" required pattern="[0-9]{10}" maxlength="10" title="Enter a valid 10-digit number">
                                <i class="bi bi-telephone position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <div class="mb-4 position-relative">
                                <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Create Password" required>
                                <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3 text-muted toggle-password" data-target="password"></i>
                            </div>
                            <div class="mb-4 position-relative">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-lg" placeholder="Confirm Password" required>
                                <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3 text-muted toggle-password" data-target="confirm_password"></i>
                            </div>
                            <div class="mb-4">
                                <select name="role" class="form-select form-select-lg" required>
                                    <option value="" disabled selected>Select Your Role</option>
                                    <option value="turf_owner">Turf Owner</option>
                                    <option value="team_organizer">Team Organizer</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100 hover-pulse">Register Now</button>
                        </form>
                        <div id="alertContainer" class="my-4"></div>
                        <div class="text-center">
                            <p class="text-muted mb-0">Already registered? <a href="login.php" class="text-success fw-bold text-decoration-none hover-underline">Login here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="js/register.js"></script>

<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
?>
