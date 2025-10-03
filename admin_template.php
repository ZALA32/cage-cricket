<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Admin Panel' ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

    <style>
        body {
            background: #f5f7fb;
            font-family: 'Poppins', sans-serif;
        }

        .navbar {
            z-index: 999;
        }

        .sidebar {
            background: #198754;
            min-height: 100vh;
            padding-top: 60px;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            overflow-y: auto;
        }

        .sidebar a {
            color: white;
            display: block;
            padding: 12px 20px;
            text-decoration: none;
            font-weight: 500;
        }

        .sidebar a:hover {
            background: #157347;
            border-radius: 5px;
        }

        .main-content {
            margin-left: 250px;
            padding: 100px 2rem 2rem 2rem;
        }

        .footer {
            text-align: center;
            font-size: 0.9rem;
            color: #666;
            padding: 1rem;
            margin-top: 40px;
            background: #f0f0f0;
            border-top: 1px solid #ccc;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffffff, #e8f5e9);
            border-left: 5px solid #198754;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0,0,0,0.1);
        }

        .icon-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.8rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding-top: 80px;
            }
        }
    </style>
</head>
<body>

    <!-- HEADER (Navbar) -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-success fixed-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="#">🏏 CageCricket Admin</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMenu">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-end" id="navbarMenu">
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i> Admin
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#">Profile</a></li>
                            <li><a class="dropdown-item" href="#">Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- SIDEBAR -->
    <div class="sidebar d-none d-md-block">
        <a href="admin_dashboard.php"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a>
        <a href="manage_users.php"><i class="bi bi-people-fill me-2"></i> Users</a>
        <a href="manage_turfs.php"><i class="bi bi-building-fill me-2"></i> Turfs</a>
        <a href="manage_bookings.php"><i class="bi bi-calendar-check-fill me-2"></i> Bookings</a>
        <a href="manage_payments.php"><i class="bi bi-currency-rupee me-2"></i> Payments</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <?= $page_content ?>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        &copy; <?= date("Y") ?> CageCricket Admin Panel 
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
