<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking_id'])) {
    $booking_id = (int)$_POST['delete_booking_id'];

    // Check for dependencies (payments)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM payments WHERE booking_id = ? AND payment_status = 'completed'");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        header("Location: manage_bookings.php?error=" . urlencode("Database error: Unable to check payments."));
        exit;
    }
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result['count'] > 0) {
        header("Location: manage_bookings.php?error=" . urlencode("Cannot delete booking: It has " . $result['count'] . " completed payment(s)."));
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Begin transaction to delete booking
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        header("Location: manage_bookings.php?success=" . urlencode("Booking deleted successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete failed: " . $e->getMessage());
        header("Location: manage_bookings.php?error=" . urlencode("Failed to delete booking: " . $e->getMessage()));
    }
    exit;
}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking_id'])) {
    $booking_id = (int)$_POST['update_booking_id'];
    $status = trim($_POST['status']);
    $valid_statuses = ['pending', 'confirmed', 'cancelled', 'approved'];

    if (!in_array($status, $valid_statuses)) {
        header("Location: manage_bookings.php?error=" . urlencode("Invalid status selected."));
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("si", $status, $booking_id);
        $stmt->execute();
        $stmt->close();

        // Add notification for organizer (users table)
        $stmt = $conn->prepare("SELECT b.organizer_id AS user_id, u.name
                                FROM bookings b
                                JOIN users u ON b.organizer_id = u.id
                                WHERE b.id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $organizer_user_id = $stmt->get_result()->fetch_assoc()['user_id'];
        $stmt->close();

        $message = "Your booking (ID: $booking_id) has been $status.";
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $organizer_user_id, $message);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: manage_bookings.php?success=" . urlencode("Booking status updated successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update failed: " . $e->getMessage());
        header("Location: manage_bookings.php?error=" . urlencode("Failed to update booking status: " . $e->getMessage()));
    }
    exit;
}

// Pagination Settings
$bookings_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $bookings_per_page;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$where_clauses = [];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(b.booking_name LIKE ? OR u.email LIKE ? OR u.name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($status_filter) {
    $where_clauses[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get Total Bookings for Pagination
$count_query = "SELECT COUNT(*) as total FROM bookings b
                JOIN turfs t ON b.turf_id = t.turf_id
                JOIN users u ON b.organizer_id = u.id
                $where_sql";
$count_stmt = $conn->prepare($count_query);
if (!$count_stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch booking count.");
}
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_bookings = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_bookings / $bookings_per_page));

// Fetch Bookings with Pagination
$query = "SELECT b.id, b.booking_name, b.date, b.start_time, b.end_time,
          b.total_audience, b.total_cost, b.status, b.created_at, t.turf_name,
          u.name AS organizer_name, u.email AS organizer_email, p.payment_status, p.payment_method
          FROM bookings b
          LEFT JOIN turfs t ON b.turf_id = t.turf_id
          LEFT JOIN users u ON b.organizer_id = u.id
          LEFT JOIN payments p ON b.id = p.booking_id
          $where_sql
          ORDER BY b.created_at DESC
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch bookings.");
}
$types .= "ii";
$params[] = $bookings_per_page;
$params[] = $offset;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status Options for Filter
$statuses = ['pending', 'confirmed', 'cancelled', 'approved'];

ob_start();
?>

<!-- Manage Bookings -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Manage Bookings</h1>
        <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back to Dashboard</a>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show text-center" role="alert">
            <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center" role="alert">
            <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by booking name or organizer email" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status_option): ?>
                            <option value="<?= $status_option ?>" <?= $status_filter === $status_option ? 'selected' : '' ?>>
                                <?= ucfirst($status_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Bookings List</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Booking Name</th>
                            <th>Turf</th>
                            <th>Organizer</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Audience</th>
                            <th>Cost</th>
                            <th>Status</th>
                            <th>Created On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">No bookings found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td><?= htmlspecialchars($booking['id']) ?></td>
                                    <td><?= htmlspecialchars($booking['booking_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['turf_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['organizer_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['date']) ?></td>
                                    <td><?= htmlspecialchars($booking['start_time']) ?> - <?= htmlspecialchars($booking['end_time']) ?></td>
                                    <td><?= htmlspecialchars($booking['total_audience']) ?></td>
                                    <td>₹<?= number_format($booking['total_cost'], 2) ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="update_booking_id" value="<?= $booking['id'] ?>">
                                            <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                <?php foreach ($statuses as $status_option): ?>
                                                    <option value="<?= $status_option ?>" <?= $booking['status'] === $status_option ? 'selected' : '' ?>>
                                                        <?= ucfirst($status_option) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($booking['created_at']) ?></td>
                                    <td>
                                        <a href="edit_booking.php?id=<?= $booking['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_booking_id" value="<?= $booking['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete the booking <?= htmlspecialchars($booking['booking_name']) ?>? This action cannot be undone.');">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation" class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;    ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- Custom Styles -->
<style>
    .table-hover tbody tr:hover { background-color: #f8f9fa; }
    .form-control, .form-select { border: 2px solid #198754; border-radius: 8px; transition: border-color 0.3s ease, box-shadow 0.3s ease; }
    .form-control:focus, .form-select:focus { border-color: #145c38; box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25); outline: none; }
    .btn-success, .btn-outline-primary, .btn-outline-danger { font-weight: 500; border-radius: 6px; }
    .btn-success:hover { background-color: #157347; }
    .btn-outline-primary:hover { background-color: #0d6efd; color: white; }
    .btn-outline-danger:hover { background-color: #dc3545; color: white; }
    .pagination .page-link { color: #198754; }
    .pagination .page-item.active .page-link { background-color: #198754; border-color: #198754; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => { alert.classList.remove('show'); alert.classList.add('fade'); }, 4000);
    });
});
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Manage Bookings - Admin Panel";
include 'admin_template.php';
?>
