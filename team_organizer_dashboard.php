<?php
ob_start();
require 'config.php';

// Ensure session is started only once
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug: Log session variables
error_log("team_organizer_dashboard.php: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
          ", role=" . ($_SESSION['role'] ?? 'unset') . 
          ", session_id=" . session_id());

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'team_organizer') {
    error_log("Session check failed in team_organizer_dashboard.php: Redirecting to login.php");
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';
require_once 'auto_cancel_expired_bookings.php';

// Handle dashboard message to prevent multiple alert cards
if (isset($_SESSION['dashboard_message']) && !empty($_SESSION['dashboard_message'])) {
    $message_text = strip_tags($_SESSION['dashboard_message']);
    $message = "<div id='dashboard-message' class='alert alert-success alert-dismissible fade show text-center' role='alert'>" . htmlspecialchars($message_text) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    unset($_SESSION['dashboard_message']);
}

// ✅ Fetch user data
$query = "SELECT u.name, u.email
          FROM users u
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$name = $user['name'] ?? 'Team Organizer';

// ✅ Fetch bookings with payment info joined
$bookings = [];
$query = "SELECT b.*, t.turf_name, t.turf_address, t.turf_photo,
                 p.payment_status, p.payment_method
          FROM bookings b
          JOIN turfs t ON b.turf_id = t.turf_id
          LEFT JOIN payments p ON b.id = p.booking_id
          WHERE b.organizer_id = ?
          ORDER BY b.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}
$stmt->close();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

date_default_timezone_set('Asia/Kolkata');
$now = new DateTime();
$nextDay = (clone $now)->modify('+1 day')->format('Y-m-d');

// ✅ Reminder emails
foreach ($bookings as $booking) {
    $matchDate = $booking['date'];
    $matchTime = $booking['start_time'];
    $matchDateTime = new DateTime("$matchDate $matchTime");

    if (
        in_array($booking['status'], ['approved', 'confirmed']) &&
        $booking['payment_status'] === 'completed' &&
        $matchDate === $nextDay
    ) {
        // Check if reminder already sent
        $stmt = $conn->prepare("SELECT 1 FROM match_reminders WHERE booking_id = ?");
        $stmt->bind_param("i", $booking['id']);
        $stmt->execute();
        $alreadySent = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$alreadySent) {
            $email = $user['email'];
            $name = $user['name'];
            $turfName = $booking['turf_name'];
            $turfAddress = $booking['turf_address'];
            $formattedDateTime = $matchDateTime->format('d M Y, h:i A');

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'somyasolanki82@gmail.com';
                $mail->Password = 'kpivfarktcpoebxr';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('somyasolanki82@gmail.com', 'Cage Cricket');
                $mail->addAddress($email, $name);
                $mail->Subject = '🏏 Match Reminder - Cage Cricket';
                $mail->isHTML(true);
                $mail->Body = "
                    <h2>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>This is a reminder for your match scheduled tomorrow:</p>
                    <ul>
                        <li><strong>Date & Time:</strong> $formattedDateTime</li>
                        <li><strong>Turf:</strong> $turfName</li>
                        <li><strong>Address:</strong> $turfAddress</li>
                    </ul>
                    <p>Please arrive 15 minutes early. Good luck!</p>
                    <p><strong>– Cage Cricket Team</strong></p>
                ";
                $mail->send();

                // Log reminder
                $log = $conn->prepare("INSERT INTO match_reminders (booking_id) VALUES (?)");
                $log->bind_param("i", $booking['id']);
                $log->execute();
                $log->close();

                $_SESSION['reminders_sent'][] = [
                    'match_time' => $formattedDateTime,
                    'turf' => $turfName
                ];
            } catch (Exception $e) {
                error_log("Reminder Mail Error: " . $mail->ErrorInfo);
            }
        }
    }
}

// ✅ Fetch unread notifications
$notifications = [];
$notif_query = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
$notif_query->bind_param("i", $user_id);
$notif_query->execute();
$result = $notif_query->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$notif_query->close();
$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0");

// ✅ Summary stats
$total_bookings = count($bookings);
$upcoming_bookings = 0;
$current_date = date('Y-m-d');
$current_time = date('H:i:s');
foreach ($bookings as $booking) {
    if (
        ($booking['date'] > $current_date || ($booking['date'] == $current_date && $booking['end_time'] > $current_time)) 
        && in_array($booking['status'], ['approved','confirmed']) 
        && $booking['payment_status'] === 'completed'
    ) {
        $upcoming_bookings++;
    }
}
$dynamic_max = max($total_bookings, $upcoming_bookings, 10);

