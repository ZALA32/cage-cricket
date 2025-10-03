<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'config.php';

// Ensure CSRF token exists before rendering any forms on this page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!function_exists('csrf_check')) {
    function csrf_check($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}
// Ensure time zone is IST
date_default_timezone_set('Asia/Kolkata');

// Auth: turf_owner only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner'])) {
    header("Location: login.php");
    exit;
}
$owner_id = (int) $_SESSION['user_id'];

// Helpers
function sanitize_phone($s){ return preg_replace('/[^0-9+]/', '', (string)$s); }

// Load current owner profile (from users table)
$owner = null;
$userRow = null;
try {
    // Fetch profile from users table (replacing the turf_owner table)
    $stmt = $conn->prepare("SELECT id, name, email, contact_no, password, created_at FROM users WHERE id = ? AND role = 'turf_owner'");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $owner = $res->fetch_assoc();
    $stmt->close();

    if (!$owner) { throw new Exception("Owner not found."); }

} catch (Exception $e) {
    $_SESSION['dashboard_message'] = "Error loading profile: ".htmlspecialchars($e->getMessage());
    header("Location: turf_owner_dashboard.php");
    exit;
}

// Quick stats
$stats = ['turfs'=>0,'reviews'=>0,'avg'=>0.0];
try {
    // Turfs count (using users table for ownership check)
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM turfs WHERE owner_id = ?");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $stats['turfs'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // Reviews count + avg across owner turfs
    $stmt = $conn->prepare("
        SELECT COUNT(tr.id) AS c, AVG(tr.rating) AS a
        FROM turf_ratings tr
        JOIN turfs t ON tr.turf_id = t.turf_id
        WHERE t.owner_id = ?
    ");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stats['reviews'] = (int) ($row['c'] ?? 0);
    $stats['avg']     = $row['a'] !== null ? round((float)$row['a'], 2) : 0.0;
    $stmt->close();
} catch (Exception $e) {
    // Non-fatal
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $_SESSION['dashboard_message'] = "Security check failed. Please try again.";
        header("Location: turf_owner_profile.php");
        exit;
    }

    if ($action === 'update_profile') {
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = sanitize_phone($_POST['contact_no'] ?? '');

        // Basic validation
        $errs = [];
        if ($name === '' || mb_strlen($name) > 255) $errs[] = "Name is required and must be under 255 chars.";
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = "Valid email is required.";
        if ($phone !== '' && mb_strlen($phone) > 20) $errs[] = "Contact number must be 20 characters or fewer.";

        // Uniqueness (email) across users table except current owner
        if (!$errs) {
            try {
                // Check email uniqueness in the users table (for turf_owner role)
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? AND role = 'turf_owner' LIMIT 1");
                $stmt->bind_param("si", $email, $owner_id);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) $errs[] = "Email is already in use.";
            } catch (Exception $e) {
                $errs[] = "Validation error: ".$e->getMessage();
            }
        }

        if ($errs) {
            $_SESSION['dashboard_message'] = implode(" ", array_map('htmlspecialchars', $errs));
            header("Location: turf_owner_profile.php");
            exit;
        }

        // Update both users table
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, contact_no = ? WHERE id = ? AND role = 'turf_owner'");
            $stmt->bind_param("sssi", $name, $email, $phone, $owner_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $_SESSION['dashboard_message'] = "Profile updated successfully.";
            header("Location: turf_owner_profile.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dashboard_message'] = "Failed to update profile: ".htmlspecialchars($e->getMessage());
            header("Location: turf_owner_profile.php");
            exit;
        }

    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new1    = (string)($_POST['new_password'] ?? '');
        $new2    = (string)($_POST['confirm_password'] ?? '');

        $errs = [];
        if ($new1 === '' || mb_strlen($new1) < 8) $errs[] = "New password must be at least 8 characters.";
        if ($new1 !== $new2) $errs[] = "New password and confirm password do not match.";

        // Verify current password (check against users table hash)
        if (!$errs) {
            if (!password_verify($current, $owner['password'])) {
                $errs[] = "Current password is incorrect.";
            }
        }

        if ($errs) {
            $_SESSION['dashboard_message'] = implode(" ", array_map('htmlspecialchars', $errs));
            header("Location: turf_owner_profile.php");
            exit;
        }

        // Update password hashes in users table
        try {
            $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 10]);

            $conn->begin_transaction();

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'turf_owner'");
            $stmt->bind_param("si", $hash, $owner_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $_SESSION['dashboard_message'] = "Password changed successfully.";
            header("Location: turf_owner_profile.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dashboard_message'] = "Failed to change password: ".htmlspecialchars($e->getMessage());
            header("Location: turf_owner_profile.php");
            exit;
        }
    }
}

