<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $user_id = (int)$_POST['delete_user_id'];

    // Fetch the user's role
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        header("Location: manage_users.php?error=" . urlencode("User not found."));
        exit;
    }

    $role = $user['role'];
    $dependencies = [];

    // Prevent deletion of admin users
    if ($role === 'admin') {
        header("Location: manage_users.php?error=" . urlencode("Cannot delete an admin user."));
        exit;
    }

    // Check dependencies based on role
    if ($role === 'turf_owner') {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM turfs WHERE owner_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result['count'] > 0) {
            $dependencies[] = $result['count'] . " turf(s)";
        }
        $stmt->close();
    } elseif ($role === 'team_organizer') {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE organizer_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result['count'] > 0) {
            $dependencies[] = $result['count'] . " booking(s)";
        }
        $stmt->close();

    }

    if (!empty($dependencies)) {
        header("Location: manage_users.php?error=" . urlencode("Cannot delete user because they are associated with: " . implode(", ", $dependencies)));
        exit;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Delete from users
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: manage_users.php?success=" . urlencode("User deleted successfully."));
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete failed: " . $e->getMessage());
        header("Location: manage_users.php?error=" . urlencode("Failed to delete user: " . $e->getMessage()));
    }
    exit;
}

// Pagination Settings
$users_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $users_per_page;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : '';
$where_clauses = [];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(name LIKE ? OR email LIKE ? OR role LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($role_filter) {
    $where_clauses[] = "role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get Total Users for Pagination
$count_query = "SELECT COUNT(*) as total FROM users $where_sql";
$count_stmt = $conn->prepare($count_query);
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_users = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_users / $users_per_page));

// Fetch Users with Pagination
$query = "SELECT id, name, email, role, created_at, status 
          FROM users $where_sql 
          ORDER BY role, created_at DESC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$types_page = $types . "ii";
$params_page = array_merge($params, [$users_per_page, $offset]);
$stmt->bind_param($types_page, ...$params_page);
$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$roles = ['admin', 'turf_owner', "team_organizer", 'coach'];

ob_start();
?>

<!-- Manage Users -->
<div class="container-fluid p-5" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Manage Users</h1>
        <div>
            <a href="add_user.php" class="btn btn-success me-2"><i class="bi bi-person-plus me-2"></i>Add New User</a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
    </div>

    <!-- Messages -->
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

    <!-- Search & Filter -->
    <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, email, or role" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $role_option): ?>
                            <option value="<?= $role_option ?>" <?= $role_filter === $role_option ? 'selected' : '' ?>>
                                <?= ucfirst($role_option) ?>
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

    <!-- Users Table -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Users List</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No users found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['id']) ?></td>
                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
                                    <td>
                                        <span class="badge <?= $user['status'] === 'verified' ? 'bg-success' : 'bg-warning' ?>">
                                            <?= ucfirst(htmlspecialchars($user['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($user['created_at']) ?></td>
                                    <td>
                                        <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <?php if ($user['role'] !== 'admin'): ?>
                                            <form method="POST" action="manage_users.php" style="display:inline;">
                                                <input type="hidden" name="delete_user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete <?= htmlspecialchars($user['name']) ?>? This cannot be undone.');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash"></i> Delete</button>
                                        <?php endif; ?>
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
                    <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>">Previous</a>
                </li>
                <?php for ($i=1;$i<=$total_pages;$i++): ?>
                    <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<style>
.table-hover tbody tr:hover { background-color:#f8f9fa; }
.form-control,.form-select{ border:2px solid #198754; border-radius:8px; }
.form-control:focus,.form-select:focus{ border-color:#145c38; box-shadow:0 0 0 .25rem rgba(25,135,84,.25); }
.btn-success,.btn-outline-primary,.btn-outline-danger{ font-weight:500; border-radius:6px; }
.btn-success:hover{ background:#157347; }
.btn-outline-primary:hover{ background:#0d6efd; color:#fff; }
.btn-outline-danger:hover{ background:#dc3545; color:#fff; }
.pagination .page-link{ color:#198754; }
.pagination .active .page-link{ background:#198754; border-color:#198754; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert').forEach(alert=>{
        setTimeout(()=>{ alert.classList.remove('show'); alert.classList.add('fade'); },4000);
    });
});
</script>

<?php
$page_content = ob_get_clean();
$page_title = "Manage Users - Admin Panel";
include 'admin_template.php';
?>
