<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Toggle Active Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_turf_id']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $turf_id = (int)$_POST['toggle_turf_id'];
    $is_active = (int)$_POST['is_active'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE turfs SET is_active = ? WHERE turf_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ii", $is_active, $turf_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            throw new Exception("No rows updated: Turf ID $turf_id may not exist.");
        }
        $stmt->close();
        $conn->commit();
        header("Location: manage_turfs.php?success=" . urlencode("Turf status updated successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Toggle status failed for turf_id $turf_id: " . $e->getMessage());
        header("Location: manage_turfs.php?error=" . urlencode("Failed to update turf status: " . $e->getMessage()));
    }
    exit;
}

// Handle Single Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_turf_id']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $turf_id = (int)$_POST['delete_turf_id'];

    // Check for dependencies (bookings)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE turf_id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        header("Location: manage_turfs.php?error=" . urlencode("Database error: Unable to check bookings."));
        exit;
    }
    $stmt->bind_param("i", $turf_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result['count'] > 0) {
        header("Location: manage_turfs.php?error=" . urlencode("Cannot delete turf: It has " . $result['count'] . " booking(s) associated."));
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Begin transaction to delete turf
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM turfs WHERE turf_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $turf_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        header("Location: manage_turfs.php?success=" . urlencode("Turf deleted successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete failed: " . $e->getMessage());
        header("Location: manage_turfs.php?error=" . urlencode("Failed to delete turf: " . $e->getMessage()));
    }
    exit;
}

// Pagination Settings
$turfs_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $turfs_per_page;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$is_active_filter = isset($_GET['is_active']) ? trim($_GET['is_active']) : '';
$where_clauses = [];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(t.turf_name LIKE ? OR t.turf_email LIKE ? OR t.turf_address LIKE ? OR u.name LIKE ? OR t.turf_facility LIKE ? OR t.turf_description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if ($is_active_filter !== '') {
    $where_clauses[] = "t.is_active = ?";
    $params[] = (int)$is_active_filter;
    $types .= "i";
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=turfs_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Email', 'Contact', 'Owner', 'Capacity', 'Cost', 'Address', 'Facilities', 'Description', 'Photo', 'Status', 'Created On']);
    $query = "SELECT t.turf_id, t.turf_name, t.turf_email, t.turf_contact, u.name as owner_name, t.turf_capacity, t.booking_cost, t.turf_address, t.turf_facility, t.turf_description, t.turf_photo, t.is_active, t.created_at 
              FROM turfs t JOIN users u ON t.owner_id = u.id $where_sql 
              ORDER BY t.created_at DESC";
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['turf_id'],
            $row['turf_name'],
            $row['turf_email'],
            $row['turf_contact'],
            $row['owner_name'],
            $row['turf_capacity'],
            $row['booking_cost'],
            $row['turf_address'],
            $row['turf_facility'] ?: 'N/A',
            $row['turf_description'] ?: 'N/A',
            $row['turf_photo'] ?: 'N/A',
            $row['is_active'] ? 'Active' : 'Inactive',
            $row['created_at']
        ]);
    }
    fclose($output);
    $stmt->close();
    exit;
}

// Get Total Turfs for Pagination
$count_query = "SELECT COUNT(*) as total FROM turfs t JOIN users u ON t.owner_id = u.id $where_sql";
$count_stmt = $conn->prepare($count_query);
if (!$count_stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch turf count.");
}
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_turfs = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_turfs / $turfs_per_page));

// Fetch Turfs with Pagination
$query = "SELECT t.turf_id, t.turf_name, t.turf_email, t.turf_contact, t.turf_capacity, t.booking_cost, t.is_active, t.created_at, t.turf_address, t.turf_facility, t.turf_description, t.turf_photo, u.name as owner_name 
          FROM turfs t JOIN users u ON t.owner_id = u.id $where_sql 
          ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $turfs_per_page;
$params[] = $offset;
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: Unable to fetch turfs.");
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$turfs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Main Content Buffer Start
ob_start();
?>

