<?php
session_start();
require 'config.php';

// Restrict to admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate turf ID
$turf_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($turf_id <= 0) {
    header("Location: manage_turfs.php?error=" . urlencode("Invalid turf ID."));
    exit;
}

// Fetch turf details
$result = $conn->query("SELECT * FROM turfs WHERE turf_id = $turf_id");
if (!$result || $result->num_rows !== 1) {
    header("Location: manage_turfs.php?error=" . urlencode("Turf not found."));
    exit;
}
$turf = $result->fetch_assoc();

// Fetch turf owners from users table
$owners = [];
$result = $conn->query("SELECT id, name, email FROM users WHERE role = 'turf_owner' ORDER BY name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $owners[] = $row;
    }
}

// Validate owner_id
$owner_id = $turf['owner_id'];
$owner_info = '';
foreach ($owners as $owner) {
    if ($owner['id'] == $owner_id) {
        $owner_info = htmlspecialchars($owner['name'] . ' (' . $owner['email'] . ')');
        break;
    }
}
if (empty($owner_info)) {
    header("Location: manage_turfs.php?error=" . urlencode("Invalid turf owner."));
    exit;
}

// Initialize form variables
$turf_name = $turf['turf_name'];
$turf_contact = $turf['turf_contact'];
$turf_email = $turf['turf_email'];
$booking_cost = $turf['booking_cost'];
$turf_capacity = $turf['turf_capacity'];
$turf_area = $turf['turf_area'];
$turf_address = $turf['turf_address'];
$turf_facility = $turf['turf_facility'] ?? '';
$turf_description = $turf['turf_description'] ?? '';
$turf_photo = $turf['turf_photo'] ?? '';
$is_active = $turf['is_active'];
$file_uploaded = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    // Retrieve form data
    $turf_name = trim($_POST['turf_name'] ?? '');
    $turf_contact = trim($_POST['turf_contact'] ?? '');
    $turf_email = trim($_POST['turf_email'] ?? '');
    $booking_cost = floatval($_POST['booking_cost'] ?? 0.0);
    $turf_capacity = intval($_POST['turf_capacity'] ?? 0);
    $turf_area = trim($_POST['turf_area'] ?? '');
    $turf_address = trim($_POST['turf_address'] ?? '');
    $turf_facility = trim($_POST['turf_facility'] ?? '');
    $turf_description = trim($_POST['turf_description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($turf_name)) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Turf name is required.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if (empty($turf_contact) || !preg_match('/^[0-9]{10}$/', $turf_contact)) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Contact number must be 10 digits.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if (empty($turf_email) || !filter_var($turf_email, FILTER_VALIDATE_EMAIL)) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Valid email is required.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if ($booking_cost <= 0) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Booking cost must be greater than 0.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if ($turf_capacity <= 0) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Turf capacity must be greater than 0.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if (empty($turf_area)) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Turf area is required.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    if (empty($turf_address)) {
        $message .= "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Turf address is required.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }

    // Check email uniqueness
    if (empty($message)) {
        $escaped_email = $conn->real_escape_string($turf_email);
        $result = $conn->query("SELECT turf_id FROM turfs WHERE turf_email = '$escaped_email' AND turf_id != $turf_id");
        if ($result && $result->num_rows > 0) {
            $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Email is already used by another turf.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        }
    }

    // Handle file upload
    if (empty($message) && isset($_FILES['turf_photo']) && $_FILES['turf_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'Uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['turf_photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($file_ext, $allowed_exts)) {
            $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Only JPG, JPEG, PNG, or GIF files allowed.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } elseif ($_FILES['turf_photo']['size'] > 5 * 1024 * 1024) {
            $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>File size must be under 5MB.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } else {
            $new_filename = 'turf_' . $turf_id . '_' . time() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['turf_photo']['tmp_name'], $upload_path)) {
                if ($turf_photo && file_exists($turf_photo)) {
                    unlink($turf_photo);
                }
                $turf_photo = $upload_path;
                $file_uploaded = true;
            } else {
                $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Failed to upload photo.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            }
        }
    }

    // Update turf
    if (empty($message)) {
        $escaped_turf_name = $conn->real_escape_string($turf_name);
        $escaped_turf_contact = $conn->real_escape_string($turf_contact);
        $escaped_turf_email = $conn->real_escape_string($turf_email);
        $escaped_booking_cost = floatval($booking_cost);
        $escaped_turf_capacity = intval($turf_capacity);
        $escaped_turf_area = $conn->real_escape_string($turf_area);
        $escaped_turf_address = $conn->real_escape_string($turf_address);
        $escaped_turf_facility = $conn->real_escape_string($turf_facility);
        $escaped_turf_description = $conn->real_escape_string($turf_description);
        $escaped_turf_photo = $conn->real_escape_string($turf_photo);
        $escaped_is_active = intval($is_active);
        $escaped_turf_id = intval($turf_id);

        $query = "UPDATE turfs SET turf_name = '$escaped_turf_name', turf_contact = '$escaped_turf_contact', turf_email = '$escaped_turf_email', booking_cost = $escaped_booking_cost, turf_capacity = $escaped_turf_capacity, turf_area = '$escaped_turf_area', turf_address = '$escaped_turf_address', turf_facility = '$escaped_turf_facility', turf_description = '$escaped_turf_description', turf_photo = '$escaped_turf_photo', is_active = $escaped_is_active WHERE turf_id = $escaped_turf_id";
        
        error_log("Executing query: $query");
        if ($conn->query($query)) {
            $_SESSION['success_message'] = "Turf updated successfully!";
            header("Location: manage_turfs.php");
            exit;
        } else {
            error_log("Update failed: " . $conn->error);
            $message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Update failed: " . htmlspecialchars($conn->error) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        }
    }
}

// Main Content Buffer Start
ob_start();
?>

<!-- Edit Turf -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Edit Turf</h1>
        <a href="manage_turfs.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back to Turfs</a>
    </div>

    <!-- Error Messages -->
    <?php if ($message): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss='alert' aria-label='Close'></button>
        </div>
    <?php endif; ?>

    <!-- Edit Turf Form -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Turf Details</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="owner_id" class="form-label">Turf Owner</label>
                        <input type="text" class="form-control" id="owner_id" value="<?php echo $owner_info; ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_name" class="form-label">Turf Name</label>
                        <input type="text" name="turf_name" id="turf_name" class="form-control" value="<?php echo htmlspecialchars($turf_name); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_contact" class="form-label">Contact Number</label>
                        <input type="text" name="turf_contact" id="turf_contact" class="form-control" value="<?php echo htmlspecialchars($turf_contact); ?>" pattern="[0-9]{10}" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_email" class="form-label">Email</label>
                        <input type="email" name="turf_email" id="turf_email" class="form-control" value="<?php echo htmlspecialchars($turf_email); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_area" class="form-label">Area (e.g., 100x100)</label>
                        <input type="text" name="turf_area" id="turf_area" class="form-control" value="<?php echo htmlspecialchars($turf_area); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="booking_cost" class="form-label">Booking Cost (₹)</label>
                        <input type="number" name="booking_cost" id="booking_cost" class="form-control" step="0.01" value="<?php echo htmlspecialchars($booking_cost); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_capacity" class="form-label">Capacity (Players)</label>
                        <input type="number" name="turf_capacity" id="turf_capacity" class="form-control" value="<?php echo htmlspecialchars($turf_capacity); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_address" class="form-label">Address</label>
                        <textarea name="turf_address" id="turf_address" class="form-control" rows="3" required><?php echo htmlspecialchars($turf_address); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_facility" class="form-label">Facilities</label>
                        <textarea name="turf_facility" id="turf_facility" class="form-control" rows="3"><?php echo htmlspecialchars($turf_facility); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_description" class="form-label">Description</label>
                        <textarea name="turf_description" id="turf_description" class="form-control" rows="3"><?php echo htmlspecialchars($turf_description); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_photo" class="form-label">Turf Photo</label>
                        <input type="file" name="turf_photo" id="turf_photo" class="form-control" accept="image/jpeg,image/png,image/gif">
                        <?php if (!$file_uploaded && $turf_photo): ?>
                            <p class="form-text mt-1">Current Photo:</p>
                            <img src="<?php echo htmlspecialchars($turf_photo); ?>" alt="Turf Photo" class="rounded mt-2" style="max-width: 200px; height: auto;">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" <?php echo $is_active ? 'checked' : ''; ?>>
                            <label for="is_active" class="form-check-label">Active</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"><i class="bi bi-save me-2"></i>Update Turf</button>
                        <a href="manage_turfs.php" class="btn btn-outline-secondary ms-2"><i class="bi bi-x-circle me-2"></i>Cancel</a>
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
        console.error('JavaScript error in admin_edit_turf.php:', error);
    }
});
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Edit Turf - Admin Panel";
include 'admin_template.php';
?>