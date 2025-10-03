<?php
session_start();
require 'config.php';

// Basic DB check
if ($conn->connect_error) {
    error_log("DB connection failed (edit_turf): " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auth: admin or turf_owner
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'turf_owner'])) {
    error_log("Unauthorized access edit_turf: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role    = $_SESSION['role'];

// Turf id
$turf_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;
if ($turf_id <= 0) { die("Invalid turf ID."); }

// Fetch turf
$stmt = $conn->prepare("SELECT * FROM turfs WHERE turf_id = ?");
$stmt->bind_param("i", $turf_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) { die("Turf not found."); }
$turf = $res->fetch_assoc();
$stmt->close();

// Ownership check (unless admin)
if ($role === 'turf_owner' && (int)$turf['owner_id'] !== $user_id) {
    error_log("Owner mismatch: user $user_id tried to edit turf {$turf['turf_id']} owned by {$turf['owner_id']}");
    header("Location: login.php");
    exit;
}

// Prefill vars
$message         = '';
$errors          = [];
$turf_name       = $turf['turf_name'];
$turf_contact    = $turf['turf_contact'];
$turf_email      = $turf['turf_email'];
$booking_cost    = $turf['booking_cost'];
$turf_capacity   = $turf['turf_capacity'];
$turf_area       = $turf['turf_area'];
$turf_address    = $turf['turf_address'];
$city            = $turf['city'];
$turf_facility   = $turf['turf_facility'];
$turf_description= $turf['turf_description'];
$current_photo   = $turf['turf_photo'];
$owner_id        = (int)$turf['owner_id'];
$is_active       = (int)$turf['is_active'];
$is_featured     = (int)$turf['is_featured'];
$file_uploaded   = false;

// Admin: get owners for dropdown (from users table)
$turf_owners = [];
if ($role === 'admin') {
    $st = $conn->prepare("SELECT id, name FROM users WHERE role = 'turf_owner' ORDER BY name ASC");
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) { $turf_owners[] = $row; }
    $st->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    // Inputs
    $turf_name        = trim($_POST['turf_name'] ?? '');
    $turf_contact     = trim($_POST['turf_contact'] ?? '');
    $turf_email       = trim($_POST['turf_email'] ?? '');
    $booking_cost     = isset($_POST['booking_cost']) ? (float)$_POST['booking_cost'] : 0;
    $turf_capacity    = isset($_POST['turf_capacity']) ? (int)$_POST['turf_capacity'] : 0;
    $turf_area        = trim($_POST['turf_area'] ?? '');
    $turf_address     = trim($_POST['turf_address'] ?? '');
    $city             = trim($_POST['city'] ?? '');
    $turf_facility    = trim($_POST['turf_facility'] ?? '');
    $turf_description = trim($_POST['turf_description'] ?? '');
    $is_active        = isset($_POST['is_active']) ? 1 : 0;
    $is_featured      = isset($_POST['is_featured']) ? 1 : 0;

    // Admin can change owner (from users table)
    if ($role === 'admin' && isset($_POST['owner_id']) && ctype_digit($_POST['owner_id'])) {
        $owner_id = (int)$_POST['owner_id'];
        $ck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'turf_owner'");
        $ck->bind_param("i", $owner_id);
        $ck->execute();
        $o = $ck->get_result();
        if ($o->num_rows === 0) { $errors[] = "Invalid turf owner selected."; }
        $ck->close();
    } else {
        $owner_id = (int)$turf['owner_id'];
    }

    // Validation
    if ($turf_name === '') $errors[] = "Turf name is required.";
    if ($turf_contact === '') $errors[] = "Contact number is required.";
    if (!preg_match('/^[0-9]{10}$/', $turf_contact)) $errors[] = "Contact number must be exactly 10 digits.";
    if ($turf_email === '' || !filter_var($turf_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    if ($booking_cost <= 0) $errors[] = "Booking cost must be greater than 0.";
    if ($turf_capacity <= 0) $errors[] = "Turf capacity must be greater than 0.";
    if ($turf_area === '') $errors[] = "Turf area is required.";
    if ($turf_address === '') $errors[] = "Turf address is required.";
    if ($city === '') $errors[] = "City is required.";

    // Unique email excluding this turf
    if (empty($errors)) {
        $st = $conn->prepare("SELECT turf_id FROM turfs WHERE turf_email = ? AND turf_id <> ?");
        $st->bind_param("si", $turf_email, $turf_id);
        $st->execute();
        $dupe = $st->get_result();
        if ($dupe->num_rows > 0) $errors[] = "This email is already registered for another turf.";
        $st->close();
    }

    // Remove photo checkbox
    $remove_photo = isset($_POST['remove_photo']) ? 1 : 0;

    // Optional file upload
    $new_photo_path = $current_photo;
    if (empty($errors) && isset($_FILES['turf_photo']) && $_FILES['turf_photo']['error'] === 0) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            $errors[] = "Failed to create upload directory.";
        }

        if (empty($errors)) {
            $file_extension = strtolower(pathinfo($_FILES['turf_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif'];
            if (!in_array($file_extension, $allowed)) {
                $errors[] = "Only JPG, JPEG, PNG, and GIF files are allowed.";
            } elseif ($_FILES['turf_photo']['size'] > 5 * 1024 * 1024) {
                $errors[] = "Photo size must be less than 5MB.";
            } else {
                $image_info = @getimagesize($_FILES['turf_photo']['tmp_name']);
                if ($image_info === false) {
                    $errors[] = "Uploaded file is not a valid image.";
                } else {
                    $new_filename = 'turf_' . time() . '.' . $file_extension;
                    $upload_path  = $upload_dir . $new_filename;
                    if (!move_uploaded_file($_FILES['turf_photo']['tmp_name'], $upload_path)) {
                        $errors[] = "Error uploading photo.";
                    } else {
                        $new_photo_path = $upload_path;
                        $file_uploaded  = true;
                    }
                }
            }
        }
    }

    // If remove photo and no new upload, clear it
    if (empty($errors) && $remove_photo && !$file_uploaded) {
        $new_photo_path = null;
    }

    // Update DB
    if (empty($errors)) {
        $q = "UPDATE turfs
              SET turf_name=?, turf_contact=?, turf_email=?, booking_cost=?, turf_capacity=?,
                  turf_area=?, turf_address=?, city=?, turf_facility=?, turf_description=?,
                  turf_photo=?, owner_id=?, is_active=?, is_featured=?
              WHERE turf_id=?";

        $st = $conn->prepare($q);
        if (!$st) {
            error_log("Prepare failed (update): " . $conn->error);
            $errors[] = "Error preparing update.";
        } else {
            $st->bind_param(
                "sssdissssssiiii",
                $turf_name,        // s
                $turf_contact,     // s
                $turf_email,       // s
                $booking_cost,     // d
                $turf_capacity,    // i
                $turf_area,        // s
                $turf_address,     // s
                $city,             // s
                $turf_facility,    // s
                $turf_description, // s
                $new_photo_path,   // s (nullable)
                $owner_id,         // i
                $is_active,        // i
                $is_featured,      // i
                $turf_id           // i
            );
            if ($st->execute()) {
                // If replaced or removed, delete old file safely (uploads/ case-insensitive)
                if (($file_uploaded || $remove_photo) && $current_photo) {
                    $lower = strtolower($current_photo);
                    if ((strpos($lower, 'uploads/') === 0 || strpos($lower, './uploads/') === 0) && file_exists($current_photo)) {
                        @unlink($current_photo);
                    }
                }

                // Flash success and redirect to Manage Turfs (same UX as add/delete)
                $_SESSION['success_message'] = 'Turf updated successfully!';
                header("Location: turf_owner_manage_turfs.php");
                exit;
            } else {
                $errors[] = "Error updating turf: " . $st->error;
            }
            $st->close();
        }
    }

    if (!empty($errors)) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>"
                 . implode("<br>", array_map('htmlspecialchars', $errors))
                 . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}

$page_title = "Edit Turf - Cage Cricket";
if (!file_exists('turf_owner_template.php')) {
    error_log("Template file not found: turf_owner_template.php");
    die("Template file not found.");
}

echo '<link rel="stylesheet" href="css/edit_turf.css">';
ob_start();
?>

<div class="container-fluid fade-in">
    <div class="dashboard-header">
        <h1 class="fw-bold">
            <i class="bi bi-pencil-square me-2"></i>Edit Turf
        </h1>
    </div>

    <?php if ($message): ?>
        <div class="alert-container text-center mb-4"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header custom-turf-header text-white">
            <h2 class="h4 fw-semibold mb-0">Turf Details</h2>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" novalidate class="needs-validation" id="edit-turf-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Turf Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" name="turf_name" value="<?php echo htmlspecialchars($turf_name); ?>" required>
                        <div class="invalid-feedback">Turf name is required.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control shadow-sm" name="turf_contact" maxlength="10" value="<?php echo htmlspecialchars($turf_contact); ?>" required>
                        <div class="invalid-feedback">Contact number must be exactly 10 digits.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Turf Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control shadow-sm" name="turf_email" value="<?php echo htmlspecialchars($turf_email); ?>" required>
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Price per Hour (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control shadow-sm" name="booking_cost" value="<?php echo htmlspecialchars($booking_cost); ?>" required>
                        <div class="invalid-feedback">Booking cost must be greater than 0.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Turf Capacity <span class="text-danger">*</span></label>
                        <input type="number" min="1" class="form-control shadow-sm" name="turf_capacity" value="<?php echo htmlspecialchars($turf_capacity); ?>" required>
                        <div class="invalid-feedback">Turf capacity must be greater than 0.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Turf Area (e.g., 200x150 sq ft) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" name="turf_area" value="<?php echo htmlspecialchars($turf_area); ?>" required>
                        <div class="invalid-feedback">Turf area is required.</div>
                    </div>

                    <?php if ($role === 'admin') { ?>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Turf Owner <span class="text-danger">*</span></label>
                        <select name="owner_id" class="form-select shadow-sm" required>
                            <option value="">Select Turf Owner</option>
                            <?php foreach ($turf_owners as $owner) { ?>
                                <option value="<?php echo $owner['id']; ?>" <?php echo ($owner_id == $owner['id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($owner['name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <div class="invalid-feedback">Please select a turf owner.</div>
                    </div>
                    <?php } ?>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Turf Address <span class="text-danger">*</span></label>
                        <textarea class="form-control shadow-sm" name="turf_address" rows="4" required><?php echo htmlspecialchars($turf_address); ?></textarea>
                        <div class="invalid-feedback">Turf address is required.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">City <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" name="city" value="<?php echo htmlspecialchars($city); ?>" required>
                        <div class="invalid-feedback">City is required.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Facilities</label>
                        <textarea class="form-control shadow-sm" name="turf_facility" rows="4"><?php echo htmlspecialchars($turf_facility); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description (Optional)</label>
                        <textarea class="form-control shadow-sm" name="turf_description" rows="4"><?php echo htmlspecialchars($turf_description); ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold d-flex align-items-center justify-content-between">
                            <span>Turf Photo (JPG, PNG, GIF)</span>
                            <?php if ($current_photo): ?>
                                <a href="<?php echo htmlspecialchars($current_photo); ?>" target="_blank" class="small text-success">Open current</a>
                            <?php endif; ?>
                        </label>
                        <input type="file" class="form-control shadow-sm" id="turf_photo" name="turf_photo" accept="image/jpeg,image/png,image/gif">
                        <div class="form-text" id="file-feedback">
                            <?php echo $current_photo ? 'Choose a file to replace the current photo (optional).' : 'No current photo — choose a file to upload.'; ?>
                        </div>

                        <?php if ($current_photo): ?>
                        <div class="current-photo-wrap mt-2">
                            <img
                                src="<?php echo htmlspecialchars($current_photo); ?>"
                                alt="Current Turf Photo"
                                class="current-photo-img"
                                id="current-photo-img"
                                onerror="this.closest('.current-photo-wrap')?.remove()">
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" value="1" id="remove_photo" name="remove_photo">
                            <label class="form-check-label" for="remove_photo">Remove current photo</label>
                        </div>
                        <?php endif; ?>

                        <div class="preview mt-3" id="preview" style="display:none;">
                            <p class="mb-1 fw-semibold">New photo preview:</p>
                            <img src="" alt="Preview" id="preview-img" class="current-photo-img">
                        </div>
                    </div>

                    <div class="col-md-6 mt-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?php echo $is_active ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-md-6 mt-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" <?php echo $is_featured ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_featured">Featured</label>
                        </div>
                    </div>

                    <div class="col-12 text-center mt-4">
                        <button type="submit" class="btn btn-success btn-lg px-5 py-3 hover-scale">Save Changes</button>
                        <a href="view_turf.php?id=<?php echo $turf_id; ?>" class="btn btn-danger btn-lg px-5 py-3 hover-scale ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="js/edit_turf.js"></script>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
