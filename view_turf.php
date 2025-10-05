<?php
session_start();
require 'config.php';

// ✅ DB check
if ($conn->connect_error) {
    error_log("DB connection failed (view_turf): " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// ✅ Auth check: only turf_owner allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'turf_owner') {
    error_log("Unauthorized access to view_turf: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id']; 
$turf_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;

if ($turf_id <= 0) {
    die("Invalid turf ID.");
}

// --------------------
// Fetch turf details
// --------------------
$sql = "SELECT t.*, 
               u.name AS owner_name, 
               u.email AS owner_email, 
               u.contact_no AS owner_contact
        FROM turfs t
        JOIN users u ON t.owner_id = u.id
        WHERE t.turf_id = ? AND u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $turf_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
    die("Turf not found or you are not authorized to view it.");
}
$turf = $res->fetch_assoc();
$stmt->close();

// --------------------
// Aggregate ratings
// --------------------
$avg_rating = null;
$total_reviews = 0;

$rs = $conn->prepare("SELECT ROUND(AVG(rating), 2) AS avg_rating, COUNT(*) AS total_reviews 
                      FROM turf_ratings WHERE turf_id = ?");
$rs->bind_param("i", $turf_id);
$rs->execute();
$agg = $rs->get_result()->fetch_assoc();
$avg_rating = $agg['avg_rating'] ? (float)$agg['avg_rating'] : null;
$total_reviews = (int)$agg['total_reviews'];
$rs->close();

// --------------------
// Fetch latest reviews
// --------------------
$reviews = [];
$rev = $conn->prepare("
    SELECT r.rating, r.feedback, r.created_at, u.name AS user_name
    FROM turf_ratings r
    JOIN users u ON u.id = r.user_id
    WHERE r.turf_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$rev->bind_param("i", $turf_id);
$rev->execute();
$rres = $rev->get_result();
while ($row = $rres->fetch_assoc()) {
    $reviews[] = $row;
}
$rev->close();

// --------------------
// Render page
// --------------------
$page_title = "View Turf - Cage Cricket";
ob_start();
?>

<div class="turf-details">
    <h2><?= htmlspecialchars($turf['turf_name']) ?></h2>
    <p><strong>Address:</strong> <?= htmlspecialchars($turf['turf_address']) ?>, <?= htmlspecialchars($turf['city']) ?></p>
    <p><strong>Booking Cost per Hour:</strong> ₹<?= htmlspecialchars($turf['booking_cost']) ?></p>
    <p><strong>Capacity:</strong> <?= htmlspecialchars($turf['turf_capacity']) ?> players</p>
    <p><strong>Area:</strong> <?= htmlspecialchars($turf['turf_area']) ?></p>
    <p><strong>Facilities:</strong> <?= htmlspecialchars($turf['turf_facility']) ?></p>
    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($turf['turf_description'])) ?></p>
    
    <?php if (!empty($turf['turf_photo'])): ?>
        <p><img src="<?= htmlspecialchars($turf['turf_photo']) ?>" alt="Turf Photo" style="max-width:400px;border-radius:10px;"></p>
    <?php endif; ?>

    <p><strong>Owner:</strong> <?= htmlspecialchars($turf['owner_name']) ?> (<?= htmlspecialchars($turf['owner_email']) ?>)</p>
    <p><strong>Contact:</strong> <?= htmlspecialchars($turf['owner_contact']) ?></p>

    <?php if ($avg_rating): ?>
        <p><strong>Average Rating:</strong> <?= $avg_rating ?>/5 (<?= $total_reviews ?> reviews)</p>
    <?php else: ?>
        <p><em>No ratings yet</em></p>
    <?php endif; ?>
</div>

<hr>

<div class="reviews">
    <h3>Latest Reviews</h3>
    <?php if (!empty($reviews)): ?>
        <ul>
            <?php foreach ($reviews as $r): ?>
                <li>
                    <strong><?= htmlspecialchars($r['user_name']) ?></strong> rated 
                    <strong><?= $r['rating'] ?>/5</strong> <br>
                    <?= nl2br(htmlspecialchars($r['feedback'])) ?><br>
                    <small><?= $r['created_at'] ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No reviews yet.</p>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
