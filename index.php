<?php
error_log("=== INDEX.PHP LOADED ===");
error_log("Session ID: " . session_id());
error_log("User ID: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Role: " . ($_SESSION['role'] ?? 'not set'));

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

// Dashboard link based on user role
$dashboard_link = 'login.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'turf_owner') {
        $dashboard_link = 'turf_owner_dashboard.php';
    } elseif ($_SESSION['role'] === 'team_organizer') {
        $dashboard_link = 'team_organizer_dashboard.php';
    } elseif ($_SESSION['role'] === 'admin') {
        $dashboard_link = 'admin_dashboard.php';
    }
}

// Fetch active turfs for Available Turfs section, including owner info from users table
try {
    $stmt = $conn->prepare("SELECT t.turf_id, t.turf_name, t.turf_address, t.booking_cost, t.turf_capacity, t.turf_facility, t.turf_photo, u.name AS owner_name, u.email AS owner_email
                            FROM turfs t
                            JOIN users u ON t.owner_id = u.id
                            WHERE t.is_active = 1
                            ORDER BY t.created_at DESC");
    $stmt->execute();
    $turfs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log('Active turfs fetched for Available Turfs: ' . print_r($turfs, true));
} catch (Exception $e) {
    $error_message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error fetching turfs: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
}

// Fetch featured turfs (limited to 3 for grid display), including owner info from users table
try {
    $stmt = $conn->prepare("SELECT t.turf_id, t.turf_name, t.turf_address, t.booking_cost, t.turf_capacity, t.turf_facility, t.turf_photo, u.name AS owner_name, u.email AS owner_email
                        FROM turfs t
                        JOIN users u ON t.owner_id = u.id
                        WHERE t.is_active = 1 AND t.is_featured = 1
                        ORDER BY RAND() LIMIT 3");
    $stmt->execute();
    $featured_turfs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log('Featured turfs fetched: ' . print_r($featured_turfs, true));
} catch (Exception $e) {
    $error_message = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error fetching featured turfs: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
}

$page_title = "Cage Cricket - Book Your Turf";
ob_start();
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="css/index.css">

<!-- Search Bar Section -->
<section class="search-section py-3">
    <div class="container">
        <div class="search-bar p-3 rounded shadow">
            <div class="input-group">
                <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="turfSearch" class="form-control border-success" placeholder="Search by name, address, or facility" aria-label="Search turfs">
                <button id="clearSearch" class="btn btn-outline-success ms-2" style="display: none;" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
    </div>
</section>

<!-- Hero Section -->
<section class="hero-section">
    <div class="hero-content">
        <h1 class="display-4 fw-bold">Book Your Favorite Turf with Ease</h1>
        <p class="lead">Discover, compare, and reserve turfs with just a few clicks!</p>
        <a href="#turfs" class="btn btn-success btn-lg hover-pulse" aria-label="Browse available turfs">Browse Turfs</a>
    </div>
</section>

<!-- Error Message -->
<?php if (isset($error_message)) echo $error_message; ?>

<!-- Featured Turfs Section -->
<?php if (!empty($featured_turfs)): ?>
<section class="py-5 featured-turfs-section">
    <div class="container">
        <h2 class="text-center text-success fw-bold mb-4">Featured Turfs</h2>
        <div class="row g-4">
            <?php foreach ($featured_turfs as $turf): ?>
                <div class="col-md-4">
                    <div class="turf-card featured-turf-card hover-pulse">
                        <div class="featured-badge">Featured</div>
                        <img src="<?php echo htmlspecialchars($turf['turf_photo'] ?: 'https://via.placeholder.com/400x200?text=No+Image'); ?>" alt="<?php echo htmlspecialchars($turf['turf_name']); ?>" class="img-fluid" loading="lazy">
                        <div class="card-body">
                            <h5 class="card-title text-success fw-bold"><?php echo htmlspecialchars($turf['turf_name']); ?></h5>
                            <p class="card-text text-muted">
                                <i class="bi bi-geo-alt me-1"></i> <span class="turf-address"><?php echo htmlspecialchars($turf['turf_address']); ?></span><br>
                                <i class="bi bi-currency-rupee me-1"></i> ₹<?php echo htmlspecialchars($turf['booking_cost']); ?> / hour<br>
                                <i class="bi bi-people me-1"></i> Capacity: <?php echo htmlspecialchars($turf['turf_capacity']); ?><br>
                                <i class="bi bi-check-circle me-1"></i> Facilities: <span class="turf-facility"><?php echo htmlspecialchars($turf['turf_facility'] ?: 'None'); ?></span><br>
                                <i class="bi bi-person-badge me-1"></i> Owner: <?php echo htmlspecialchars($turf['owner_name']); ?> (<?php echo htmlspecialchars($turf['owner_email']); ?>)
                            </p>
                            <a href="book_turf.php?turf_id=<?php echo htmlspecialchars($turf['turf_id']); ?>" class="btn btn-success w-100" aria-label="Book <?php echo htmlspecialchars($turf['turf_name']); ?>">Book Now</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Available Turfs Section -->
<section id="turfs" class="py-5">
    <div class="container">
        <h2 class="text-center text-success fw-bold mb-4">Available Turfs</h2>
        <!-- Turf Cards -->
        <div class="row g-4" id="turfContainer">
            <?php if (empty($turfs)): ?>
                <div class="col-12 text-center">
                    <p class="text-muted">No active turfs available at the moment.</p>
                </div>
            <?php else: ?>
                <?php foreach ($turfs as $turf): ?>
                    <div class="col-md-4 turf-item">
                        <div class="turf-card hover-pulse">
                            <img src="<?php echo htmlspecialchars($turf['turf_photo'] ?: 'https://via.placeholder.com/400x200?text=No+Image'); ?>" alt="<?php echo htmlspecialchars($turf['turf_name']); ?>" class="img-fluid" loading="lazy">
                            <div class="card-body">
                                <h5 class="card-title text-success fw-bold"><?php echo htmlspecialchars($turf['turf_name']); ?></h5>
                                <p class="card-text text-muted">
                                    <i class="bi bi-geo-alt me-1"></i> <span class="turf-address"><?php echo htmlspecialchars($turf['turf_address']); ?></span><br>
                                    <i class="bi bi-currency-rupee me-1"></i> ₹<?php echo htmlspecialchars($turf['booking_cost']); ?> / hour<br>
                                    <i class="bi bi-people me-1"></i> Capacity: <?php echo htmlspecialchars($turf['turf_capacity']); ?><br>
                                    <i class="bi bi-check-circle me-1"></i> Facilities: <span class="turf-facility"><?php echo htmlspecialchars($turf['turf_facility'] ?: 'None'); ?></span><br>
                                    <i class="bi bi-person-badge me-1"></i> Owner: <?php echo htmlspecialchars($turf['owner_name']); ?> (<?php echo htmlspecialchars($turf['owner_email']); ?>)
                                </p>
                                <a href="book_turf.php?turf_id=<?php echo htmlspecialchars($turf['turf_id']); ?>" class="btn btn-success w-100" aria-label="Book <?php echo htmlspecialchars($turf['turf_name']); ?>">Book Now</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <!-- No Results Message -->
        <div id="noResults" class="col-12 text-center" style="display: none;">
            <p class="text-muted">No turfs found matching your search.</p>
        </div>
        <!-- Loading Spinner -->
        <div id="loadingSpinner" class="col-12 text-center" style="display: none;">
            <div class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="py-4">
    <div class="container">
        <div class="cta-section p-4 text-center rounded">
            <h4 class="text-success fw-bold mb-3">Ready to Play?</h4>
            <p class="text-muted mb-3">Sign up or log in to book your favorite turf today!</p>
            <div class="d-flex justify-content-center gap-2">
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="login.php" class="btn btn-outline-success" aria-label="Log in to Cage Cricket">Login</a>
                    <a href="register.php" class="btn btn-success" aria-label="Register for Cage Cricket">Register</a>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($dashboard_link); ?>" class="btn btn-success" aria-label="Go to your dashboard">Go to Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script src="js/index.js"></script>

<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
?>
