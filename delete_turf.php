<?php
session_start();
require 'config.php';

// Basic DB check
if ($conn->connect_error) {
    error_log("DB connection failed (delete_turf): " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auth
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'turf_owner'])) {
    error_log("Unauthorized access delete_turf: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role    = $_SESSION['role'];

// Turf id
$turf_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;
if ($turf_id <= 0) { die("Invalid turf ID."); }

// Fetch turf (owner_id references users.id)
$stmt = $conn->prepare("SELECT * FROM turfs WHERE turf_id = ? LIMIT 1");
$stmt->bind_param("i", $turf_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $stmt->close();
    die("Turf not found.");
}
$turf = $res->fetch_assoc();
$stmt->close();

// Ownership check for turf_owner (now using users table)
if ($role === 'turf_owner' && (int)$turf['owner_id'] !== $user_id) {
    error_log("Owner mismatch: user $user_id tried to delete turf {$turf['turf_id']} owned by {$turf['owner_id']}");
    header("Location: login.php");
    exit;
}

// Impact summary
$booking_count = 0;
$rating_count  = 0;

$st = $conn->prepare("SELECT COUNT(*) AS c FROM bookings WHERE turf_id = ?");
$st->bind_param("i", $turf_id);
$st->execute();
$booking_count = (int) $st->get_result()->fetch_assoc()['c'];
$st->close();

$st = $conn->prepare("SELECT COUNT(*) AS c FROM turf_ratings WHERE turf_id = ?");
$st->bind_param("i", $turf_id);
$st->execute();
$rating_count = (int) $st->get_result()->fetch_assoc()['c'];
$st->close();

$page_title = "Delete Turf - Cage Cricket";
$photo_path = (!empty($turf['turf_photo']) && file_exists($turf['turf_photo']))
    ? htmlspecialchars($turf['turf_photo'])
    : (file_exists('images/default-turf.jpg') ? 'images/default-turf.jpg' : 'https://via.placeholder.com/1200x700');

$full_address = trim($turf['turf_address'] . ($turf['city'] ? ', ' . $turf['city'] : ''));

$errors = [];
$info_messages = [];

// Handle POST (final delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    // Confirm text + acknowledge
    $confirm_text = trim($_POST['confirm_text'] ?? '');
    $ack          = isset($_POST['ack']) ? 1 : 0;

    if ($confirm_text !== $turf['turf_name']) {
        $errors[] = "Confirmation text does not match the turf name.";
    }
    if (!$ack) {
        $errors[] = "Please acknowledge that this action is permanent.";
    }

    if (empty($errors)) {
        $current_photo = $turf['turf_photo'];

        $conn->begin_transaction();
        try {
            // 1) Delete payments for bookings of this turf
            $sql = "DELETE p FROM payments p 
                    JOIN bookings b ON p.booking_id = b.id 
                    WHERE b.turf_id = ?";
            $del = $conn->prepare($sql);
            $del->bind_param("i", $turf_id);
            if (!$del->execute()) throw new Exception("payments: ".$del->error);
            $del->close();

            // 2) Delete match_reminders for bookings of this turf
            $sql = "DELETE mr FROM match_reminders mr 
                    JOIN bookings b ON mr.booking_id = b.id 
                    WHERE b.turf_id = ?";
            $del = $conn->prepare($sql);
            $del->bind_param("i", $turf_id);
            if (!$del->execute()) throw new Exception("match_reminders: ".$del->error);
            $del->close();

            // 3) Delete ratings for this turf
            $sql = "DELETE FROM turf_ratings WHERE turf_id = ?";
            $del = $conn->prepare($sql);
            $del->bind_param("i", $turf_id);
            if (!$del->execute()) throw new Exception("turf_ratings: ".$del->error);
            $del->close();

            // 4) Delete bookings for this turf
            $sql = "DELETE FROM bookings WHERE turf_id = ?";
            $del = $conn->prepare($sql);
            $del->bind_param("i", $turf_id);
            if (!$del->execute()) throw new Exception("bookings: ".$del->error);
            $del->close();

            // 5) Finally delete the turf
            $sql = "DELETE FROM turfs WHERE turf_id = ? LIMIT 1";
            $del = $conn->prepare($sql);
            $del->bind_param("i", $turf_id);
            if (!$del->execute()) throw new Exception("turfs: ".$del->error);
            $del->close();

            $conn->commit();

            // Remove photo if it lives inside uploads/ (handle case variants)
            if ($current_photo) {
                $lower = strtolower($current_photo);
                if ((strpos($lower, 'uploads/') === 0 || strpos($lower, './uploads/') === 0) && file_exists($current_photo)) {
                    @unlink($current_photo);
                }
            }

            // Notify turf owner (users.id)
            $owner_id = $turf['owner_id'];
            if ($owner_id) {
                $notif_msg = "Your turf '{$turf['turf_name']}' has been deleted.";
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notif_stmt->bind_param("is", $owner_id, $notif_msg);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            // Flash success and redirect to manage page (no query param)
            $_SESSION['success_message'] = 'Turf deleted successfully!';
            header("Location: turf_owner_manage_turfs.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Turf delete failed (turf_id=$turf_id): " . $e->getMessage());

            $errors[] = "Delete failed due to linked records (".$e->getMessage().").";
            $errors[] = "Tip: Ensure related bookings/payments/reminders/ratings are removable or ON DELETE CASCADE is set.";
        }
    }
}

ob_start();
?>
<link rel="stylesheet" href="css/delete_turf.css">

<div class="container-fluid fade-in">
  <div class="dashboard-header">
    <h1 class="fw-bold"><i class="bi bi-trash3-fill me-2"></i> Delete Turf</h1>
  </div>

  <?php if (!empty($errors)): ?>
    <div id="form-alert" class="alert alert-danger alert-dismissible fade show text-center" role="alert">
      <?php echo implode("<br>", array_map('htmlspecialchars', $errors)); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Hero / Glass card -->
  <section class="danger-hero card-shadow">
    <div class="danger-hero-media">
      <img src="<?php echo $photo_path; ?>" alt="Turf Photo" class="danger-hero-img" loading="lazy">
      <div class="danger-ribbon"><i class="bi bi-exclamation-octagon me-2"></i>Permanent Deletion</div>
    </div>
    <div class="danger-hero-body">
      <h2 class="turf-title">
        <i class="bi bi-building me-2"></i><?php echo htmlspecialchars($turf['turf_name']); ?>
      </h2>

      <p class="address-line">
        <i class="bi bi-geo-alt me-1"></i>
        <?php echo htmlspecialchars($full_address ?: 'Address not specified'); ?>
      </p>

      <div class="chips">
        <span class="chip">
          <i class="bi bi-people"></i>
          Capacity: <?php echo (int)$turf['turf_capacity']; ?>
        </span>

        <span class="chip">
          <i class="bi bi-cash-coin"></i>
          ₹<?php echo number_format((float)$turf['booking_cost'], 2); ?>/hr
        </span>

        <span class="chip <?php echo $turf['is_active'] ? 'chip-ok' : 'chip-off'; ?>">
          <i class="bi <?php echo $turf['is_active'] ? 'bi-check-circle' : 'bi-slash-circle'; ?>"></i>
          <?php echo $turf['is_active'] ? 'Active' : 'Inactive'; ?>
        </span>
      </div>

      <div class="impact card-soft mt-3">
        <h3 class="impact-title"><i class="bi bi-info-circle me-2"></i>What will be removed</h3>
        <div class="impact-grid">
          <div class="impact-item">
            <div class="impact-icon"><i class="bi bi-calendar2-x"></i></div>
            <div>
              <div class="impact-count"><?php echo $booking_count; ?></div>
              <div class="impact-label">Bookings</div>
            </div>
          </div>
          <div class="impact-item">
            <div class="impact-icon"><i class="bi bi-star-half"></i></div>
            <div>
              <div class="impact-count"><?php echo $rating_count; ?></div>
              <div class="impact-label">Ratings</div>
            </div>
          </div>
        </div>
        <p class="mb-0 small text-danger-emphasis">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>This action cannot be undone.
        </p>
      </div>
    </div>
  </section>

  <div class="card card-shadow border-0 rounded-3 mt-3">
    <div class="card-header danger-header text-white">
      <h2 class="h5 fw-semibold mb-0">Confirm deletion</h2>
    </div>
    <div class="card-body p-4">
      <form method="POST" id="delete-turf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="mb-3">
          <label class="form-label fw-semibold">Type the turf name to confirm:</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-type"></i></span>
            <input type="text" class="form-control shadow-sm" id="confirm_text" name="confirm_text"
                   placeholder="<?php echo htmlspecialchars($turf['turf_name']); ?>" autocomplete="off" required>
          </div>
          <div class="progress mt-2" role="progressbar" aria-label="Match Progress" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" id="match-bar" style="width:0%"></div>
          </div>
        </div>

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="ack" name="ack">
          <label class="form-check-label" for="ack">
            I understand this will permanently remove the turf and its related data.
          </label>
        </div>

        <div class="actions d-flex flex-wrap gap-2">
          <button type="button" id="open-modal" class="btn btn-danger btn-lg px-5 py-3"
                  data-bs-toggle="modal" data-bs-target="#finalConfirmModal" disabled>
            <i class="bi bi-trash3-fill me-1"></i> Delete Permanently
          </button>
          <a href="view_turf.php?id=<?php echo $turf_id; ?>" class="btn btn-outline-success btn-lg px-5 py-3">
            <i class="bi bi-arrow-left-circle me-1"></i> Cancel
          </a>
        </div>

        <div class="modal fade" id="finalConfirmModal" tabindex="-1" aria-labelledby="finalConfirmLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content danger-modal">
              <div class="modal-header">
                <h5 class="modal-title" id="finalConfirmLabel">
                  <i class="bi bi-shield-exclamation me-2"></i>Final confirmation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                Are you absolutely sure you want to delete
                <strong><?php echo htmlspecialchars($turf['turf_name']); ?></strong>? This cannot be undone.
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Go Back</button>
                <button type="submit" class="btn btn-danger" form="delete-turf-form" name="action" value="delete">
                  <i class="bi bi-trash3-fill me-1"></i> Yes, delete it
                </button>
              </div>
            </div>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<script src="js/delete_turf.js"></script>

<?php
$page_content = ob_get_clean();

if (!file_exists('turf_owner_template.php')) {
    error_log("Template not found (delete_turf): turf_owner_template.php");
    die("Template file not found.");
}
include 'turf_owner_template.php';
?>
