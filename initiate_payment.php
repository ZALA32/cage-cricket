<?php
require 'razorpay_config.php';
require 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_organizer') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);

    // Fetch booking info, turf info, and organizer info from users table
    $stmt = $conn->prepare("SELECT b.*, t.turf_name, 
                                   u.name AS organizer_name, 
                                   u.email AS organizer_email, 
                                   u.contact_no AS organizer_contact
                            FROM bookings b
                            JOIN turfs t ON b.turf_id = t.turf_id
                            JOIN users u ON b.organizer_id = u.id
                            WHERE b.id = ? 
                              AND b.status = 'approved' 
                              AND b.payment_status = 'pending'");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die("Invalid or already paid booking.");
    }

    $booking = $result->fetch_assoc();
    $stmt->close();

    $amount = $booking['total_cost'] * 100; // in paise for Razorpay
    $bookingName = htmlspecialchars($booking['turf_name']);
    $sanitizedName = preg_replace('/[^a-zA-Z\s]/', '', $booking['organizer_name'] ?? 'Test User');
    $bookingId = $booking['id'];
    $email = $booking['organizer_email'] ?? "testuser@example.com";
    $contact = $booking['organizer_contact'] ?? "9999999999";
} else {
    die("Invalid request.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Processing Payment</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
    <script>
        var options = {
            "key": "<?php echo RAZORPAY_KEY_ID; ?>",
            "amount": "<?php echo $amount; ?>",
            "currency": "INR",
            "name": "Cage Cricket",
            "description": "Booking for <?php echo $bookingName; ?>",
            "handler": function (response) {
                window.location.href = "payment_success.php?booking_id=<?php echo $bookingId; ?>&razorpay_payment_id=" + response.razorpay_payment_id;
            },
            "prefill": {
                "name": "<?php echo $sanitizedName; ?>",
                "email": "<?php echo $email; ?>",
                "contact": "<?php echo $contact; ?>"
            },
            "theme": {
                "color": "#198754"
            },
            "modal": {
                "ondismiss": function () {
                    alert("❌ Payment cancelled or failed.");
                    window.location.href = "team_organizer_dashboard.php"; 
                }
            },
            "method": {
                "card": false,
                "netbanking": true,
                "upi": true
            }
        };

        var rzp = new Razorpay(options);
        rzp.open();
    </script>
</body>
</html>
