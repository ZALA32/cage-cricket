<?php
session_start();
require 'config.php';

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'turf_owner'])) {
    error_log("Unauthorized access attempt: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];
$turf_name = $turf_contact = $turf_email = $turf_area = $turf_address = $city = $turf_facility = $turf_description = '';
$booking_cost = '';
$turf_capacity = '';
$owner_id = ($role == 'admin' && isset($_POST['owner_id'])) ? intval($_POST['owner_id']) : $user_id;
$file_uploaded = false;
$message = '';
$errors  = [];

define('TURF_UPLOAD_DIR', 'uploads/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    }

    // Read fields
    $turf_name      = trim($_POST['turf_name'] ?? '');
    $turf_contact   = trim($_POST['turf_contact'] ?? '');
    $turf_email     = trim($_POST['turf_email'] ?? '');
    $booking_cost   = floatval($_POST['booking_cost'] ?? 0);
    $turf_capacity  = intval($_POST['turf_capacity'] ?? 0);
    $turf_area      = trim($_POST['turf_area'] ?? '');
    $turf_address   = trim($_POST['turf_address'] ?? '');
    $city           = trim($_POST['city'] ?? '');
    $turf_facility  = trim($_POST['turf_facility'] ?? '');
    $turf_description = trim($_POST['turf_description'] ?? '');

    // Validation
    if ($turf_name === '')               $errors[] = "Turf name is required.";
    if ($turf_contact === '')            $errors[] = "Contact number is required.";
    if (!preg_match('/^[0-9]{10}$/', $turf_contact)) $errors[] = "Contact number must be exactly 10 digits.";
    if ($turf_email === '')              $errors[] = "Email is required.";
    if (!filter_var($turf_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    if ($booking_cost <= 0)              $errors[] = "Booking cost must be greater than 0.";
    if ($turf_capacity <= 0)             $errors[] = "Turf capacity must be greater than 0.";
    if ($turf_area === '')               $errors[] = "Turf area is required.";
    if ($turf_address === '')            $errors[] = "Turf address is required.";
    if ($city === '')                    $errors[] = "City is required.";
    if ($role === 'admin' && empty($owner_id)) $errors[] = "Please select a turf owner.";

    // Unique email check
    if (!$errors) {
        $stmt = $conn->prepare("SELECT turf_id, turf_name FROM turfs WHERE turf_email = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $errors[] = "Error checking email availability.";
        } else {
            $stmt->bind_param("s", $turf_email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $errors[] = "This email is already registered for another turf (ID: {$row['turf_id']}, Name: ".htmlspecialchars($row['turf_name']).").";
            }
            $stmt->close();
        }
    }

    // Verify owner (admin) - FIXED: Use users table with role filter
    if (!$errors && $role === 'admin') {
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'turf_owner'");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $errors[] = "Error verifying turf owner.";
        } else {
            $stmt->bind_param("i", $owner_id);
            $stmt->execute();
            $owner_check = $stmt->get_result();
            if ($owner_check->num_rows === 0) {
                $errors[] = "Invalid turf owner selected.";
                $owner_id = null;
            }
            $stmt->close();
        }
    }

    // Upload
    $turf_photo = null;
    if (!$errors && isset($_FILES['turf_photo']) && $_FILES['turf_photo']['error'] === 0) {
        if (!is_dir(TURF_UPLOAD_DIR) && !mkdir(TURF_UPLOAD_DIR, 0755, true)) {
            $errors[] = "Failed to create upload directory.";
        } else {
            $ext = strtolower(pathinfo($_FILES['turf_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif'];
            if (!in_array($ext, $allowed)) {
                $errors[] = "Only JPG, JPEG, PNG, and GIF files are allowed.";
            } elseif ($_FILES['turf_photo']['size'] > 5*1024*1024) {
                $errors[] = "Photo size must be less than 5MB.";
            } elseif (($info = getimagesize($_FILES['turf_photo']['tmp_name'])) === false) {
                $errors[] = "Uploaded file is not a valid image.";
            } else {
               $base = 'turf_'.time();
               $new_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $base).'.'.$ext;
               $path = TURF_UPLOAD_DIR.$new_name;
                if (!move_uploaded_file($_FILES['turf_photo']['tmp_name'], $path)) {
                    $errors[] = "Error uploading photo.";
                } else {
                    $turf_photo = $path;
                    $file_uploaded = true;
                }
            }
        }
    }

    // Insert
    if (!$errors) {
        $sql = "INSERT INTO turfs (turf_name, turf_contact, turf_email, booking_cost, turf_capacity,
                                   turf_area, turf_address, city, turf_facility, turf_description, turf_photo,
                                   owner_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $errors[] = "Error preparing database query.";
        } else {
            $stmt->bind_param(
                "sssdissssssi",
                $turf_name, $turf_contact, $turf_email, $booking_cost, $turf_capacity,
                $turf_area, $turf_address, $city, $turf_facility, $turf_description, $turf_photo, $owner_id
            );
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Turf added successfully!';
                header("Location: turf_owner_manage_turfs.php");
                exit;
            } else {
                $errors[] = "Error adding turf: ".$stmt->error;
            }
            $stmt->close();
        }
    }

    if ($errors) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>"
                 . implode("<br>", $errors)
                 . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}

// Owners dropdown (for admin) - FIXED: Use users table with role filter
$turf_owners = [];
if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'turf_owner'");
    if ($stmt) {
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $turf_owners[] = $row; }
        $stmt->close();
    } else {
        error_log("Prepare failed: " . $conn->error);
        $message .= "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error fetching turf owners.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}

$page_title = "Add Turf - Cage Cricket";
ob_start();
?>
<link rel="stylesheet" href="css/add_turf.css">

<div class="container-fluid fade-in">
    <div class="dashboard-header">
        <h1 class="fw-bold">
            <i class="bi bi-plus-circle-fill me-2"></i>Add a New Turf
        </h1>
    </div>
    <?php if ($message): ?>
        <div class="alert-container text-center mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header custom-turf-header text-white">
            <h2 class="h4 fw-semibold mb-0">Turf Details</h2>
        </div>
        <div class="card-body p-4">
            <form id="addTurfForm" method="POST" enctype="multipart/form-data" novalidate class="needs-validation">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="turf_name" class="form-label fw-semibold">Turf Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" id="turf_name" name="turf_name" value="<?php echo htmlspecialchars($turf_name); ?>" required>
                        <div class="invalid-feedback">Turf name is required.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_contact" class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control shadow-sm" id="turf_contact" name="turf_contact" maxlength="10" value="<?php echo htmlspecialchars($turf_contact); ?>" required>
                        </div>
                        <div class="invalid-feedback">Contact number must be exactly 10 digits.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_email" class="form-label fw-semibold">Turf Email <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control shadow-sm" id="turf_email" name="turf_email" value="<?php echo htmlspecialchars($turf_email); ?>" required>
                        </div>
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="booking_cost" class="form-label fw-semibold">Price per Hour (₹) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                            <input type="number" step="0.01" min="0.01" class="form-control shadow-sm" id="booking_cost" name="booking_cost" value="<?php echo htmlspecialchars($booking_cost); ?>" required>
                        </div>
                        <div class="invalid-feedback">Booking cost must be greater than 0.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_capacity" class="form-label fw-semibold">Turf Capacity <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-people"></i></span>
                            <input type="number" min="1" class="form-control shadow-sm" id="turf_capacity" name="turf_capacity" value="<?php echo htmlspecialchars($turf_capacity); ?>" required>
                        </div>
                        <div class="invalid-feedback">Turf capacity must be greater than 0.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="turf_area" class="form-label fw-semibold">Turf Area (e.g., 200x150 sq ft) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-aspect-ratio"></i></span>
                            <input type="text" class="form-control shadow-sm" id="turf_area" name="turf_area" value="<?php echo htmlspecialchars($turf_area); ?>" required>
                        </div>
                        <div class="invalid-feedback">Turf area is required.</div>
                    </div>
                    <?php if ($role == 'admin') { ?>
                        <div class="col-md-6">
                            <label for="owner_id" class="form-label fw-semibold">Turf Owner <span class="text-danger">*</span></label>
                            <select name="owner_id" id="owner_id" class="form-select shadow-sm" required>
                                <option value="">Select Turf Owner</option>
                                <?php foreach ($turf_owners as $owner) { ?>
                                    <option value="<?php echo $owner['id']; ?>" <?php echo ($owner_id == $owner['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($owner['name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <div class="invalid-feedback">Please select a turf owner.</div>
                        </div>
                    <?php } ?>
                    <div class="col-12">
                        <label for="turf_address" class="form-label fw-semibold">Turf Address <span class="text-danger">*</span></label>
                        <textarea class="form-control shadow-sm" id="turf_address" name="turf_address" rows="4" required><?php echo htmlspecialchars($turf_address); ?></textarea>
                        <div class="invalid-feedback">Turf address is required.</div>
                    </div>
                    <div class="col-12">
                        <label for="city" class="form-label fw-semibold">City <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" id="city" name="city" value="<?php echo htmlspecialchars($city); ?>" required>
                        <div class="invalid-feedback">City is required.</div>
                    </div>
                    <div class="col-12">
                        <label for="turf_facility" class="form-label fw-semibold">Facilities (e.g., Floodlights, Parking, Changing Rooms)</label>
                        <textarea class="form-control shadow-sm" id="turf_facility" name="turf_facility" rows="4"><?php echo htmlspecialchars($turf_facility); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label for="turf_description" class="form-label fw-semibold">Turf Description (Optional)</label>
                        <textarea class="form-control shadow-sm" id="turf_description" name="turf_description" rows="4"><?php echo htmlspecialchars($turf_description); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label for="turf_photo" class="form-label fw-semibold">Turf Photo (JPG, PNG, GIF)</label>
                        <input type="file" class="form-control shadow-sm" id="turf_photo" name="turf_photo" accept="image/jpeg,image/png,image/gif">
                        <div id="file-feedback" class="form-text"><?php echo $file_uploaded ? 'File Uploaded (Re-select if needed)' : 'Choose a file...'; ?></div>
                    </div>
                    <div class="col-12 text-center mt-5">
                        <button type="button" id="openConfirmModal" class="btn btn-success btn-lg px-5 py-3 hover-scale">
                            <i class="bi bi-check-circle me-2"></i> Add Turf
                        </button>
                        <a href="turf_owner_dashboard.php" class="btn btn-danger btn-lg px-5 py-3 hover-scale ms-3">
                            <i class="bi bi-x-circle me-2"></i> Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirmation Modal with FULL details -->
<div class="modal fade" id="confirmAddModal" tabindex="-1" aria-labelledby="confirmAddLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="confirmAddLabel"><i class="bi bi-check2-square me-2"></i>Confirm Turf Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3 text-muted">Please review the details. If everything looks good, click <strong>Yes, Add Turf</strong>.</p>
        <div id="confirmPreview"><!-- populated by JS --></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-pencil-square me-1"></i> Edit
        </button>
        <button type="button" id="confirmSubmitBtn" class="btn btn-success">
          <i class="bi bi-check-circle me-1"></i> Yes, Add Turf
        </button>
      </div>
    </div>
  </div>
</div>

<script src="js/add_turf.js"></script>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>