<!-- Manage Turfs -->
<div class="container-fluid p-4" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5 animate__animated animate__fadeIn">
        <h1 class="fw-bold text-dark display-5">Manage Turfs</h1>
        <div class="d-flex gap-3">
            <a href="?export=csv" class="btn btn-success btn-hover"><i class="bi bi-download me-2"></i>Export CSV</a>
            <a href="admin_add_turf.php" class="btn btn-success btn-hover"><i class="bi bi-plus-circle me-2"></i>Add New Turf</a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-hover"><i class="bi bi-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show text-center rounded-3 animate__animated animate__fadeIn" role="alert">
            <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show text-center rounded-3 animate__animated animate__fadeIn" role="alert">
            <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="card shadow-sm border-0 mb-5 rounded-3 animate__animated animate__fadeIn">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-success text-white rounded-start"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control rounded-end" placeholder="Search by name, email, address, owner, facilities, or description" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="is_active" class="form-select rounded">
                        <option value="">All Statuses</option>
                        <option value="1" <?= $is_active_filter === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $is_active_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100 btn-hover rounded">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Turfs Table -->
    <div class="card shadow-sm border-0 rounded-3 animate__animated animate__fadeInUp">
        <div class="card-header text-white fs-5 fw-semibold rounded-top" style="background: linear-gradient(135deg, #198754, #28a745);">
            Turfs List
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Owner</th>
                            <th>Capacity</th>
                            <th>Cost</th>
                            <th>Address</th>
                            <th>Facilities</th>
                            <th>Description</th>
                            <th>Photo</th>
                            <th>Status</th>
                            <th>Created On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($turfs)): ?>
                            <tr>
                                <td colspan="14" class="text-center text-muted">No turfs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($turfs as $turf): ?>
                                <tr>
                                    <td><?= htmlspecialchars($turf['turf_id']) ?></td>
                                    <td><?= htmlspecialchars($turf['turf_name']) ?></td>
                                    <td><?= htmlspecialchars($turf['turf_email']) ?></td>
                                    <td><?= htmlspecialchars($turf['turf_contact']) ?></td>
                                    <td><?= htmlspecialchars($turf['owner_name']) ?></td>
                                    <td><?= htmlspecialchars($turf['turf_capacity']) ?></td>
                                    <td>₹<?= number_format($turf['booking_cost'], 2) ?></td>
                                    <td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($turf['turf_address']) ?>">
                                        <?= htmlspecialchars($turf['turf_address']) ?>
                                    </td>
                                    <td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($turf['turf_facility'] ?: 'N/A') ?>">
                                        <?= htmlspecialchars($turf['turf_facility'] ?: 'N/A') ?>
                                    </td>
                                    <td class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($turf['turf_description'] ?: 'N/A') ?>">
                                        <?= htmlspecialchars($turf['turf_description'] ?: 'N/A') ?>
                                    </td>
                                    <td>
                                        <?php if ($turf['turf_photo'] && file_exists($turf['turf_photo'])): ?>
                                            <img src="<?= htmlspecialchars($turf['turf_photo']) ?>" class="img-fluid rounded" style="max-width: 40px;" alt="Turf Photo">
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="manage_turfs.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="toggle_turf_id" value="<?= $turf['turf_id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $turf['is_active'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-sm <?= $turf['is_active'] ? 'btn-success' : 'btn-warning' ?> btn-hover">
                                                <?= $turf['is_active'] ? 'Active' : 'Inactive' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($turf['created_at']) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <a href="admin_edit_turf.php?id=<?= $turf['turf_id'] ?>" class="btn btn-sm btn-outline-primary btn-hover">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <form method="POST" action="manage_turfs.php">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="delete_turf_id" value="<?= $turf['turf_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger btn-hover" onclick="return confirm('Are you sure you want to delete the turf <?= htmlspecialchars($turf['turf_name']) ?>? This action cannot be undone.');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
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
                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&is_active=<?= urlencode($is_active_filter) ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&is_active=<?= urlencode($is_active_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&is_active=<?= urlencode($is_active_filter) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- Custom Styles -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
    
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f4f7fa;
    }
    .card {
        border-radius: 12px;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    }
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
    .btn-success, .btn-outline-primary, .btn-outline-danger, .btn-warning {
        font-weight: 500;
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    .btn-success:hover {
        background-color: #157347;
    }
    .btn-outline-primary:hover {
        background-color: #0d6efd;
        color: white;
    }
    .btn-outline-danger:hover {
        background-color: #dc3545;
        color: white;
    }
    .btn-warning:hover {
        background-color: #e0a800;
        color: white;
    }
    .btn-hover {
        transition: transform 0.2s ease, background-color 0.3s ease;
    }
    .btn-hover:hover {
        transform: scale(1.05);
    }
    .pagination .page-link {
        color: #198754;
        border-radius: 6px;
        margin: 0 2px;
    }
    .pagination .page-item.active .page-link {
        background-color: #198754;
        border-color: #198754;
    }
    th:last-child, td:last-child {
        min-width: 160px;
    }
    .text-truncate {
        max-width: 150px;
    }
    .text-truncate[title] {
        cursor: pointer;
    }
    img.img-fluid {
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .alert {
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
        console.error('JavaScript error in manage_turfs.php:', error);
    }
});
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Manage Turfs - Admin Panel";
include 'admin_template.php';
?>