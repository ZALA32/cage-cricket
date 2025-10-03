<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'team_organizer') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';

if (!isset($_GET['id'])) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>No booking ID provided!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

$booking_id = intval($_GET['id']);

// Fetch booking details, turf info, and owner info from users table
$stmt = $conn->prepare("SELECT b.*, t.turf_name, u.name AS owner_name, u.email AS owner_email
                        FROM bookings b
                        JOIN turfs t ON b.turf_id = t.turf_id
                        JOIN users u ON t.owner_id = u.id
                        WHERE b.id = ? AND b.organizer_id = ? AND b.status = 'pending'");
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['dashboard_message'] = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Booking not found, you do not have access, or the booking is not deletable!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    header("Location: team_organizer_dashboard.php");
    exit;
}

$booking = $result->fetch_assoc();
$stmt->close();

// Handle deletion after confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delete'])) {
    $conn->begin_transaction();
    try {
        // Delete associated payment record
        $stmt = $conn->prepare("DELETE FROM payments WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();

        // Delete the booking
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ? AND organizer_id = ?");
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();
        $stmt->close();

        // Notify turf owner (users.id)
        $owner_id = $booking['owner_id'];
        if ($owner_id) {
            $notif_msg = "Booking for {$booking['turf_name']} on {$booking['date']} has been deleted by the organizer.";
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notif_stmt->bind_param("is", $owner_id, $notif_msg);
            $notif_stmt->execute();
            $notif_stmt->close();
        }

        $conn->commit();
        $_SESSION['toast_message'] = "📢 <strong>Success:</strong> Booking deleted successfully!";
        header("Location: team_organizer_dashboard.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['dashboard_message'] = "<div class='alert alert-danger alert-dismissible fade show text-center' role='alert'>Error deleting booking: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        header("Location: team_organizer_dashboard.php");
        exit;
    }
}

$page_title = "🗑️ Delete Booking - 🏏 Cage Cricket";
ob_start();
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --primary-color: #198754;
        --secondary-color: #146c43;
        --accent-color: #28a745;
        --background-color: #e6f4ea;
        --section-background: #f0f8f2;
        --text-color: #333333;
        --error-color: #dc3545;
        --border-radius: 12px;
        --shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.3s ease;
    }

    body {
        background: var(--background-color);
        font-family: 'Poppins', sans-serif;
        color: var(--text-color);
    }

    .delete-box {
        width: 100%;
        background: #fff;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        animation: fadeIn 0.5s ease-in-out;
    }

    .delete-header {
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 2.5rem;
        text-align: center;
        border-bottom: 4px solid #fff;
    }

    .delete-header h2 {
        margin: 0;
        font-weight: 600;
        font-size: 2.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .delete-body {
        padding: 3rem;
    }

    .card-details {
        background: var(--background-color);
        border-radius: var(--border-radius);
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        transition: var(--transition);
        border: 1px solid rgba(25, 135, 84, 0.2);
    }

    .card-details:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .card-details h5 {
        color: var(--secondary-color);
        font-weight: 700;
        font-size: 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-details p {
        margin: 0.75rem 0;
        font-size: 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .card-details p strong {
        color: var(--primary-color);
        font-weight: 600;
        flex: 0 0 40%;
    }

    .card-details p span {
        color: var(--text-color);
        flex: 0 0 60%;
        text-align: right;
    }

    .card-details p:last-child {
        border-bottom: none;
    }

    .btn-custom {
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
        border-radius: var(--border-radius);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        transition: var(--transition);
    }

    .btn-delete-confirm {
        background-color: var(--error-color);
    }

    .btn-delete-confirm:hover {
        background-color: #b02a37;
        transform: translateY(-2px);
    }

    .btn-dark-green {
        background-color: var(--secondary-color);
        border: 2px solid var(--secondary-color);
    }

    .btn-dark-green:hover {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        transform: translateY(-2px);
    }

    .modal-content {
        background: var(--section-background);
        border: 2px solid var(--primary-color);
        border-radius: var(--border-radius);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-bottom: 1px solid var(--primary-color);
    }

    .modal-title {
        color: #fff;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }

    .slide-in {
        animation: slideIn 0.5s ease-in-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes slideIn {
        from { opacity: 0; transform: translateX(-10px); }
        to { opacity: 1; transform: translateX(0); }
    }

    @media (max-width: 768px) {
        .delete-body {
            padding: 2rem;
        }

        .delete-header h2 {
            font-size: 1.8rem;
        }

        .card-details p {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .card-details p strong,
        .card-details p span {
            flex: none;
            text-align: left;
        }

        .btn-custom {
            font-size: 1rem;
            padding: 0.6rem 1.2rem;
        }
    }

    @media (max-width: 576px) {
        .delete-body {
            padding: 1.5rem;
        }

        .delete-header h2 {
            font-size: 1.5rem;
        }

        .card-details h5 {
            font-size: 1.3rem;
        }

        .card-details p {
            font-size: 1rem;
        }
    }
</style>

<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-10 col-xl-12 col-xxl-12">
        <div class="delete-box fade-in">
            <div class="delete-header">
                <h2><i class="bi bi-trash-fill me-2"></i>Delete Your Booking</h2>
            </div>
            <div class="delete-body">
                <?php if ($message): ?>
                    <?php echo $message; ?>
                <?php endif; ?>

                <!-- Booking Details -->
                <div class="card-details slide-in">
                    <h5><i class="bi bi-calendar-check me-2"></i>Booking Details</h5>
                    <p><strong>Event Name:</strong> <span><?php echo htmlspecialchars($booking['booking_name']); ?></span></p>
                    <p><strong>Turf Name:</strong> <span><?php echo htmlspecialchars($booking['turf_name']); ?></span></p>
                    <p><strong>Date:</strong> <span><?php echo htmlspecialchars($booking['date']); ?></span></p>
                    <p><strong>Time:</strong> <span><?php echo htmlspecialchars($booking['start_time']); ?> - <?php echo htmlspecialchars($booking['end_time']); ?></span></p>
                    <p><strong>Total Cost:</strong> <span>₹<?php echo number_format($booking['total_cost'], 2); ?></span></p>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between gap-3 slide-in">
                    <button type="button" id="confirm-delete-btn" class="btn btn-delete-confirm btn-custom w-50" data-bs-toggle="tooltip" title="Delete this booking permanently">
                        <i class="bi bi-trash-fill me-2"></i>Delete Booking
                    </button>
                    <a href="team_organizer_dashboard.php" class="btn btn-dark-green btn-custom w-50" data-bs-toggle="tooltip" title="Return to dashboard">
                        <i class="bi bi-arrow-left-circle me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmation-modal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small text-center">Are you absolutely sure you want to delete this booking? This action cannot be undone.</p>
                <form method="POST" id="confirm-form" class="mt-4 d-flex flex-column gap-3">
                    <input type="hidden" name="confirm_delete" value="1">
                    <div class="d-flex justify-content-center gap-3">
                        <button type="submit" class="btn btn-delete-confirm btn-custom"> <!-- Removed tooltip -->
                            <i class="bi bi-trash-fill me-2"></i>Delete
                        </button>
                        <button type="button" class="btn btn-dark-green btn-custom" data-bs-dismiss="modal"> <!-- Removed tooltip -->
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Bootstrap tooltips only for main page buttons
    const tooltipTriggerList = document.querySelectorAll('.delete-body [data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].forEach(tooltipTriggerEl => {
        const isDeleteButton = tooltipTriggerEl.classList.contains('btn-delete-confirm');
        new bootstrap.Tooltip(tooltipTriggerEl, {
            customClass: isDeleteButton ? 'delete-tooltip' : 'custom-tooltip',
            offset: [0, 8]
        });
    });

    // Auto-dismiss alerts after 10 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert && alert.parentNode) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }
        }, 10000);
    });

    // Handle confirmation modal
    document.getElementById('confirm-delete-btn').addEventListener('click', (e) => {
        e.preventDefault();
        const confirmationModal = new bootstrap.Modal(document.getElementById('confirmation-modal'));
        confirmationModal.show();
    });

    // Custom tooltip styling
    const style = document.createElement('style');
    style.textContent = `
        .custom-tooltip .tooltip-inner {
            background: var(--primary-color);
            color: #fff;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        .custom-tooltip .tooltip-arrow::before {
            border-top-color: var(--primary-color);
        }
        .delete-tooltip .tooltip-inner {
            background: var(--error-color);
            color: #fff;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        .delete-tooltip .tooltip-arrow::before {
            border-top-color: var(--error-color);
        }
    `;
    document.head.appendChild(style);
});
</script>

<?php
$page_content = ob_get_clean();
include 'template.php';
?>