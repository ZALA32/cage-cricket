<?php
session_start();
require 'config.php';

// Ensure CSRF token exists before rendering any forms on this page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure time zone is IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle filters
$status_filter  = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter    = isset($_GET['date']) ? $_GET['date'] : '';
$turf_filter    = isset($_GET['turf_id']) ? intval($_GET['turf_id']) : 0;
$payment_filter = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';

$per_page = 10;
$page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset  = ($page - 1) * $per_page;

$where_conditions = ["t.owner_id = ?"];
$params = [$user_id];
$param_types = "i";

// Only apply is_active = 1 when a specific turf is selected
if ($turf_filter) {
    $where_conditions[] = "b.turf_id = ? AND t.is_active = 1";
    $params[] = $turf_filter;
    $param_types .= "i";
}

if ($status_filter !== 'all') {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if ($date_filter) {
    $where_conditions[] = "b.date = ?";
    $params[] = $date_filter;
    $param_types .= "s";
}

$payStatusExpr = "COALESCE(
    p.payment_status,
    CASE 
        WHEN b.payment_status = 'paid' THEN 'completed'
        WHEN b.payment_status = 'pending' THEN 'pending'
        ELSE b.payment_status
    END
)";

if ($payment_filter !== 'all') {
    $where_conditions[] = "$payStatusExpr = ?";
    $params[] = $payment_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Count total bookings for pagination
$count_query = "SELECT COUNT(*) as total 
                FROM bookings b 
                JOIN turfs t ON b.turf_id = t.turf_id 
                LEFT JOIN payments p ON b.id = p.booking_id 
                WHERE $where_clause";

try {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_bookings = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    $total_pages = ceil($total_bookings / $per_page);
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

// Fetch bookings (no logic change)
$bookings = [];
$query = "SELECT 
            b.*,
            t.turf_name,
            t.is_active,
            u.name AS organizer_name,  -- Corrected this line to fetch from users table (u.name)
            COALESCE(p.payment_method, 'cash') AS payment_method,
            $payStatusExpr AS pay_status,
            b.status AS display_status
          FROM bookings b
          JOIN turfs t ON b.turf_id = t.turf_id
          JOIN users u ON b.organizer_id = u.id  -- Replaced team_organizer with users table
          LEFT JOIN payments p ON b.id = p.booking_id
          WHERE $where_clause
          ORDER BY b.created_at DESC
          LIMIT ? OFFSET ?";
try {
    $stmt = $conn->prepare($query);
    $all_params = array_merge($params, [$per_page, $offset]);
    $stmt->bind_param($param_types . "ii", ...$all_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

// Fetch turfs for filter dropdown (only active turfs)
$turfs = [];
$query = "SELECT turf_id, turf_name FROM turfs WHERE owner_id = ? AND is_active = 1";
try {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $turfs[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

// Fetch chart data for payment methods (include all turfs)
$chart_data = ['cash' => 0, 'other' => 0];
$query = "SELECT COUNT(*) as count, COALESCE(p.payment_method, 'cash') as payment_method 
          FROM payments p 
          JOIN bookings b ON p.booking_id = b.id 
          JOIN turfs t ON b.turf_id = t.turf_id 
          WHERE t.owner_id = ? 
          GROUP BY p.payment_method";
try {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['payment_method'] === 'cash') {
            $chart_data['cash'] += $row['count'];
        } else {
            $chart_data['other'] += $row['count'];
        }
    }
    $stmt->close();
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

$page_title = "Manage Bookings - Cage Cricket";
ob_start();
?>

<div class="container-fluid fade-in" style="max-width: 1400px;">
    <div class="dashboard-header">
        <h1 class="fw-bold">
            <i class="bi bi-calendar3 me-2"></i>Manage Bookings
        </h1>
    </div>

    <!-- Display Session Messages -->
    <?php if (isset($_SESSION['dashboard_message'])) { ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['dashboard_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['dashboard_message']); ?>
    <?php } ?>

    <!-- Payment Method Chart -->
    <div class="card shadow-sm border-0 rounded-3 mb-5">
        <div class="card-header bg-success text-white">
            <h2 class="h4 fw-semibold mb-0"><i class="bi bi-pie-chart"></i> Payment Method Distribution</h2>
        </div>
        <div class="card-body">
            <canvas id="paymentChart" style="max-height: 300px;"></canvas>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 rounded-3 mb-5">
        <div class="card-header bg-success text-white">
            <h2 class="h4 fw-semibold mb-0">Filter Bookings</h2>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="turf_id" class="form-label">Turf</label>
                    <select name="turf_id" id="turf_id" class="form-select">
                        <option value="0" <?php echo $turf_filter === 0 ? 'selected' : ''; ?>>All Turfs</option>
                        <?php foreach ($turfs as $turf) { ?>
                            <option value="<?php echo $turf['turf_id']; ?>" <?php echo $turf_filter === $turf['turf_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($turf['turf_name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="payment_status" class="form-label">Payment Status</label>
                    <select name="payment_status" id="payment_status" class="form-select">
                        <option value="all" <?php echo $payment_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $payment_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $payment_filter === 'completed' ? 'selected' : ''; ?>>Paid</option>
                        <option value="refunded" <?php echo $payment_filter === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date" class="form-label">Date</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-success hover-scale">Filter</button>
                    <a href="turf_owner_manage_bookings.php" class="btn btn-outline-secondary hover-scale">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-success text-white">
            <h2 class="h4 fw-semibold mb-0"><i class="bi bi-list-task"></i> All Bookings</h2>
        </div>
        <div class="card-body">
            <?php if (count($bookings) > 0) { ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle booking-table">
                        <thead>
                            <tr>
                                <th class="p-3">Turf Name</th>
                                <th class="p-3">Booking Name</th>
                                <th class="p-3">Organizer</th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Time</th>
                                <th class="p-3">Capacity</th>
                                <th class="p-3">Cost (₹)</th>
                                <th class="p-3">Status</th>
                                <th class="p-3">Payment Status</th>
                                <th class="p-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $index => $booking) { ?>
                                <tr class="fade-in" style="animation-delay: <?php echo min($index * 0.1, 2); ?>s;">
                                    <td class="p-3">
                                        <?php echo htmlspecialchars($booking['turf_name']); ?>
                                        <?php if ((int)$booking['is_active'] === 0) { ?>
                                            <span class="badge bg-warning text-dark ms-2">Inactive</span>
                                        <?php } ?>
                                    </td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['booking_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['organizer_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($booking['date']); ?></td>
                                    <td class="p-3">
                                        <?php echo htmlspecialchars(substr($booking['start_time'], 0, 5)) . " - " . htmlspecialchars(substr($booking['end_time'], 0, 5)); ?>
                                    </td>
                                    <td class="p-3 text-center"><?php echo htmlspecialchars($booking['total_audience']); ?></td>
                                    <td class="p-3 text-center"><?php echo htmlspecialchars(number_format($booking['total_cost'], 2)); ?></td>
                                    <?php 
                                        $statusClass = in_array($booking['display_status'], ['confirmed', 'approved'])
                                            ? 'text-success'
                                            : (in_array($booking['display_status'], ['cancelled', 'rejected']) ? 'text-danger' : 'text-warning');
                                    ?>
                                    <td class="p-3">
                                        <span class="chip <?php echo $statusClass; ?>">
                                            <?php echo ucfirst(htmlspecialchars($booking['display_status'])); ?>
                                        </span>
                                    </td>

                                    <td class="p-3">
                                        <?php 
                                            $current_time = time();
                                            $booking_start_time = strtotime($booking['date'] . ' ' . $booking['start_time']);

                                            // Show cash confirm ONLY when: booking is confirmed, payment is cash & pending, and match has started
                                            if ($booking['display_status'] === 'confirmed'
                                                && $booking['pay_status'] === 'pending'
                                                && $booking['payment_method'] === 'cash'
                                                && $booking_start_time <= $current_time) {
                                                echo '<span class="chip text-info">To be paid at play</span>';
                                                ?>
                                                <form id="confirm-cash-form-<?php echo (int)$booking['id']; ?>" action="confirm_cash_collection.php" method="POST" style="display:inline;">
                                                    <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <button type="button" class="btn btn-sm btn-success ms-2 mt-1 confirm-cash-btn" data-booking-id="<?php echo (int)$booking['id']; ?>" data-bs-toggle="modal" data-bs-target="#confirmCashModal">Confirm Cash Collected</button>
                                                </form>
                                                <?php
                                            } else {
                                                $labels = [
                                                    'pending'   => 'Pending',
                                                    'completed' => 'Paid',
                                                    'refunded'  => 'Refunded'
                                                ];
                                                $text = $labels[$booking['pay_status']] ?? ucfirst($booking['pay_status']);

                                                $payClass = ($booking['pay_status'] === 'pending' && $booking['payment_method'] === 'cash')
                                                    ? 'text-info'
                                                    : ($booking['pay_status'] === 'completed'
                                                        ? 'text-success'
                                                        : ($booking['pay_status'] === 'refunded' ? 'text-danger' : 'text-warning'));
                                                echo '<span class="chip ' . $payClass . '">' . htmlspecialchars($text) . '</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="p-3">
    <?php
        // Show cancel only if not cancelled/rejected and before start time
        $nowTs   = time();
        $startTs = strtotime($booking['date'].' '.$booking['start_time']);
        $canCancel = ($nowTs < $startTs) && !in_array($booking['display_status'], ['cancelled','rejected']);

        if ($canCancel) {
            ?>
            <form id="cancel-booking-form-<?php echo (int)$booking['id']; ?>"
                  action="turf_owner_cancel_booking.php"
                  method="POST" style="display:inline;">
                <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="reason" value=""> <!-- filled via modal/JS -->
                <button type="button"
                        class="btn btn-outline-danger btn-sm cancel-booking-btn"
                        data-booking-id="<?php echo (int)$booking['id']; ?>"
                        data-booking-name="<?php echo htmlspecialchars($booking['booking_name']); ?>"
                        data-booking-date="<?php echo htmlspecialchars($booking['date']); ?>"
                        data-bs-toggle="modal"
                        data-bs-target="#cancelBookingModal">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </form>
            <?php
        } else {
            echo '<span class="text-muted small">—</span>';
        }
    ?>
</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- Confirmation Modal -->
                <div class="modal fade" id="confirmCashModal" tabindex="-1" aria-labelledby="confirmCashModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="confirmCashModalLabel">Confirm Cash Collection</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                Are you sure you want to confirm cash collection for this booking?
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" id="confirmCashSubmit">Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>
             <!-- Cancel Booking Modal -->
            <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
                <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="cancelBookingModalLabel">Cancel Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                    You’re cancelling <strong id="cb-booking-name">—</strong> scheduled on <strong id="cb-booking-date">—</strong>.
                    </p>
                    <div class="mb-3">
                    <label for="cb-reason" class="form-label">Reason for cancellation <span class="text-danger">*</span></label>
                    <textarea id="cb-reason" class="form-control" rows="3" placeholder="e.g., Turf maintenance issue, weather, double booking, etc." required></textarea>
                    <div class="form-text">This will be sent to the organizer.</div>
                    </div>
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <div>Cancellation is not allowed after the match start time.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" id="cancelBookingSubmit">
                    <i class="bi bi-x-circle"></i> Confirm Cancel
                    </button>
                </div>
                </div>
            </div>
            </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>&turf_id=<?php echo $turf_filter; ?>&payment_status=<?php echo urlencode($payment_filter); ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++) { ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>&turf_id=<?php echo $turf_filter; ?>&payment_status=<?php echo urlencode($payment_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php } ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>&turf_id=<?php echo $turf_filter; ?>&payment_status=<?php echo urlencode($payment_filter); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php } else { ?>
                <p class="text-muted fst-italic text-center p-4">No bookings found matching the selected filters.</p>
            <?php } ?>
        </div>
    </div>
</div>

<link rel="stylesheet" href="css/turf_owner_manage_bookings.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    window.chartData = <?php echo json_encode($chart_data); ?>;
</script>
<script src="js/turf_owner_manage_bookings.js"></script>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
