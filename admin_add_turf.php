<?php
session_start();
require 'config.php';

// Restrict to admins or turf_owners
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'turf_owner'])) {
    error_log("Unauthorized access attempt: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Initialize form variables
$turf_name = '';
$turf_contact = '';
$turf_email = '';
$turf_area = '';
$booking_cost = '';
$turf_capacity = '';
$turf_address = '';
$turf_facility = '';
$turf_description = '';
$owner_id = $role == 'turf_owner' ? $user_id : 0;
$is_active = 1; // Default to active
$file_uploaded = false;

$message = '';
$errors = [];

// Fetch Turf Owners for admin (from users table)
$owners = [];
if ($role == 'admin') {
    $result = $conn->query("SELECT id, name, email FROM users WHERE role = 'turf_owner' ORDER BY name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $owners[] = $row;
        }
    } else {
        error_log("Query failed: " . $conn->error);
        $errors[] = "Unable to fetch turf owners.";
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $turf_name = trim($_POST['turf_name'] ?? '');
    $turf_contact = trim($_POST['turf_contact'] ?? '');
    $turf_email = trim($_POST['turf_email'] ?? '');
    $turf_area = trim($_POST['turf_area'] ?? '');
    $booking_cost = floatval($_POST['booking_cost'] ?? 0.0);
    $turf_capacity = intval($_POST['turf_capacity'] ?? 0);
    $turf_address = trim($_POST['turf_address'] ?? '');
    $turf_facility = trim($_POST['turf_facility'] ?? '');
    $turf_description = trim($_POST['turf_description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($role == 'admin') {
        $owner_id = intval($_POST['owner_id'] ?? 0);
    }

    // Validate Inputs
    if ($role == 'admin' && $owner_id <= 0) {
        $errors[] = "Please select a valid turf owner.";
    }
    if (empty($turf_name)) {
        $errors[] = "Turf name is required.";
    }
    if (!filter_var($turf_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required.";
    }
    if (!preg_match('/^[0-9]{10}$/', $turf_contact)) {
        $errors[] = "Contact number must be 10 digits.";
    }
    if ($booking_cost <= 0) {
        $errors[] = "Booking cost must be greater than zero.";
    }
    if ($turf_capacity <= 0) {
        $errors[] = "Capacity must be greater than zero.";
    }
    if (empty($turf_address)) {
        $errors[] = "Address is required.";
    }

    // Check email uniqueness
    if (empty($errors)) {
        $escaped_email = $conn->real_escape_string($turf_email);
        $result = $conn->query("SELECT turf_id FROM turfs WHERE turf_email = '$escaped_email'");
        if ($result && $result->num_rows > 0) {
            $errors[] = "This email is already used by another turf.";
        }
    }

    // Validate owner_id (from users table)
    if (empty($errors) && $role == 'admin') {
        $escaped_owner_id = intval($owner_id);
        $result = $conn->query("SELECT id FROM users WHERE id = $escaped_owner_id AND role = 'turf_owner'");
        if (!$result || $result->num_rows === 0) {
            error_log("Invalid owner_id: $escaped_owner_id does not exist in users");
            $errors[] = "Invalid turf owner. Owner ID: $escaped_owner_id does not exist.";
        }
    }

    // Handle File Upload
    $turf_photo = '';
    if (empty($errors) && isset($_FILES['turf_photo']) && $_FILES['turf_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'Uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_extension = strtolower(pathinfo($_FILES['turf_photo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Only JPEG, PNG, or GIF images are allowed.";
        } elseif ($_FILES['turf_photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = "Image size must be less than 5MB.";
        } else {
            $file_name = 'turf_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['turf_photo']['tmp_name'], $target_path)) {
                $turf_photo = $target_path;
                $file_uploaded = true;
            } else {
                $errors[] = "Failed to upload image.";
            }
        }
    }

    // Insert Turf if No Errors
    if (empty($errors)) {
        $escaped_turf_name = $conn->real_escape_string($turf_name);
        $escaped_turf_contact = $conn->real_escape_string($turf_contact);
        $escaped_turf_email = $conn->real_escape_string($turf_email);
        $escaped_turf_area = $conn->real_escape_string($turf_area);
        $escaped_booking_cost = floatval($booking_cost);
        $escaped_turf_capacity = intval($turf_capacity);
        $escaped_turf_address = $conn->real_escape_string($turf_address);
        $escaped_turf_facility = $conn->real_escape_string($turf_facility);
        $escaped_turf_description = $conn->real_escape_string($turf_description);
        $escaped_turf_photo = $conn->real_escape_string($turf_photo);
        $escaped_owner_id = $role == 'admin' ? intval($owner_id) : intval($user_id);
        $escaped_is_active = intval($is_active);

        $query = "INSERT INTO turfs (owner_id, turf_name, turf_contact, turf_email, turf_area, booking_cost, turf_capacity, turf_address, turf_facility, turf_description, turf_photo, is_active, created_at) 
                  VALUES ($escaped_owner_id, '$escaped_turf_name', '$escaped_turf_contact', '$escaped_turf_email', '$escaped_turf_area', $escaped_booking_cost, $escaped_turf_capacity, '$escaped_turf_address', '$escaped_turf_facility', '$escaped_turf_description', '$escaped_turf_photo', $escaped_is_active, NOW())";

        error_log("Executing query: $query");
        if ($conn->query($query)) {
            error_log("Turf inserted with owner_id: $escaped_owner_id, turf_id: " . $conn->insert_id);
            $_SESSION['success_message'] = "Turf added successfully!";
            if ($role == 'admin') {
                header("Location: manage_turfs.php");
            } else {
                header("Location: turf_owner_dashboard.php");
            }
            exit;
        } else {
            error_log("Insert failed: " . $conn->error);
            $errors[] = "Failed to add turf: " . htmlspecialchars($conn->error);
        }
    }

    if (!empty($errors)) {
        $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>" . implode("<br>", $errors) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}

// Main Content Buffer Start
ob_start();
?>

<!-- Add Turf -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Add New Turf</h1>
        <a href="<?= $role == 'admin' ? 'manage_turfs.php' : 'turf_owner_dashboard.php' ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>

    <!-- Error Messages -->
    <?php if ($message): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss='alert' aria-label='Close'></button>
        </div>
    <?php endif; ?>

    <!-- Add Turf Form -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Turf Details</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="row g-3">
                    <?php if ($role == 'admin'): ?>
                        <div class="col-md-6">
                            <label for="owner_id" class="form-label">Turf Owner</label>
                            <select name="owner_id" id="owner_id" class="form-select" required>
                                <option value="">Select Owner</option>
                                <?php foreach ($owners as $owner): ?>
                                    <option value="<?= $owner['id'] ?>" <?= isset($_POST['owner_id']) && $_POST['owner_id'] == $owner['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($owner['name'] . ' (' . $owner['email'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <label for="turf_name" class="form-label">Turf Name</label>
                        <input type="text" name="turf_name" id="turf_name" class="form-control" value="<?= isset($_POST['turf_name']) ? htmlspecialchars($_POST['turf_name']) : '' ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_contact" class="form-label">Contact Number</label>
                        <input type="text" name="turf_contact" id="turf_contact" class="form-control" value="<?= isset($_POST['turf_contact']) ? htmlspecialchars($_POST['turf_contact']) : '' ?>" pattern="[0-9]{10}" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_email" class="form-label">Email</label>
                        <input type="email" name="turf_email" id="turf_email" class="form-control" value="<?= isset($_POST['turf_email']) ? htmlspecialchars($_POST['turf_email']) : '' ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_area" class="form-label">Area (e.g., 100x100)</label>
                        <input type="text" name="turf_area" id="turf_area" class="form-control" value="<?= isset($_POST['turf_area']) ? htmlspecialchars($_POST['turf_area']) : '' ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="booking_cost" class="form-label">Booking Cost (₹)</label>
                        <input type="number" name="booking_cost" id="booking_cost" class="form-control" step="0.01" value="<?= isset($_POST['booking_cost']) ? htmlspecialchars($_POST['booking_cost']) : '' ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_capacity" class="form-label">Capacity (Players)</label>
                        <input type="number" name="turf_capacity" id="turf_capacity" class="form-control" value="<?= isset($_POST['turf_capacity']) ? htmlspecialchars($_POST['turf_capacity']) : '' ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_address" class="form-label">Address</label>
                        <textarea name="turf_address" id="turf_address" class="form-control" rows="3" required><?= isset($_POST['turf_address']) ? htmlspecialchars($_POST['turf_address']) : '' ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_facility" class="form-label">Facilities</label>
                        <textarea name="turf_facility" id="turf_facility" class="form-control" rows="3"><?= isset($_POST['turf_facility']) ? htmlspecialchars($_POST['turf_facility']) : '' ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_description" class="form-label">Description</label>
                        <textarea name="turf_description" id="turf_description" class="form-control" rows="3"><?= isset($_POST['turf_description']) ? htmlspecialchars($_POST['turf_description']) : '' ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_photo" class="form-label">Turf Photo</label>
                        <input type="file" name="turf_photo" id="turf_photo" class="form-control" accept="image/jpeg,image/png,image/gif">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" <?= isset($_POST['is_active']) || !$_POST ? 'checked' : '' ?>>
                            <label for="is_active" class="form-check-label">Active</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"><i class="bi bi-plus-circle me-2"></i>Add Turf</button>
                        <a href="<?= $role == 'admin' ? 'manage_turfs.php' : 'turf_owner_dashboard.php' ?>" class="btn btn-outline-secondary ms-2"><i class="bi bi-x-circle me-2"></i>Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Custom Styles -->
<style>
    .form-control, .form-select, .form-check-input {
        border: 2px solid #198754;
        border-radius: 8px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .form-control:focus, .form-select:focus, .form-check-input:focus {
        border-color: #145c38;
        box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
        outline: none;
    }
    .btn-success {
        font-weight: 500;
        border-radius: 6px;
    }
    .btn-success:hover {
        background-color: #157347;
    }
    .btn-outline-secondary {
        font-weight: 500;
        border-radius: 6px;
    }
    .btn-outline-secondary:hover {
        background-color: #6c757d;
        color: white;
    }
</style>

<!-- JavaScript for Auto-Dismiss Alerts -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    try {
        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.classList.remove('show');
                alert.classList.add('fade');
            }, 4000);
        });
    } catch (error) {
        console.error('JavaScript error in admin_add_turf.php:', error);
    }
});
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Add New Turf - Cage Cricket";
include 'admin_template.php';
?>