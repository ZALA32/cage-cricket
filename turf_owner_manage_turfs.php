<?php
session_start();
require 'config.php';

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner'])) {
    error_log("Unauthorized access attempt: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle name: use session if set, otherwise fetch from users table
$name = isset($_SESSION['name']) ? $_SESSION['name'] : '';
if (empty($name)) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'turf_owner'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $name = $user['name'];
        $_SESSION['name'] = $name;
    } else {
        error_log("Failed to fetch user name for user_id: $user_id");
        $name = "Turf Owner";
    }
    $stmt->close();
}

$message = '';

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $alert_class = (strpos($_SESSION['success_message'], 'Error') !== false || strpos($_SESSION['success_message'], 'Invalid') !== false || strpos($_SESSION['success_message'], 'Cannot') !== false) ? 'alert-danger' : 'alert-green';
    $message = "<div id='form-alert' class='alert $alert_class alert-dismissible fade show text-center' role='alert'>" . htmlspecialchars($_SESSION['success_message']) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    unset($_SESSION['success_message']);
}

// Handle turf status toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_turf']) && isset($_POST['turf_id']) && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Invalid CSRF token!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    } elseif (!is_numeric($_POST['turf_id']) || $_POST['turf_id'] <= 0) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Invalid turf ID!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    } else {
        $turf_id = intval($_POST['turf_id']);
        $current_status = isset($_POST['current_status']) ? intval($_POST['current_status']) : 0;
        $is_active = $current_status == 1 ? 0 : 1;

        $stmt = $conn->prepare("UPDATE turfs SET is_active = ? WHERE turf_id = ? AND owner_id = ?");
        $stmt->bind_param("iii", $is_active, $turf_id, $user_id);
        if ($stmt->execute()) {
            $message = "<div id='form-alert' class='alert alert-green alert-dismissible fade show text-center' role='alert'>Turf status updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } else {
            error_log("Error updating turf status: " . $conn->error);
            $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error updating turf status: " . htmlspecialchars($conn->error) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        }
        $stmt->close();
    }
}

