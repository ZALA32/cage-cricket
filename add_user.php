<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Initialize variables for form data and errors
$errors = [];
$success = '';
$name = $email = $role = $status = '';
$roles = ['admin', 'turf_owner', 'team_organizer'];
$statuses = ['pending', 'verified'];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if (empty($role) || !in_array($role, $roles)) {
        $errors[] = "Please select a valid role.";
    }
    if (empty($status) || !in_array($status, $statuses)) {
        $errors[] = "Please select a valid status.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }

    // Check if email already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "Email already exists.";
        }
        $stmt->close();
    }

    // If no errors, insert the user
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Insert into users table only
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $name, $email, $hashed_password, $role, $status);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $stmt->close();

            $conn->commit();
            $success = "User added successfully.";
            $name = $email = $role = $status = '';
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Failed to add user: " . $e->getMessage();
            error_log("Add user failed: " . $e->getMessage());
        }
    }
}

// Main Content Buffer Start
ob_start();
?>

<!-- Add User -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Add New User</h1>
        <a href="manage_users.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back to Manage Users</a>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show text-center" role="alert">
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center" role="alert">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Add User Form -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">User Details</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role_option): ?>
                                <option value="<?= $role_option ?>" <?= $role === $role_option ? 'selected' : '' ?>>
                                    <?= ucfirst($role_option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="">Select Status</option>
                            <?php foreach ($statuses as $status_option): ?>
                                <option value="<?= $status_option ?>" <?= $status === $status_option ? 'selected' : '' ?>>
                                    <?= ucfirst($status_option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-success">Add User</button>
                    <a href="manage_users.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Custom Styles -->
<style>
    .form-control, .form-select {
        border: 2px solid #198754;
        border-radius: 8px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #145c38;
        box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
        outline: none;
    }
    .btn-success, .btn-outline-secondary {
        font-weight: 500;
        border-radius: 6px;
    }
    .btn-success:hover {
        background-color: #157347;
    }
    .btn-outline-secondary:hover {
        background-color: #6c757d;
        color: white;
    }
    .form-label {
        font-weight: 500;
    }
</style>

<!-- Auto-Dismiss Alerts -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert && alert.parentNode) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 4000);
        });
    });
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Add User - Admin Panel";
include 'admin_template.php';
?>