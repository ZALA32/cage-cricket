<?php
session_start();
require 'config.php';

// Admin Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Count Function
function getCount($conn, $query, $param = null) {
    $stmt = $conn->prepare($query);
    if ($param) $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['total'] ?? 0;
}

// Counts
$users = getCount($conn, "SELECT COUNT(*) as total FROM users");
$turfs = getCount($conn, "SELECT COUNT(*) as total FROM turfs");
$bookings = getCount($conn, "SELECT COUNT(*) as total FROM bookings");
$payments = getCount($conn, "SELECT COUNT(*) as total FROM payments WHERE payment_status = ?", 'completed');

// Last 5 Bookings Activity ✅ FIXED JOIN
$activity_log = [];
$log_stmt = $conn->prepare("
    SELECT b.id, u.name, b.date AS booking_day 
    FROM bookings b
    JOIN users u ON b.organizer_id = u.id
    ORDER BY b.id DESC 
    LIMIT 5
");
$log_stmt->execute();
$log_result = $log_stmt->get_result();
while ($row = $log_result->fetch_assoc()) {
    $activity_log[] = $row;
}
$log_stmt->close();

// Weekly Bookings
$weekly_bookings = array_fill(0, 7, 0);
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('D', strtotime($date));
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE DATE(date) = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $weekly_bookings[6 - $i] = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

ob_start();
?>

<!-- Dashboard -->
<div class="container-fluid p-5" style="max-width: 1400px;">

    <!-- Welcome Header -->
    <div class="d-flex align-items-center justify-content-between mb-5">
        <h1 class="fw-bold text-dark display-5 animate__animated animate__fadeIn">Welcome, Admin!</h1>
        <span class="badge bg-secondary p-2 px-3 rounded-pill shadow-sm">
            <i class="bi bi-clock me-2"></i> Updated: <?= date('d M Y, H:i A') ?>
        </span>
    </div>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <?php
        $stats = [
            ['icon' => 'people', 'label' => 'Total Users', 'value' => $users],
            ['icon' => 'building', 'label' => 'Total Turfs', 'value' => $turfs],
            ['icon' => 'calendar-check', 'label' => 'Total Bookings', 'value' => $bookings],
            ['icon' => 'currency-rupee', 'label' => 'Payments Completed', 'value' => $payments]
        ];
        $delay = 0;
        foreach ($stats as $stat):
        ?>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm animate__animated animate__fadeInUp" style="animation-delay: <?= $delay ?>s;">
                    <div class="card-body text-center p-4">
                        <div class="icon-circle bg-success bg-gradient text-white mb-3 mx-auto shadow">
                            <i class="bi bi-<?= $stat['icon'] ?> fs-2"></i>
                        </div>
                        <h6 class="text-muted"><?= $stat['label'] ?></h6>
                        <h2 class="text-success fw-bold"><?= $stat['value'] ?></h2>
                    </div>
                </div>
            </div>
        <?php $delay += 0.1; endforeach; ?>
    </div>

    <!-- Manage Section -->
    <div class="card shadow-sm border-0 mb-5 animate__animated animate__fadeInUp">
        <div class="card-header bg-success text-white fs-5 fw-semibold">Quick Admin Actions</div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-md-3"><a href="manage_users.php" class="btn btn-outline-success w-100 py-3"><i class="bi bi-people me-2"></i>Manage Users</a></div>
                <div class="col-md-3"><a href="manage_turfs.php" class="btn btn-outline-success w-100 py-3"><i class="bi bi-building me-2"></i>Manage Turfs</a></div>
                <div class="col-md-3"><a href="manage_bookings.php" class="btn btn-outline-success w-100 py-3"><i class="bi bi-calendar-check me-2"></i>Manage Bookings</a></div>
                <div class="col-md-3"><a href="manage_payments.php" class="btn btn-outline-success w-100 py-3"><i class="bi bi-currency-rupee me-2"></i>Manage Payments</a></div>
            </div>
        </div>
    </div>

    <!-- Activity & Chart -->
    <div class="row g-4">
        <!-- Activity Log -->
        <div class="col-md-6">
            <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
                <div class="card-header bg-success text-white fs-5">Recent Bookings</div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($activity_log as $log): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-person-check text-success me-2 fs-5"></i>
                                    <span><?= htmlspecialchars($log['name']) ?></span>
                                </div>
                                <span class="badge bg-light text-muted"><?= $log['booking_day'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="col-md-6">
            <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
                <div class="card-header bg-success text-white fs-5">Weekly Booking Trend</div>
                <div class="card-body">
                    <canvas id="bookingChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('bookingChart').getContext('2d');
    const bookingChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($days) ?>,
            datasets: [{
                label: 'Bookings',
                data: <?= json_encode($weekly_bookings) ?>,
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                borderColor: '#198754',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#198754'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
<style>
    .icon-circle { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .list-group-item:hover { background: #f8f9fa; }
    .btn-outline-success:hover { background-color: #198754; color: white; }
</style>

<?php
$page_content = ob_get_clean();
$page_title = "Admin Dashboard";
include 'admin_template.php';
?>