// Refresh owner after any changes (display latest)
try {
    $stmt = $conn->prepare("SELECT id, name, email, contact_no, created_at FROM users WHERE id = ? AND role = 'turf_owner'");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    // ignore; already loaded earlier
}

$page_title = "Profile - Cage Cricket";
ob_start();
?>

<div class="container-fluid fade-in" style="max-width: 1000px;">
  <div class="dashboard-header">
    <h1 class="fw-bold">
      <i class="bi bi-person-circle me-2"></i> My Profile
    </h1>
  </div>

  <!-- Session message -->
  <?php if (isset($_SESSION['dashboard_message'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <?php echo htmlspecialchars($_SESSION['dashboard_message']); unset($_SESSION['dashboard_message']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Turfs</div>
            <div class="h4 m-0 text-success"><?php echo (int)$stats['turfs']; ?></div>
          </div>
          <i class="bi bi-houses fs-2 text-success"></i>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Total Reviews</div>
            <div class="h4 m-0 text-success"><?php echo (int)$stats['reviews']; ?></div>
          </div>
          <i class="bi bi-chat-dots fs-2 text-success"></i>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Avg Rating</div>
            <div class="h4 m-0 text-success"><?php echo number_format($stats['avg'], 2); ?> / 5</div>
          </div>
          <div>
            <?php
              $full = floor($stats['avg']);
              $half = ($stats['avg'] - $full) >= 0.5 ? 1 : 0;
              $empty = 5 - $full - $half;
              for ($i=0; $i<$full; $i++) echo '<i class="bi bi-star-fill text-warning fs-4"></i>';
              if ($half) echo '<i class="bi bi-star-half text-warning fs-4"></i>';
              for ($i=0; $i<$empty; $i++) echo '<i class="bi bi-star text-warning fs-4"></i>';
            ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Profile + Security -->
  <div class="row g-4">
    <!-- Profile Details -->
    <div class="col-lg-7">
      <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-success text-white">
          <h2 class="h5 fw-semibold mb-0"><i class="bi bi-person-lines-fill"></i> Profile Details</h2>
        </div>
        <div class="card-body">
          <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="mb-3">
              <label class="form-label">Owner ID</label>
              <input type="text" class="form-control" value="<?php echo (int)$owner['id']; ?>" readonly>
            </div>

            <div class="mb-3">
              <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" id="name" name="name" class="form-control" maxlength="255"
                     value="<?php echo htmlspecialchars((string)$owner['name']); ?>" required>
            </div>

            <div class="mb-3">
              <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" id="email" name="email" class="form-control" maxlength="255"
                     value="<?php echo htmlspecialchars((string)$owner['email']); ?>" required>
            </div>

            <div class="mb-3">
              <label for="contact_no" class="form-label">Contact Number</label>
              <input type="text" id="contact_no" name="contact_no" class="form-control" maxlength="20"
                     value="<?php echo htmlspecialchars((string)($owner['contact_no'] ?? '')); ?>" placeholder="+91XXXXXXXXXX">
            </div>

            <div class="mb-3">
              <label class="form-label">Member Since</label>
              <input type="text" class="form-control" value="<?php
                  $dt = new DateTime($owner['created_at'], new DateTimeZone('UTC'));
                  $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                  echo $dt->format('Y-m-d H:i');
              ?>" readonly>
            </div>

            <div class="text-end">
              <button type="submit" class="btn btn-success hover-scale">
                <i class="bi bi-check2-circle"></i> Save Changes
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Security -->
    <div class="col-lg-5">
      <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-success text-white">
          <h2 class="h5 fw-semibold mb-0"><i class="bi bi-shield-lock"></i> Security</h2>
        </div>
        <div class="card-body">
          <form method="POST" autocomplete="off" id="passwordForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="change_password">

            <!-- Current Password + Eye -->
            <div class="mb-3">
              <label for="current_password" class="form-label">Current Password</label>
              <div class="input-group">
                <input type="password" id="current_password" name="current_password" class="form-control" minlength="1" required autocomplete="current-password">
                <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="#current_password" aria-label="Show/Hide current password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- New Password + Eye -->
            <div class="mb-3">
              <label for="new_password" class="form-label">New Password <small class="text-muted">(min 8 chars)</small></label>
              <div class="input-group">
                <input type="password" id="new_password" name="new_password" class="form-control" minlength="8" required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="#new_password" aria-label="Show/Hide new password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text">Use at least 8 characters.</div>
            </div>

            <!-- Confirm Password + Eye + Live match state -->
            <div class="mb-3">
              <label for="confirm_password" class="form-label">Confirm New Password</label>
              <div class="input-group">
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="#confirm_password" aria-label="Show/Hide confirm password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div id="matchHelp" class="form-text"></div>
              <div class="invalid-feedback">Passwords do not match.</div>
              <div class="valid-feedback">Passwords match.</div>
            </div>

            <div class="text-end">
              <button type="submit" class="btn btn-outline-danger hover-scale">
                <i class="bi bi-key"></i> Change Password
              </button>
            </div>
          </form>

          <hr>
          <p class="small text-muted mb-0">
            Tip: Use a unique password you don’t reuse elsewhere. After changing your password, you’ll remain signed in.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="css/ratings_feedback.css"><!-- reuse your visual language -->
<script>
  // Auto-dismiss alerts after 10s
  document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
      setTimeout(function () {
        try { bootstrap.Alert.getOrCreateInstance(alert).close(); } catch(e){}
      }, 10000);
    });

    // Eye toggles
    document.querySelectorAll('.toggle-pass').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const targetInput = document.querySelector(this.dataset.target);
        const icon = this.querySelector('i');
        if (!targetInput) return;
        if (targetInput.type === 'password') {
          targetInput.type = 'text';
          icon.classList.remove('bi-eye');
          icon.classList.add('bi-eye-slash');
        } else {
          targetInput.type = 'password';
          icon.classList.remove('bi-eye-slash');
          icon.classList.add('bi-eye');
        }
      });
    });

    // Real-time password match validation
    const newPwd = document.getElementById('new_password');
    const confirmPwd = document.getElementById('confirm_password');
    const matchHelp = document.getElementById('matchHelp');
    const form = document.getElementById('passwordForm');

    function checkMatch() {
      const a = newPwd.value;
      const b = confirmPwd.value;

      // Only show states if confirm has something typed
      if (b.length === 0) {
        confirmPwd.classList.remove('is-valid', 'is-invalid');
        confirmPwd.setCustomValidity('');
        matchHelp.textContent = '';
        return;
      }

      if (a === b && a.length >= 8) {
        confirmPwd.classList.add('is-valid');
        confirmPwd.classList.remove('is-invalid');
        confirmPwd.setCustomValidity('');
        matchHelp.textContent = 'Passwords match.';
      } else {
        confirmPwd.classList.add('is-invalid');
        confirmPwd.classList.remove('is-valid');
        confirmPwd.setCustomValidity('Passwords do not match');
        matchHelp.textContent = 'Make sure both passwords are identical and at least 8 characters.';
      }
    }

    newPwd.addEventListener('input', checkMatch);
    confirmPwd.addEventListener('input', checkMatch);

    // Prevent submit if invalid
    form.addEventListener('submit', function (e) {
      checkMatch();
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    });
  });
</script>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>    
