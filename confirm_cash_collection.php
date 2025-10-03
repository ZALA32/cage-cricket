<?php
session_start();
require 'config.php';

// Ensure time zone is IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['turf_owner', 'admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['dashboard_message'] = "⚠️ Invalid request (CSRF).";
        header("Location: turf_owner_manage_bookings.php");
        exit;
    }

    $booking_id = intval($_POST['booking_id']);
    $user_id    = intval($_SESSION['user_id']);
    $role       = $_SESSION['role'];
    $updated_at = date('Y-m-d H:i:s');

    try {
        // --- Ownership + timing gate for turf_owner (admins can bypass) ---
        if ($role !== 'admin') {
            $checkBooking = $conn->prepare(
                "SELECT b.id
                   FROM bookings b
                   JOIN turfs t ON b.turf_id = t.turf_id
                   JOIN users u ON t.owner_id = u.id
                  WHERE b.id = ?
                    AND t.owner_id = ?
                    AND CONCAT(b.date, ' ', b.start_time) <= NOW()"
            );
            $checkBooking->bind_param("ii", $booking_id, $user_id);
            $checkBooking->execute();
            $bookingExists = $checkBooking->get_result()->num_rows > 0;
            $checkBooking->close();

            if (!$bookingExists) {
                $_SESSION['dashboard_message'] = "⚠️ Invalid booking ID, unauthorized access, or booking start time has not yet occurred.";
                header("Location: turf_owner_manage_bookings.php");
                exit;
            }
        } else {
            // Admin path: just ensure the booking exists at all
            $chk = $conn->prepare("SELECT id FROM bookings WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $booking_id);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();
            if (!$exists) {
                $_SESSION['dashboard_message'] = "⚠️ Booking not found.";
                header("Location: turf_owner_manage_bookings.php");
                exit;
            }
        }

        // ============ Begin atomic operation ============
        $conn->begin_transaction();

        // Lock the booking row and fetch status + payment_status
        $bSel = $conn->prepare("SELECT status, payment_status, date, start_time FROM bookings WHERE id = ? FOR UPDATE");
        $bSel->bind_param("i", $booking_id);
        $bSel->execute();
        $bRow = $bSel->get_result()->fetch_assoc();
        $bSel->close();

        if (!$bRow) {
            throw new Exception("Booking not found.");
        }
        // Allow cash collection ONLY for confirmed bookings
        if ($bRow['status'] !== 'confirmed') {
            throw new Exception("Cash collection is allowed only for confirmed bookings.");
        }

        // Enforce match start time has passed (defense-in-depth; UI also gates)
        $start_ts = strtotime($bRow['date'] . ' ' . $bRow['start_time']);
        if ($role !== 'admin' && $start_ts !== false && $start_ts > time()) {
            throw new Exception("Match has not started yet.");
        }

        // Double-confirm guard: already paid in bookings?
        if ($bRow['payment_status'] === 'paid') {
            throw new Exception("This booking is already marked as paid.");
        }

        // Lock the payment row if present
        $pSel = $conn->prepare("SELECT id, payment_status, payment_method FROM payments WHERE booking_id = ? FOR UPDATE");
        $pSel->bind_param("i", $booking_id);
        $pSel->execute();
        $pRes = $pSel->get_result();
        $pRow = $pRes->fetch_assoc();
        $pSel->close();

        // Double-confirm guard: payment row already completed?
        if ($pRow && $pRow['payment_method'] === 'cash' && $pRow['payment_status'] === 'completed') {
            throw new Exception("This booking is already marked as paid (cash).");
        }

        // If no payment row, allow auto-create only when booking shows pending
        if (!$pRow) {
            if ($bRow['payment_status'] !== 'pending') {
                throw new Exception("Payment is not cash or not pending.");
            }
            $pIns = $conn->prepare("INSERT INTO payments (booking_id, payment_method, payment_status, created_at) VALUES (?, 'cash', 'pending', NOW())");
            $pIns->bind_param("i", $booking_id);
            $pIns->execute();
            $pIns->close();
        } else {
            // Must be cash + pending to proceed
            if (!($pRow['payment_method'] === 'cash' && $pRow['payment_status'] === 'pending')) {
                throw new Exception("Payment is not cash or not pending.");
            }
        }

        // Update bookings -> paid
        $bUpd = $conn->prepare("UPDATE bookings SET payment_status = 'paid' WHERE id = ?");
        $bUpd->bind_param("i", $booking_id);
        $bUpd->execute();
        $bUpd->close();

        // Update payments -> completed
        $pUpd = $conn->prepare("UPDATE payments SET payment_status = 'completed', updated_at = ? WHERE booking_id = ? AND payment_method = 'cash'");
        $pUpd->bind_param("si", $updated_at, $booking_id);
        $pUpd->execute();
        $pUpd->close();

        $conn->commit();
        $_SESSION['dashboard_message'] = "✅ Cash payment confirmed as collected.";
    } catch (Exception $e) {
        // Rollback on any failure
        if ($conn->errno === 0) { // safe to call even if no tx started, but guard anyway
            $conn->rollback();
        } else {
            // Still try to rollback
            @ $conn->rollback();
        }
        $_SESSION['dashboard_message'] = "⚠️ Cannot confirm cash: " . htmlspecialchars($e->getMessage());
    }

    header("Location: turf_owner_manage_bookings.php");
    exit;
} else {
    $_SESSION['dashboard_message'] = "⚠️ Invalid access.";
    header("Location: turf_owner_manage_bookings.php");
    exit;
}
