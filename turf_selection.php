<?php
ob_start();
require 'config.php';

// Ensure session is started only once
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle compare action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compare_turf_id'])) {
    $turf_id = (int)$_POST['compare_turf_id'];
    if (!isset($_SESSION['compare_turfs'])) {
        $_SESSION['compare_turfs'] = [];
    }
    if (in_array($turf_id, $_SESSION['compare_turfs'])) {
        $_SESSION['compare_turfs'] = array_diff($_SESSION['compare_turfs'], [$turf_id]);
        unset($_SESSION['compare_error']);
    } else {
        if (count($_SESSION['compare_turfs']) < 4) {
            $_SESSION['compare_turfs'][] = $turf_id;
            unset($_SESSION['compare_error']);
        } else {
            $_SESSION['compare_error'] = 'Only 4 turfs can be compared at a time.';
        }
    }
    header("Location: turf_selection.php");
    exit;
}

// Debug: Log session variables
error_log("turf_selection.php: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'unset') . 
          ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'unset') . 
          ", session_id=" . session_id());

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['team_organizer', 'admin'])) {
    error_log("Session check failed in turf_selection.php: Redirecting to login.php");
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$search_location = '';
$turfs = [];

// Fetch turfs with location filter, including is_featured, turf_facility, and city
$turfs_query = "SELECT t.*, 
                ROUND(AVG(r.rating), 1) AS avg_rating, 
                COUNT(r.rating) AS total_ratings 
                FROM turfs t 
                LEFT JOIN turf_ratings r ON t.turf_id = r.turf_id";
$conditions = [];
$params = [];
$types = '';

// Only show active turfs if the user is a team organizer
if ($role == 'team_organizer') {
    $conditions[] = "t.is_active = 1";
}

// Handle search by location if provided
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_location'])) {
    $search_location = mysqli_real_escape_string($conn, trim($_POST['search_location']));
    if (!empty($search_location)) {
        $conditions[] = "(t.turf_address LIKE ? OR t.turf_name LIKE ?)";
        $params[] = "%$search_location%";
        $params[] = "%$search_location%";
        $types .= "ss";
    }
}

if (!empty($conditions)) {
    $turfs_query .= " WHERE " . implode(" AND ", $conditions);
}
$turfs_query .= " GROUP BY t.turf_id ORDER BY t.is_featured DESC, t.created_at DESC";

