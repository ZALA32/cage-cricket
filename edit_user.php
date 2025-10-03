<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_users.php?error=Invalid user ID");
    exit;
}

$user_id = (int)$_GET['id'];

// Fetch user details
$stmt = $conn->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: manage_users.php?error=User not found");
    exit;
}

// Initialize variables for form data and errors
$errors = [];
$success = '';
$name = $user['name'];
$email = $user['email'];
$role = $user['role'];
$status = $user['status'];
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
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }

    // Check if email already exists (excluding the current user)
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "Email already exists.";
        }
        $stmt->close();
    }

    // Check dependencies if role is changing
    if (empty($errors) && $role !== $user['role']) {
        if ($user['role'] === 'turf_owner') {
            // Check if user owns any turfs
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM turfs WHERE owner_id = ?");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            if ($result['count'] > 0) {
                $errors[] = "Cannot change role: User has " . $result['count'] . " turf(s) associated.";
            }
            $stmt->close();
        } elseif ($user['role'] === 'team_organizer') {
            // Check if user has bookings
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE organizer_id = ?");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            if ($result['count'] > 0) {
                $errors[] = "Cannot change role: User has " . $result['count'] . " booking(s) associated.";
            }
            $stmt->close();

        }
    }

    // If no errors, update the user
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Update users table
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, status = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $name, $email, $hashed_password, $role, $status, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $role, $status, $user_id);
            }
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = "User updated successfully.";
            // Update form variables to reflect changes
            $user['name'] = $name;
            $user['email'] = $email;
            $user['role'] = $role;
            $user['status'] = $status;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Failed to update user: " . $e->getMessage();
            error_log("Update user failed: " . $e->getMessage());
        }
    }
}

// Main Content Buffer Start
ob_start();
?>

<!-- Edit User -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Edit User</h1>
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

    <!-- Edit User Form -->
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
                        <label for="password" class="form-label">New Password <span class="text-muted">(Optional)</span></label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Leave blank to keep unchanged">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-success">Update User</button>
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
$page_title = "Edit User - Admin Panel";
include 'admin_template.php';
?>