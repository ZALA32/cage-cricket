<?php
session_start();
require 'config.php';

// Basic DB check
if ($conn->connect_error) {
    error_log("DB connection failed (view_turf): " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// Auth: Turf Owner only (same as manage page)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner'])) {
    error_log("Unauthorized access to view_turf: " . json_encode($_SESSION));
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id']; // users.id
$turf_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;

if ($turf_id <= 0) {
    $error = "Invalid turf ID.";
}

// Fetch turf + aggregate ratings, verifying ownership
$turf = null;
$avg_rating = null;
$total_reviews = 0;

if (!isset($error)) {
    // Update the SQL query to use the `users` table for ownership check
    $sql = "SELECT t.* 
            FROM turfs t
            JOIN users u ON t.owner_id = u.id  -- Join with users instead of turf_owner
            WHERE t.turf_id = ? AND u.id = ?  -- Validate ownership with users.id
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $turf_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $turf = $res->fetch_assoc();
    } else {
        $error = "Turf not found or you are not authorized to view it.";
    }
    $stmt->close();
}

if (!isset($error)) {
    // Aggregate ratings
    $rs = $conn->prepare("SELECT ROUND(AVG(rating), 2) AS avg_rating, COUNT(*) AS total_reviews FROM turf_ratings WHERE turf_id = ?");
    $rs->bind_param("i", $turf_id);
    $rs->execute();
    $agg = $rs->get_result()->fetch_assoc();
    $avg_rating = $agg['avg_rating'] ? (float)$agg['avg_rating'] : null;
    $total_reviews = (int)$agg['total_reviews'];
    $rs->close();

    // Latest reviews (with user name)
    $reviews = [];
    $rev = $conn->prepare("
        SELECT r.rating, r.feedback, r.created_at, u.name AS user_name
        FROM turf_ratings r
        JOIN users u ON u.id = r.user_id  -- Join with users instead of team_organizer
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
}

$page_title = "View Turf - Cage Cricket";
ob_start();
?>

<!-- (HTML content stays the same as in your file) -->

<?php
if (!file_exists('turf_owner_template.php')) {
    error_log("Template not found (view_turf): turf_owner_template.php");
    die("Template file not found.");
}
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
