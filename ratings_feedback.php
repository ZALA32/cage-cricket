<?php
session_start();
require 'config.php';

// --------------------
// Auth: Turf Owner only
// --------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Fetch user role from users table
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user || $user['role'] !== 'turf_owner') {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// --------------------
// Filters
// --------------------
$turf_filter    = isset($_GET['turf_id']) ? (int) $_GET['turf_id'] : 0;
$stars_filter   = isset($_GET['stars']) ? (int) $_GET['stars'] : 0;            
$has_feedback   = isset($_GET['has_feedback']) ? $_GET['has_feedback'] : 'all'; 
$search_text    = isset($_GET['q']) ? trim($_GET['q']) : '';

// --------------------
// Pagination
// --------------------
$per_page = 10;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// --------------------
// WHERE builder
// --------------------
$where  = ["t.owner_id = ?"];
$params = [$user_id];
$types  = "i";

if ($turf_filter) {
    $where[] = "tr.turf_id = ?";
    $params[] = $turf_filter;
    $types   .= "i";
}

if ($stars_filter > 0 && $stars_filter <= 5) {
    $where[] = "tr.rating >= ?";
    $params[] = $stars_filter;
    $types   .= "i";
}

if ($has_feedback === 'yes') {
    $where[] = "TRIM(COALESCE(tr.feedback,'')) <> ''";
} elseif ($has_feedback === 'no') {
    $where[] = "TRIM(COALESCE(tr.feedback,'')) = ''";
}

if ($search_text !== '') {
    $where[] = "(u.name LIKE CONCAT('%', ?, '%') OR tr.feedback LIKE CONCAT('%', ?, '%'))";
    $params[] = $search_text;
    $params[] = $search_text;
    $types   .= "ss";
}

$where_clause = implode(" AND ", $where);

// --------------------
// Fetch active turfs for filter dropdown
// --------------------
$turfs = [];
$stmt = $conn->prepare("SELECT turf_id, turf_name FROM turfs WHERE owner_id = ? AND is_active = 1 ORDER BY turf_name");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $turfs[] = $r; }
$stmt->close();

// --------------------
// Total count for pagination
// --------------------
$total_rows = 0;
$sql = "SELECT COUNT(*) AS cnt
        FROM turf_ratings tr
        JOIN turfs t ON tr.turf_id = t.turf_id
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN bookings b ON tr.booking_id = b.id
        WHERE $where_clause";
$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total_rows = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// --------------------
// Ratings list (paginated)
// --------------------
$rows = [];
$sql = "SELECT 
        tr.id, tr.rating, tr.feedback, tr.created_at,
        t.turf_id, t.turf_name,
        u.name AS reviewer_name,
        b.id AS booking_id, b.date AS match_date, b.start_time, b.end_time
    FROM turf_ratings tr
    JOIN turfs t ON tr.turf_id = t.turf_id
    JOIN users u ON tr.user_id = u.id
    LEFT JOIN bookings b ON tr.booking_id = b.id
    WHERE $where_clause
    ORDER BY tr.created_at DESC
    LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$list_params = $params;
$list_types  = $types . "ii";
$list_params[] = $per_page;
$list_params[] = $offset;
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

// --------------------
// Aggregates for charts
// --------------------
$dist = [1=>0,2=>0,3=>0,4=>0,5=>0];
$perTurf = [];
$sql = "SELECT tr.rating, COUNT(*) AS c
        FROM turf_ratings tr
        JOIN turfs t ON tr.turf_id = t.turf_id
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN bookings b ON tr.booking_id = b.id
        WHERE $where_clause
        GROUP BY tr.rating";
$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $rt = (int)$r['rating'];
    if ($rt >=1 && $rt <=5) $dist[$rt] = (int)$r['c'];
}
$stmt->close();

$sql = "SELECT t.turf_name, AVG(tr.rating) AS avg_rating, COUNT(*) AS cnt
        FROM turf_ratings tr
        JOIN turfs t ON tr.turf_id = t.turf_id
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN bookings b ON tr.booking_id = b.id
        WHERE $where_clause
        GROUP BY t.turf_id, t.turf_name
        ORDER BY t.turf_name";
$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $perTurf[$r['turf_name']] = [
        'avg' => round((float)$r['avg_rating'], 2),
        'cnt' => (int)$r['cnt'],
    ];
}
$stmt->close();

// --------------------
// Overall average
// --------------------
$sumRatings = 0; $cntRatings = 0;
foreach ($dist as $star => $cnt) { $sumRatings += $star * $cnt; $cntRatings += $cnt; }
$overallAvg = $cntRatings ? round($sumRatings / $cntRatings, 2) : 0.00;

// --------------------
// Page title and content
// --------------------
$page_title = "Ratings & Feedback - Cage Cricket";
ob_start();
?>

<div class="container-fluid fade-in" style="max-width: 1400px;">
    <div class="dashboard-header">
        <h1 class="fw-bold"><i class="bi bi-chat-dots me-2"></i> Ratings & Feedback</h1>
    </div>

    <!-- Summary & Charts -->
    <!-- (Your HTML here remains the same; use $rows, $dist, $perTurf, $overallAvg as in your original code) -->

</div>

<link rel="stylesheet" href="css/ratings_feedback.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.rfDist = <?php echo json_encode(array_values($dist), JSON_NUMERIC_CHECK); ?>;
window.rfLabels = [1,2,3,4,5].map(s => s + '★');
window.rfPerTurf = <?php echo json_encode($perTurf, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK); ?>;
</script>
<script src="js/ratings_feedback.js"></script>

<?php
$page_content = ob_get_clean();
include 'turf_owner_template.php';
?>