// Handle featured toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_featured']) && isset($_POST['turf_id']) && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Invalid CSRF token!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    } elseif (!is_numeric($_POST['turf_id']) || $_POST['turf_id'] <= 0) {
        $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Invalid turf ID!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    } else {
        $turf_id = intval($_POST['turf_id']);
        $current_featured = isset($_POST['current_featured']) ? intval($_POST['current_featured']) : 0;
        $is_featured = $current_featured == 1 ? 0 : 1;

        $verify_stmt = $conn->prepare("SELECT turf_id FROM turfs WHERE turf_id = ? AND owner_id = ?");
        $verify_stmt->bind_param("ii", $turf_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();

        if ($verify_result->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE turfs SET is_featured = ? WHERE turf_id = ? AND owner_id = ?");
            $stmt->bind_param("iii", $is_featured, $turf_id, $user_id);
            if ($stmt->execute()) {
                $message = "<div id='form-alert' class='alert alert-green alert-dismissible fade show text-center' role='alert'>Turf featured status updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            } else {
                error_log("Error updating featured status: " . $conn->error);
                $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error updating featured status: " . htmlspecialchars($conn->error) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            }
            $stmt->close();
        } else {
            $message = "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Invalid turf or not authorized to update!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        }
        $verify_stmt->close();
    }
}

// Fetch owner's turfs and bookings
$turfs = [];
$query = "SELECT t.*, b.id AS booking_id, b.date AS booking_date 
          FROM turfs t
          LEFT JOIN bookings b ON b.turf_id = t.turf_id 
          WHERE t.owner_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $turfs[] = $row;
}
$stmt->close();

$page_title = "Manage Turfs - Cage Cricket";
ob_start();
?>

<div class="container-fluid fade-in">
    <!-- Header Section -->
    <div class="dashboard-header">
        <h1 class="fade-in">Manage Your Turfs</h1>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
        <div id="form-alert" class="text-center mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Turfs Section -->
    <div class="turfs-section">
        <h2><i class="bi bi-geo-alt-fill me-2"></i>Your Turfs</h2>
        <?php if (count($turfs) > 0): ?>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead class="sticky-header">
                        <tr>
                            <th data-label="Turf Photo">Turf Photo</th>
                            <th data-label="Turf Name">Turf Name</th>
                            <th data-label="Address">Address</th>
                            <th data-label="Booking Cost">Cost (₹/hr)</th>
                            <th data-label="Bookings">Bookings</th>
                            <th data-label="Status">Status</th>
                            <th data-label="Featured">Featured</th>
                            <th data-label="Toggle Featured">Toggle Featured</th>
                            <th data-label="Toggle Active">Toggle Active</th>
                            <th data-label="Actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($turfs as $index => $turf): ?>
                            <tr class="fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                <td data-label="Turf Photo">
                                    <div class="d-flex align-items-center gap-2 justify-content-center">
                                        <?php 
                                        $photo_path = (!empty($turf['turf_photo']) && file_exists($turf['turf_photo'])) 
                                            ? htmlspecialchars($turf['turf_photo']) 
                                            : (file_exists('images/default-turf.jpg') ? 'images/default-turf.jpg' : 'https://via.placeholder.com/150');
                                        ?>
                                        <img src="<?php echo $photo_path; ?>" alt="Turf Photo" class="turf-photo" loading="lazy">
                                    </div>
                                </td>
                                <td data-label="Turf Name" class="turf-text fw-bold"><?php echo htmlspecialchars($turf['turf_name']); ?></td>
                                <td data-label="Address"><?php echo htmlspecialchars($turf['turf_address']); ?></td>
                                <td data-label="Booking Cost"><?php echo htmlspecialchars(number_format($turf['booking_cost'], 2)); ?></td>
                                <td data-label="Bookings">
                                    <?php
                                    // Display bookings for this turf
                                    if (!empty($turf['booking_id'])) {
                                        echo "<span>" . htmlspecialchars($turf['booking_date']) . "</span>";
                                    } else {
                                        echo "No bookings yet";
                                    }
                                    ?>
                                </td>
                                <td data-label="Status">
                                    <span class="badge status-<?php echo $turf['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $turf['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td data-label="Featured">
                                    <span class="badge featured-<?php echo $turf['is_featured'] ? 'yes' : 'no'; ?>">
                                        <?php echo $turf['is_featured'] ? 'Featured' : 'Not Featured'; ?>
                                    </span>
                                </td>
                                <td data-label="Toggle Featured">
                                    <form method="POST" class="d-inline" data-confirm>
                                        <input type="hidden" name="turf_id" value="<?php echo $turf['turf_id']; ?>">
                                        <input type="hidden" name="toggle_featured" value="1">
                                        <input type="hidden" name="current_featured" value="<?php echo $turf['is_featured']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <button type="submit" class="btn btn-action btn-outline-success" data-bs-toggle="tooltip" title="<?php echo $turf['is_featured'] ? 'Remove Featured Status' : 'Mark as Featured'; ?>">
                                            <?php echo $turf['is_featured'] ? 'Remove' : 'Feature'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td data-label="Toggle Active">
                                    <form method="POST" class="d-inline" data-confirm>
                                        <input type="hidden" name="turf_id" value="<?php echo $turf['turf_id']; ?>">
                                        <input type="hidden" name="toggle_turf" value="1">
                                        <input type="hidden" name="current_status" value="<?php echo $turf['is_active']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <button type="submit" class="btn btn-action <?php echo $turf['is_active'] ? 'btn-danger' : 'btn-primary'; ?>" data-bs-toggle="tooltip" title="<?php echo $turf['is_active'] ? 'Deactivate Turf' : 'Activate Turf'; ?>">
                                            <?php echo $turf['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td data-label="Actions">
                                    <div class="dropdown">
                                        <button class="btn btn-action btn-primary dropdown-toggle" type="button" id="actionsDropdown<?php echo $turf['turf_id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                            Manage
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="actionsDropdown<?php echo $turf['turf_id']; ?>">
                                            <li><a class="dropdown-item" href="view_turf.php?id=<?php echo $turf['turf_id']; ?>">View</a></li>
                                            <li><a class="dropdown-item" href="edit_turf.php?id=<?php echo $turf['turf_id']; ?>">Edit</a></li>
                                            <li><a class="dropdown-item" href="delete_turf.php?id=<?php echo $turf['turf_id']; ?>">Delete</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <p>No turfs found. <a href="add_turf.php" class="text-success">Add one now!</a></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Load CSS and JavaScript -->
<link href="css/turf_owner_manage_turfs.css" rel="stylesheet">
<script src="js/turf_owner_manage_turfs.js"></script>

<?php
if (!file_exists('turf_owner_template.php')) {
    error_log("Template file not found: turf_owner_template.php");
    die("Template file not found.");
}
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
