<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $response = ['success' => false, 'message' => '', 'redirect' => ''];

    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = trim($_POST['password']);

    // Admin login check (now use users table for all roles)
    $user_query = "SELECT * FROM users WHERE email = '$email'";
    $user_result = mysqli_query($conn, $user_query);

    if ($user_result && mysqli_num_rows($user_result) === 1) {
        $user = mysqli_fetch_assoc($user_result);

        // ✅ Admin login
        if ($user['role'] === 'admin' && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['name'] = $user['name'];

            $response['success'] = true;
            $response['message'] = "Login successful! Redirecting to admin dashboard...";
            $response['redirect'] = "admin_dashboard.php";

        // ✅ Non-admin login
        } elseif (password_verify($password, $user['password'])) {
            if ($user['status'] !== 'verified') {
                $response['message'] = "Please verify your email before logging in.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];

                if ($user['role'] === 'turf_owner') {
                    $response['success'] = true;
                    $response['message'] = "Login successful! Redirecting to turf owner dashboard";
                    $response['redirect'] = "turf_owner_dashboard.php";
                } elseif ($user['role'] === 'team_organizer') {
                    $response['success'] = true;
                    $response['message'] = "Login successful! Redirecting to team organizer dashboard";
                    $response['redirect'] = "team_organizer_dashboard.php";
                } else {
                    $response['message'] = "Unknown user role.";
                }
            }

        } else {
            $response['message'] = "Invalid email or password.";
        }

    } else {
        $response['message'] = "Invalid email or password.";
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
ob_start();
?>

<!-- Animate.css & Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="css/login.css">

<!-- Login Section -->
<section class="login-section py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card shadow-lg animate__animated animate__fadeInUp">
                    <div class="card-body p-5">
                        <h2 class="text-center text-success fw-bold mb-4" style="font-size: 1.75rem;">🏏 Login to Cage Cricket</h2>

                        <!-- Alert if reset password success -->
                        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                            <div class="custom-alert custom-alert-success text-center fade show" id="resetAlert">
                                ✅ New password sent to your email. Please log in using it.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form id="loginForm">
                            <div class="mb-4 position-relative">
                                <input type="email" name="email" class="form-control form-control-lg" placeholder="Your Email" required>
                                <i class="bi bi-envelope position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
                            </div>
                            <div class="mb-4 position-relative">
                                <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Your Password" required>
                                <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3 text-muted toggle-password" data-target="password"></i>
                            </div>
                            <div class="mb-3 text-end">
                                <a href="forgot_password.php" class="text-muted small">Forgot Password?</a>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100 hover-pulse">Login Now</button>
                        </form>

                        <div id="alertContainer" class="my-4"></div>
                        <div class="text-center">
                            <p class="text-muted mb-0">Not registered yet? <a href="register.php" class="text-success fw-bold text-decoration-none hover-underline">Register here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="js/login.js"></script>

<?php
$page_content = ob_get_clean();
$page_title = "Login - Cage Cricket";
require 'template.php';
ob_end_flush();
?>
