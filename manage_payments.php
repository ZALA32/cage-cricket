<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// CSRF Token Generation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: manage_payments.php?error=" . urlencode("Invalid CSRF token."));
        exit;
    }
    $payment_id = (int)$_POST['delete_payment_id'];

    // Check if associated booking is active
    $stmt = $conn->prepare("SELECT b.status FROM bookings b JOIN payments p ON b.id = p.booking_id WHERE p.id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        header("Location: manage_payments.php?error=" . urlencode("Database error: Unable to check booking status."));
        exit;
    }
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($result && $result['status'] !== 'cancelled') {
        header("Location: manage_payments.php?error=" . urlencode("Cannot delete payment: Associated booking is still active."));
        exit;
    }

    // Begin transaction to delete payment
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $stmt->close();

        // Notify organizer (users table)
        $stmt = $conn->prepare("SELECT b.organizer_id FROM payments p JOIN bookings b ON p.booking_id = b.id WHERE p.id = ?");
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $organizer_id = $stmt->get_result()->fetch_assoc()['organizer_id'];
        $stmt->close();
        $message = "Payment (ID: $payment_id) has been deleted by the admin.";
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $organizer_id, $message);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: manage_payments.php?success=" . urlencode("Payment deleted successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete failed: " . $e->getMessage());
        header("Location: manage_payments.php?error=" . urlencode("Failed to delete payment: " . $e->getMessage()));
    }
    exit;
}

// Handle Status Update (including Refund)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: manage_payments.php?error=" . urlencode("Invalid CSRF token."));
        exit;
    }
    $payment_id = (int)$_POST['update_payment_id'];
    $payment_status = trim($_POST['payment_status']);
    $valid_statuses = ['pending', 'completed', 'failed', 'refunded'];

    if (!in_array($payment_status, $valid_statuses)) {
        header("Location: manage_payments.php?error=" . urlencode("Invalid payment status selected."));
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE payments SET payment_status = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("si", $payment_status, $payment_id);
        $stmt->execute();
        $stmt->close();

        // Notify organizer (users table)
        $stmt = $conn->prepare("SELECT b.organizer_id FROM payments p JOIN bookings b ON p.booking_id = b.id WHERE p.id = ?");
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $organizer_id = $stmt->get_result()->fetch_assoc()['organizer_id'];
        $stmt->close();
        $message = "Payment (ID: $payment_id) status updated to $payment_status.";
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $organizer_id, $message);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: manage_payments.php?success=" . urlencode("Payment status updated successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update failed: " . $e->getMessage());
        header("Location: manage_payments.php?error=" . urlencode("Failed to update payment status: " . $e->getMessage()));
    }
    exit;
}

// Pagination Settings
$payments_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $payments_per_page;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$where_clauses = [];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(p.transaction_id LIKE ? OR b.booking_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($status_filter) {
    $where_clauses[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get Total Payments for Pagination
$count_query = "SELECT COUNT(*) as total FROM payments p JOIN bookings b ON p.booking_id = b.id $where_sql";
$count_stmt = $conn->prepare($count_query);
if (!$count_stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch payment count.");
}
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_payments = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_payments / $payments_per_page));

// Fetch Payments with Pagination
$query = "SELECT p.id, p.booking_id, p.payment_status, p.payment_method, p.transaction_id, p.created_at, p.updated_at, 
          b.booking_name, b.total_cost 
          FROM payments p 
          JOIN bookings b ON p.booking_id = b.id 
          $where_sql 
          ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch payments.");
}
$types .= "ii";
$params[] = $payments_per_page;
$params[] = $offset;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status Options for Filter
$statuses = ['pending', 'completed', 'failed', 'refunded'];

// Main Content Buffer Start
ob_start();
?>

<!-- Manage Payments -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Manage Payments</h1>
        <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back to Dashboard</a>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show text-center" role="alert" aria-live="assertive">
            <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center" role="alert" aria-live="assertive">
            <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by transaction ID or booking name" value="<?= htmlspecialchars($search) ?>" aria-label="Search payments">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select" aria-label="Filter by payment status">
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

    <!-- Payments Table -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Payments List</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" aria-label="List of payments">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Booking Name</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Transaction ID</th>
                            <th>Status</th>
                            <th>Created On</th>
                            <th>Updated On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No payments found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= htmlspecialchars($payment['id']) ?></td>
                                    <td><?= htmlspecialchars($payment['booking_name']) ?></td>
                                    <td>₹<?= number_format($payment['total_cost'], 2) ?></td>
                                    <td><?= htmlspecialchars($payment['payment_method'] ?: 'N/A') ?></td>
                                    <td><?= htmlspecialchars($payment['transaction_id'] ?: 'N/A') ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="update_payment_id" value="<?= $payment['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <select name="payment_status" class="form-select form-select-sm d-inline-block w-auto" aria-label="Update payment status" onchange="this.form.submit()">
                                                <?php foreach ($statuses as $status_option): ?>
                                                    <option value="<?= $status_option ?>" <?= $payment['payment_status'] === $status_option ? 'selected' : '' ?>>
                                                        <?= ucfirst($status_option) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($payment['created_at']) ?></td>
                                    <td><?= htmlspecialchars($payment['updated_at'] ?: 'N/A') ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_payment_id" value="<?= $payment['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete the payment for booking <?= htmlspecialchars($payment['booking_name']) ?>? This action cannot be undone.');">
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
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- Custom Styles -->
<style>
    .table-hover tbody tr:hover {
        background-color: #f8f9fa;
    }
    .form-control, .form-select {
        border: 2px solid #198754;
        border-radius: 8px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #145c38;
        box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
        outline: none;
    }
    .btn-success, .btn-outline-danger {
        font-weight: 500;
        border-radius: 6px;
    }
    .btn-success:hover {
        background-color: #157347;
    }
    .btn-outline-danger:hover {
        background-color: #dc3545;
        color: white;
    }
    .pagination .page-link {
        color: #198754;
    }
    .pagination .page-item.active .page-link {
        background-color: #198754;
        border-color: #198754;
    }
</style>

<!-- JavaScript for Auto-Dismiss Alerts -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert && alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 4000);
    });
});
</script>

<?php
$conn->close();
$page_content = ob_get_clean();
$page_title = "Manage Payments - Admin Panel";
include 'admin_template.php';
?>