$page_title = "🏠 Dashboard - Team Organizer - 🏏 Cage Cricket";
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/team_organizer_dashboard.css">

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-10 col-xl-12 col-xxl-12 dashboard-container">

    <!-- Header Section -->
    <div class="header-section">
        <h1 class="animate-title"><i class="bi bi-house-door-fill me-2"></i> Welcome, <?= htmlspecialchars(ucfirst(strstr($name, '@', true) ?: $name)); ?>!</h1>
        <p>Plan Your Next Match with Cage Cricket!</p>
    </div>

    <!-- Alerts -->
    <?php if ($message) echo $message; ?>

    <?php if (!empty($_SESSION['reminders_sent'])): ?>
        <div class="alert alert-success alert-dismissible fade show text-center" role="alert">
            <?php foreach ($_SESSION['reminders_sent'] as $reminder): ?>
                📧 Reminder sent for match on <strong><?= htmlspecialchars($reminder['match_time']) ?></strong> at <strong><?= htmlspecialchars($reminder['turf']) ?></strong>.<br>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['reminders_sent']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['toast_message'])): ?>
        <div class="alert alert-green alert-dismissible fade show text-center fw-medium" role="alert">
            <i class="bi bi-info-circle me-2"></i> <?= $_SESSION['toast_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['toast_message']); ?>
    <?php endif; ?>

    <!-- Unread Notifications -->
    <?php if (!empty($notifications)): ?>
        <div class="alert alert-green alert-dismissible fade show text-center" role="alert">
         <strong>📢 Notifications:</strong>
            <ul class="list-unstyled mt-2 mb-0">
                <?php foreach ($notifications as $note): ?>
                    <li><i class="bi bi-info-circle me-1"></i> <?= htmlspecialchars($note['message']); ?> 
                        <small class="text-muted">(<?= date('M d, H:i', strtotime($note['created_at'])); ?>)</small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Card -->
    <div class="summary-card">
        <div class="summary-item">
            <div class="circle-progress" data-value="<?= $total_bookings ?>" data-max="<?= $dynamic_max ?>">
                <svg class="progress-ring" width="100" height="100">
                    <circle class="progress-ring-circle" stroke="#198754" stroke-width="6" fill="transparent" r="44" cx="50" cy="50"/>
                </svg>
                <span class="progress-value"><?= $total_bookings ?></span>
            </div>
            <p>Total Bookings</p>
        </div>
        <div class="summary-item">
            <div class="circle-progress" data-value="<?= $upcoming_bookings ?>" data-max="<?= $dynamic_max ?>">
                <svg class="progress-ring" width="100" height="100">
                    <circle class="progress-ring-circle" stroke="#198754" stroke-width="6" fill="transparent" r="44" cx="50" cy="50"/>
                </svg>
                <span class="progress-value"><?= $upcoming_bookings ?></span>
            </div>
            <p>Upcoming Matches</p>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="turf_selection.php" class="btn btn-primary btn-custom"><i class="bi bi-calendar-check me-2"></i>Book Turf</a>
    </div>

    <!-- Bookings Section -->
    <div class="bookings-section">
        <h2><i class="bi bi-ticket-perforated me-2"></i>Your Bookings</h2>
        <div class="search-bar mb-4">
            <div class="input-group">
                <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="bookingSearch" class="form-control border-success" placeholder="Search by turf name">
                <button id="clearSearch" class="btn btn-outline-success ms-2" style="display:none;"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <?php if ($bookings): ?>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead class="sticky-header">
                        <tr>
                            <th>Turf</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Cost (₹)</th>
                            <th>Status</th>
                            <th>Payment Method</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bookingsTable">
                        <?php foreach ($bookings as $index => $booking): ?>
                            <tr class="fade-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($booking['turf_photo']): ?>
                                            <img src="<?= htmlspecialchars($booking['turf_photo']); ?>" alt="Turf" class="rounded turf-photo" loading="lazy">
                                        <?php else: ?>
                                            <div class="no-image">No Image</div>
                                        <?php endif; ?>
                                        <div class="turf-text fw-bold"><?= htmlspecialchars($booking['turf_name']); ?></div>
                                    </div>
                                </td>
                                <td class="fw-bold"><?= htmlspecialchars($booking['date']); ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($booking['start_time'] . " - " . $booking['end_time']); ?></td>
                                <td class="fw-bold"><?= number_format($booking['total_cost'], 2); ?></td>
                                <td><span class="badge status-<?= strtolower($booking['status']); ?>"><?= ucfirst($booking['status']); ?></span></td>
                                <td>
                                    <?php if (in_array($booking['status'], ['approved','confirmed']) && $booking['payment_status'] === 'completed'): ?>
                                        <span class="badge payment-method"><?= htmlspecialchars(ucfirst($booking['payment_method'] ?? 'Cash')); ?></span>
                                    <?php else: ?>
                                        <span>-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="view_booking.php?id=<?= $booking['id']; ?>" class="btn btn-primary btn-action">View</a>
                                        <?php if (in_array($booking['status'], ['approved','confirmed'])): ?>
                                            <a href="cancel_booking.php?id=<?= $booking['id']; ?>" class="btn btn-primary btn-action">Cancel</a>
                                        <?php endif; ?>
                                        <?php if ($booking['status'] === 'pending'): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-primary btn-action dropdown-toggle" type="button" data-bs-toggle="dropdown">Manage</button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="edit_booking.php?booking_id=<?= $booking['id']; ?>&turf_id=<?= $booking['turf_id']; ?>">Edit Booking</a></li>
                                                    <li><a class="dropdown-item" href="delete_booking.php?id=<?= $booking['id']; ?>">Delete Booking</a></li>
                                                </ul>
                                            </div>
                                        <?php elseif ($booking['status'] === 'approved' && $booking['payment_status'] === 'pending'): ?>
                                            <a href="generate_bill.php?booking_id=<?= $booking['id']; ?>" class="btn btn-primary btn-action">Pay</a>
                                        <?php endif; ?>
                                        <?php if ($booking['status'] === 'rejected'): ?>
                                            <a href="turf_selection.php?rebook_id=<?= $booking['id']; ?>" class="btn btn-primary btn-action">Rebook</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data"><p>No bookings yet. <a href="turf_selection.php" class="text-success">Make your first booking!</a></p></div>
        <?php endif; ?>
    </div>
</div>
        </div>
    </div>
</div>

<script src="js/team_organizer_dashboard.js"></script>

<?php
$page_content = ob_get_clean();
require 'template.php';
ob_end_flush();
?>
