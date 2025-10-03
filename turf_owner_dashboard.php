<?php
session_start();
require 'config.php';
date_default_timezone_set('Asia/Kolkata');

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Only turf owners allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'turf_owner') {
    error_log("Unauthorized access attempt: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$name    = $_SESSION['name'] ?? '';

// Fetch owner name if missing
if (empty($name)) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'turf_owner'");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $name = $user['name'];
                $_SESSION['name'] = $name;
            }
        }
        $stmt->close();
    }
}

// Flash message
$message = '';
if (isset($_SESSION['success_message'])) {
    $alert_class = (str_contains($_SESSION['success_message'], 'Error') || str_contains($_SESSION['success_message'], 'Invalid') || str_contains($_SESSION['success_message'], 'Cannot')) ? 'alert-danger' : 'alert-success';
    $message = "<div id='form-alert' class='alert $alert_class alert-dismissible fade show text-center' role='alert'>" . htmlspecialchars($_SESSION['success_message']) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    unset($_SESSION['success_message']);
}

// Fetch turfs owned by logged-in owner
$turfs = [];
$stmt  = $conn->prepare("SELECT * FROM turfs WHERE owner_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $turfs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Pending bookings (fixed JOIN with role filter)
$pending_bookings = [];
$stmt = $conn->prepare("
    SELECT b.id, b.booking_name, b.date, b.start_time, b.end_time, b.total_audience, b.total_cost,
           t.turf_name, u.name AS organizer_name
    FROM bookings b
    JOIN turfs t ON b.turf_id = t.turf_id
    JOIN users u ON b.organizer_id = u.id AND u.role = 'team_organizer'
    WHERE t.owner_id = ? AND b.status = 'pending'
    ORDER BY b.date ASC, b.start_time ASC
");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pending_bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Earnings calculation
$total_earnings       = 0;
$paid_bookings_count  = 0;
$stmt = $conn->prepare("
    SELECT
      COALESCE(SUM(b.total_cost), 0) AS total_earnings,
      COUNT(*) AS paid_bookings
    FROM bookings b
    JOIN turfs t ON b.turf_id = t.turf_id
    INNER JOIN (
        SELECT p.booking_id
        FROM payments p
        JOIN (
            SELECT booking_id, MAX(updated_at) AS max_updated
            FROM payments
            GROUP BY booking_id
        ) last ON last.booking_id = p.booking_id AND last.max_updated = p.updated_at
        WHERE p.payment_status = 'completed'

        UNION

        SELECT b2.id AS booking_id
        FROM bookings b2
        LEFT JOIN payments p2 ON p2.booking_id = b2.id
        WHERE p2.id IS NULL AND b2.payment_status = 'paid'
    ) paid ON paid.booking_id = b.id
    WHERE t.owner_id = ?
");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $total_earnings      = (float)$row['total_earnings'];
            $paid_bookings_count = (int)$row['paid_bookings'];
        }
    }
    $stmt->close();
}

$page_title = "Turf Owner Dashboard - Cage Cricket";

// File checks
$missing_files = [];
if (!file_exists('css/turf_owner_dashboard.css')) $missing_files[] = "Stylesheet";
if (!file_exists('js/turf_owner_dashboard.js')) $missing_files[] = "JavaScript";
if (!file_exists('process_booking.php')) $missing_files[] = "Booking processor";
if (!empty($missing_files)) {
    $message .= "<div id='form-alert' class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error loading: " . implode(", ", $missing_files) . ". Please contact support.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
}

// ❌ REMOVE this line (caused duplicate styles outside template)
// echo '<link rel="stylesheet" href="css/turf_owner_dashboard.css">';

ob_start();
?>

<div class="container-fluid fade-in">
    <!-- Header -->
    <div class="text-center mb-5">
        <h1 class="display-4 fw-bold text-success mb-3">Welcome, <?php echo htmlspecialchars($name); ?>!</h1>
        <p class="lead text-muted">Manage your Cage Cricket empire with ease.</p>
        <?php if ($message) echo $message; ?>
    </div>

    <!-- Summary Cards -->
    <div class="row row-cols-1 row-cols-md-4 g-4 mb-5">
        <!-- Total Earnings -->
        <div class="col">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-body text-center">
                    <div class="icon-circle"><i class="bi bi-currency-rupee"></i></div>
                    <h3 class="card-title mt-3 fw-semibold">Total Earnings</h3>
                    <p class="card-text display-6 fw-bold text-success">₹<?php echo number_format($total_earnings, 2); ?></p>
                </div>
            </div>
        </div>
        <!-- Paid Bookings -->
        <div class="col">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-body text-center">
                    <div class="icon-circle"><i class="bi bi-calendar-check"></i></div>
                    <h3 class="card-title mt-3 fw-semibold">Paid Bookings</h3>
                    <p class="card-text display-6 fw-bold text-success"><?php echo $paid_bookings_count; ?></p>
                </div>
            </div>
        </div>
        <!-- Active Turfs -->
        <div class="col">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-body text-center">
                    <div class="icon-circle"><i class="bi bi-building-check"></i></div>
                    <h3 class="card-title mt-3 fw-semibold">Active Turfs</h3>
                    <p class="card-text display-6 fw-bold text-success"><?php echo count(array_filter($turfs, fn($t) => $t['is_active'])); ?></p>
                </div>
            </div>
        </div>
        <!-- Pending Bookings -->
        <div class="col">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-body text-center">
                    <div class="icon-circle"><i class="bi bi-clock-history"></i></div>
                    <h3 class="card-title mt-3 fw-semibold">Pending Bookings</h3>
                    <p class="card-text display-6 fw-bold text-success"><?php echo count($pending_bookings); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Booking Table -->
    <div class="card shadow-sm border-0 rounded-3 mb-5">
        <div class="card-header bg-success text-white">
            <h2 class="h4 fw-semibold mb-0">Pending Booking Requests</h2>
        </div>
        <div class="card-body">
            <?php if (count($pending_bookings) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-success">
                            <tr>
                                <th class="p-3">Turf Name</th>
                                <th class="p-3">Booking Name</th>
                                <th class="p-3">Organizer</th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Time</th>
                                <th class="p-3">Capacity</th>
                                <th class="p-3">Cost (₹)</th>
                                <th class="p-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_bookings as $index => $booking): ?>
                                <tr class="fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                    <td class="p-3"><?php echo htmlspecialchars($booking['turf_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['booking_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['organizer_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['date']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['start_time']) . ' - ' . htmlspecialchars($booking['end_time']); ?></td>
                                    <td class="p-3"><?php echo (int)$booking['total_audience']; ?></td>
                                    <td class="p-3">₹<?php echo number_format($booking['total_cost'], 2); ?></td>
                                    <td class="p-3">
                                        <?php
                                        $nowTs = time();
                                        $startTs = strtotime($booking['date'] . ' ' . $booking['start_time']);
                                        $canAct = ($nowTs < $startTs);
                                        ?>
                                        <?php if ($canAct): ?>
                                            <form method="POST" action="process_booking.php" class="d-inline" data-confirm>
                                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm px-3 hover-scale" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Approve this booking">Approve</button>
                                            </form>
                                            <form method="POST" action="process_booking.php" class="d-inline" data-confirm>
                                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm px-3 hover-scale" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Reject this booking">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-secondary" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Start time has passed">Actions closed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted fst-italic text-center p-4">No pending booking requests at the moment.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="js/turf_owner_dashboard.js"></script>

<?php
if (!file_exists('turf_owner_template.php')) {
    error_log("Template file not found: turf_owner_template.php");
    die("Template file not found.");
}
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