// Execute the query and fetch results
$stmt = $conn->prepare($turfs_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$turfs_result = $stmt->get_result();
while ($row = $turfs_result->fetch_assoc()) {
    $row['city'] = isset($row['city']) ? $row['city'] : explode(',', $row['turf_address'])[0];
    $turfs[] = $row;
}
$stmt->close();

// Fetch distinct facilities for the filter
$facilities = [];
$facility_query = "SELECT turf_facility FROM turfs WHERE turf_facility IS NOT NULL AND turf_facility != ''";
$facility_result = $conn->query($facility_query);
while ($row = $facility_result->fetch_assoc()) {
    $facility_list = array_map('trim', explode(',', $row['turf_facility']));
    foreach ($facility_list as $facility) {
        if (!empty($facility) && !in_array($facility, $facilities)) {
            $facilities[] = $facility;
        }
    }
}
sort($facilities);

$page_title = "Select a Turf - Cage Cricket";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/turf_selection.css">
</head>
<body>
    <div class="turf-selection-container mx-auto">
        <!-- Header Section -->
        <div class="header-section">
            <h1 class="animate-title"><i class="fas fa-cricket-bat-ball me-2"></i> Select a Turf</h1>
            <p class="header-subtitle">Find the Perfect Turf for Your Next Match!</p>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <?php if (isset($_SESSION['compare_error'])): ?>
                <div id="compareAlert" class="alert alert-success alert-dismissible fade show text-center" role="alert">
                    <?php echo htmlspecialchars($_SESSION['compare_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['compare_error']); ?>
            <?php endif; ?>
            <div class="search-input-group mb-4">
                <div class="input-group">
                    <span class="input-group-text bg-success text-white"><i class="fas fa-search"></i></span>
                    <input type="text" id="turfSearch" class="form-control border-success" placeholder="Search by name, address, or facility" aria-label="Search turfs">
                    <button id="clearSearch" class="btn btn-outline-success ms-2 d-none" aria-label="Clear search"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <!-- Action Buttons -->
            <div class="action-buttons mb-4">
                <button id="findNearby" class="btn btn-success btn-custom" data-bs-toggle="tooltip" title="Find turfs near your location">
                    <i class="fas fa-map-marker-alt me-2"></i> Find Turfs Near Me
                </button>
                <?php if (isset($_SESSION['compare_turfs']) && count($_SESSION['compare_turfs']) >= 2): ?>
                    <a href="compare_turfs.php" class="btn btn-success btn-custom" data-bs-toggle="tooltip" title="Compare selected turfs">
                        <i class="fas fa-balance-scale me-2"></i> View Comparison (<?php echo count($_SESSION['compare_turfs']); ?>)
                    </a>
                <?php endif; ?>
            </div>
            <!-- Filters -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="featuredFilter">
                        <label class="form-check-label" for="featuredFilter">
                            Show Only Featured Turfs
                        </label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="priceRange">Price Range</label>
                    <select id="priceRange" class="form-select border-success">
                        <option value="">Any Price</option>
                        <option value="0-500">₹0 - ₹500</option>
                        <option value="500-1000">₹500 - ₹1000</option>
                        <option value="1000-2000">₹1000 - ₹2000</option>
                        <option value="2000+">₹2000+</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="capacityFilter">Capacity</label>
                    <select id="capacityFilter" class="form-select border-success">
                        <option value="">All Capacities</option>
                        <option value="0-10">0-10 People</option>
                        <option value="11-20">11-20 People</option>
                        <option value="21-50">21-50 People</option>
                        <option value="51+">51+ People</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="facilityFilter">Facilities</label>
                    <select id="facilityFilter" class="form-select border-success">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo htmlspecialchars($facility); ?>"><?php echo htmlspecialchars($facility); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="sortBy">Sort By</label>
                    <select id="sortBy" class="form-select border-success">
                        <option value="default">Featured First</option>
                        <option value="price-asc">Price: Low to High</option>
                        <option value="price-desc">Price: High to Low</option>
                        <option value="capacity-asc">Capacity: Low to High</option>
                        <option value="capacity-desc">Capacity: High to Low</option>
                        <option value="name-asc">Name: A to Z</option>
                        <option value="name-desc">Name: Z to A</option>
                        <option value="distance-asc" class="d-none">Distance: Nearest First</option>
                        <option value="rating-desc">Rating: High to Low</option>
                        <option value="rating-asc">Rating: Low to High</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label> </label>
                    <button id="resetFilters" class="btn btn-success btn-custom" data-bs-toggle="tooltip" title="Reset all filters">
                        <i class="fas fa-undo me-2"></i> Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading Spinner -->
        <div id="loadingSpinner" class="d-none text-center my-5">
            <div class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <!-- Turf Cards -->
        <div class="row g-4" id="turfContainer">
            <?php if (count($turfs) > 0): ?>
                <?php foreach ($turfs as $index => $turf): ?>
                    <div class="col-md-4 turf-item fade-in" 
                         data-price="<?php echo $turf['booking_cost']; ?>" 
                         data-capacity="<?php echo htmlspecialchars($turf['turf_capacity']); ?>" 
                         data-name="<?php echo htmlspecialchars($turf['turf_name']); ?>" 
                         data-address="<?php echo htmlspecialchars($turf['turf_address']); ?>" 
                         data-turf-id="<?php echo $turf['turf_id']; ?>" 
                         data-lat="" 
                         data-lon="" 
                         data-distance="" 
                         data-is-featured="<?php echo $turf['is_featured'] ? 'true' : 'false'; ?>" 
                         data-facility="<?php echo htmlspecialchars(strtolower($turf['turf_facility'] ?: '')); ?>">
                        <div class="card turf-card h-100 shadow-sm <?php echo $turf['is_featured'] ? 'featured' : ''; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                            <?php if ($turf['is_featured']): ?>
                                <span class="featured-badge" data-bs-toggle="tooltip" data-bs-placement="top" title="This turf is highly rated!">
                                    <i class="fas fa-star me-1"></i> Featured
                                </span>
                            <?php endif; ?>
                            <img src="<?php echo htmlspecialchars($turf['turf_photo'] ?: 'https://via.placeholder.com/400x200?text=No+Image'); ?>" class="card-img-top turf-img" alt="<?php echo htmlspecialchars($turf['turf_name']); ?>" loading="lazy">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($turf['turf_name']); ?></h5>
                                <?php if (!empty($turf['avg_rating'])): ?>
                                <div class="rating-stars mb-2" title="<?= $turf['avg_rating'] ?> stars from <?= $turf['total_ratings'] ?> users">
                                    <span class="text-warning">
                                        <?php
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= floor($turf['avg_rating'])) {
                                                echo '<i class="fas fa-star"></i>';
                                            } elseif ($i - 0.5 <= $turf['avg_rating']) {
                                                echo '<i class="fas fa-star-half-alt"></i>';
                                            } else {
                                                echo '<i class="far fa-star"></i>';
                                            }
                                        }
                                        ?>
                                    </span>
                                    <small class="text-muted">(<?= $turf['avg_rating'] ?>/5)</small>
                                </div>
                            <?php else: ?>
                                <div class="rating-stars mb-2 text-muted">No ratings yet</div>
                            <?php endif; ?>

                                <div class="turf-details">
                                    <div class="detail-item">
                                        <span class="label">Location:</span>
                                        <span class="value turf-address"><?php echo htmlspecialchars($turf['turf_address']); ?>, <?php echo htmlspecialchars($turf['city']); ?></span>
                                    </div>
                                    <div class="detail-item distance-info d-none">
                                        <span class="label">Distance:</span>
                                        <span class="value">Calculating...</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="label">Area:</span>
                                        <span class="value"><?php echo htmlspecialchars($turf['turf_area']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="label">Cost:</span>
                                        <span class="value">₹<?php echo htmlspecialchars($turf['booking_cost']); ?>/hr</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="label">Capacity:</span>
                                        <span class="value"><?php echo htmlspecialchars($turf['turf_capacity']); ?> Players</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="label">Facilities:</span>
                                        <span class="value turf-facility"><?php echo htmlspecialchars($turf['turf_facility'] ?: 'None'); ?></span>
                                    </div>
                                    <div class="detail-item weather-info" 
                                         data-address="<?php echo htmlspecialchars($turf['turf_address']); ?>" 
                                         data-city="<?php echo htmlspecialchars($turf['city']); ?>" 
                                         data-bs-toggle="tooltip" 
                                         data-bs-placement="top" 
                                         title="Loading weather...">
                                        <span class="label">Weather:</span>
                                        <img class="weather-icon" src="" alt="Weather Icon" style="width: 24px; height: 24px; vertical-align: middle;" />
                                        <span class="value weather-text">Loading weather...</span>
                                    </div>
                                </div>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($turf['turf_address']); ?>" target="_blank" class="map-placeholder d-block text-decoration-none">
                                    View on Google Maps
                                </a>
                            </div>
                            <div class="card-footer">
                                <a href="book_turf.php?turf_id=<?php echo $turf['turf_id']; ?>" class="btn btn-success btn-custom">
                                    Book Now
                                </a>
                                <button class="btn btn-success btn-custom quick-view-btn" data-bs-toggle="modal" data-bs-target="#quickViewModal-<?php echo $turf['turf_id']; ?>">
                                    Quick View
                                </button>
                                <form method="POST" class="compare-form">
                                    <input type="hidden" name="compare_turf_id" value="<?php echo $turf['turf_id']; ?>">
                                    <button type="submit" class="btn btn-success btn-custom" data-bs-toggle="tooltip" title="<?php echo in_array($turf['turf_id'], $_SESSION['compare_turfs'] ?? []) ? 'Remove from Comparison' : 'Add to Comparison'; ?>">
                                        <?php echo in_array($turf['turf_id'], $_SESSION['compare_turfs'] ?? []) ? 'Remove' : 'Compare'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Quick View Modal -->
                        <div class="modal fade" id="quickViewModal-<?php echo $turf['turf_id']; ?>" tabindex="-1" aria-labelledby="quickViewModalLabel-<?php echo $turf['turf_id']; ?>" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-success text-white">
                                        <h5 class="modal-title" id="quickViewModalLabel-<?php echo $turf['turf_id']; ?>"><i class="fas fa-cricket-bat-ball me-2"></i> <?php echo htmlspecialchars($turf['turf_name']); ?></h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <?php if (!empty($turf['avg_rating'])): ?>
                                                    <p><strong>Rating:</strong>
                                                        <span class="text-warning">
                                                            <?php
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                if ($i <= floor($turf['avg_rating'])) {
                                                                    echo '<i class="fas fa-star"></i>';
                                                                } elseif ($i - 0.5 <= $turf['avg_rating']) {
                                                                    echo '<i class="fas fa-star-half-alt"></i>';
                                                                } else {
                                                                    echo '<i class="far fa-star"></i>';
                                                                }
                                                            }
                                                            ?>
                                                        </span>
                                                        <small class="text-muted">(<?= $turf['avg_rating'] ?>/5 from <?= $turf['total_ratings'] ?> users)</small>
                                                    </p>
                                                <?php else: ?>
                                                    <p><strong>Rating:</strong> <span class="text-muted">No ratings yet</span></p>
                                                <?php endif; ?>
                                                <img src="<?php echo htmlspecialchars($turf['turf_photo'] ?: 'https://via.placeholder.com/400x200?text=No+Image'); ?>"
                                                    alt="<?php echo htmlspecialchars($turf['turf_name']); ?>"
                                                    class="img-fluid rounded shadow border border-success"style="height: 250px; object-fit: cover; width: 100%;">
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Location:</strong> <?php echo htmlspecialchars($turf['turf_address']); ?></p>
                                                <p><strong>Area:</strong> <?php echo htmlspecialchars($turf['turf_area']); ?></p>
                                                <p><strong>Cost:</strong> ₹<?php echo htmlspecialchars($turf['booking_cost']); ?>/hr</p>
                                                <p><strong>Capacity:</strong> <?php echo htmlspecialchars($turf['turf_capacity']); ?> Players</p>
                                                <p><strong>Facilities:</strong> <?php echo htmlspecialchars($turf['turf_facility'] ?: 'None'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-custom" data-bs-dismiss="modal">Close</button>
                                        <a href="book_turf.php?turf_id=<?php echo $turf['turf_id']; ?>" class="btn btn-success btn-custom">
                                            Book Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center">
                    <p class="text-muted">No turfs found for the selected criteria.</p>
                </div>
            <?php endif; ?>
        </div>
        <!-- No Results Message -->
        <div id="noResults" class="col-12 text-center d-none">
            <p class="text-muted">No turfs found matching your search. Try adjusting your filters.</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/turf_selection.js"></script>
</body>
</html>
<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
// Ensure no whitespace after this line
