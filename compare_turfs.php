<?php
ob_start();
require 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['team_organizer', 'admin'])) {
    error_log("Session check failed in compare_turfs.php: Redirecting to login.php");
    header("Location: login.php");
    exit;
}

// Handle clear comparison action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_comparison'])) {
    unset($_SESSION['compare_turfs']);
    header("Location: turf_selection.php");
    exit;
}

// Handle remove individual turf action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compare_turf_id'])) {
    $turf_id = (int)$_POST['compare_turf_id'];
    if (isset($_SESSION['compare_turfs']) && in_array($turf_id, $_SESSION['compare_turfs'])) {
        $_SESSION['compare_turfs'] = array_diff($_SESSION['compare_turfs'], [$turf_id]);
        unset($_SESSION['compare_error']);
    }
    header("Location: compare_turfs.php");
    exit;
}

// Fetch selected turfs
$selected_turfs = [];
if (isset($_SESSION['compare_turfs']) && !empty($_SESSION['compare_turfs'])) {
    $turf_ids = array_map('intval', $_SESSION['compare_turfs']);
    $placeholders = implode(',', array_fill(0, count($turf_ids), '?'));
    $query = "SELECT t.*, 
              ROUND(AVG(r.rating), 1) AS avg_rating, 
              COUNT(r.rating) AS total_ratings,
              u.name AS owner_name, u.email AS owner_email
              FROM turfs t 
              LEFT JOIN turf_ratings r ON t.turf_id = r.turf_id 
              LEFT JOIN users u ON t.owner_id = u.id
              WHERE t.turf_id IN ($placeholders)
              GROUP BY t.turf_id";
    $stmt = $conn->prepare($query);
    $types = str_repeat('i', count($turf_ids));
    $stmt->bind_param($types, ...$turf_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['city'] = isset($row['city']) ? $row['city'] : explode(',', $row['turf_address'])[0];
        $selected_turfs[] = $row;
    }
    $stmt->close();
}

$page_title = "🏏 Compare Turfs - Cage Cricket";
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
    <link rel="stylesheet" href="css/compare_turfs.css">
</head>
<body>
    <div class="turf-selection-container mx-auto">
        <!-- Header Section -->
        <div class="header-section">
            <h1 class="animate-title"><i class="fas fa-cricket-bat-ball me-2"></i> Compare Turfs</h1>
            <p class="header-subtitle">Compare Your Selected Turfs for the Perfect Match!</p>
        </div>

        <!-- Comparison Table -->
        <?php if (count($selected_turfs) >= 2): ?>
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Attribute</th>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <th><?php echo htmlspecialchars($turf['turf_name']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Image</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>
                                    <img src="<?php echo htmlspecialchars($turf['turf_photo'] ?: 'https://via.placeholder.com/400x200?text=No+Image'); ?>" 
                                         alt="<?php echo htmlspecialchars($turf['turf_name']); ?>" 
                                         class="turf-img">
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Rating</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>
                                    <?php if (!empty($turf['avg_rating'])): ?>
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
                                        <small class="text-muted">(<?php echo $turf['avg_rating']; ?>/5 from <?php echo $turf['total_ratings']; ?> users)</small>
                                    <?php else: ?>
                                        <span class="text-muted">No ratings yet</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Location</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td><?php echo htmlspecialchars($turf['turf_address']); ?>, <?php echo htmlspecialchars($turf['city']); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Area</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td><?php echo htmlspecialchars($turf['turf_area']); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Cost</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>₹<?php echo htmlspecialchars($turf['booking_cost']); ?>/hr</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Capacity</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td><?php echo htmlspecialchars($turf['turf_capacity']); ?> Players</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Facilities</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td><?php echo htmlspecialchars($turf['turf_facility'] ?: 'None'); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Owner</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>
                                    <?php echo htmlspecialchars($turf['owner_name'] ?? 'Unknown'); ?>
                                    <?php if (!empty($turf['owner_email'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($turf['owner_email']); ?></small>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Featured</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>
                                    <?php if (!empty($turf['is_featured'])): ?>
                                        <span class="featured-badge" data-bs-toggle="tooltip" title="This turf is highly rated!">
                                            <i class="fas fa-star me-1"></i> Featured
                                        </span>
                                    <?php else: ?>
                                        <span class="not-featured-badge">Not Featured</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Action</strong></td>
                            <?php foreach ($selected_turfs as $turf): ?>
                                <td>
                                    <a href="book_turf.php?turf_id=<?php echo $turf['turf_id']; ?>" 
                                       class="btn btn-success btn-custom" 
                                       data-bs-toggle="tooltip" 
                                       title="Book this turf now">
                                        <i class="fas fa-calendar-check me-1"></i> Book Now
                                    </a>
                                    <form method="POST" class="compare-form d-inline">
                                        <input type="hidden" name="compare_turf_id" value="<?php echo $turf['turf_id']; ?>">
                                        <button type="submit" 
                                                class="btn btn-remove btn-custom" 
                                                data-bs-toggle="tooltip" 
                                                title="Remove from Comparison">
                                            <i class="fas fa-trash me-1"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex gap-2 justify-content-center">
                <a href="turf_selection.php" 
                   class="btn btn-success btn-custom" 
                   data-bs-toggle="tooltip" 
                   title="Return to turf selection">
                    <i class="fas fa-arrow-left me-2"></i> Back to Selection
                </a>
                <form method="POST" class="d-inline">
                    <button type="submit" 
                            name="clear_comparison" 
                            class="btn btn-success btn-custom" 
                            data-bs-toggle="tooltip" 
                            title="Clear all selected turfs">
                        <i class="fas fa-trash me-2"></i> Clear Comparison
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center fade-in">
                <p>Please select at least two turfs to compare. 
                   <a href="turf_selection.php" class="text-decoration-none">Go back</a> to select turfs.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enable tooltips
        document.addEventListener('DOMContentLoaded', function () {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
?>