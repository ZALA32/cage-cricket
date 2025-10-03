<?php
// template.php
require_once 'config.php';

if (!isset($page_title)) {
    $page_title = "Cage Cricket";
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine the dashboard link based on the user's role (if logged in)
$dashboard_link = 'login.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'turf_owner') {
        $dashboard_link = 'turf_owner_dashboard.php';
    } elseif ($_SESSION['role'] === 'team_organizer') {
        $dashboard_link = 'team_organizer_dashboard.php';
    } elseif ($_SESSION['role'] === 'admin') {
        $dashboard_link = 'admin_dashboard.php';
    }
}

// CSRF token function for secure logout
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Book your turf with Cage Cricket, the ultimate sports booking platform.">
    <meta name="keywords" content="turf booking, cage cricket, sports, football, cricket">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="Book your turf with Cage Cricket!">
    <meta property="og:image" content="images/cage-cricket-logo.png">
    <meta property="og:url" content="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
    <title><?php echo htmlspecialchars($page_title . ' | Cage Cricket'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #F7F7F7;
            font-family: 'Poppins', sans-serif;
        }
        header, footer {
            background-color: #198754;
        }
        .cricket-logo {
            width: 40px;
            height: 40px;
        }
        .nav-link {
            position: relative;
            transition: color 0.3s ease;
            padding: 0.5rem 1rem;
            line-height: 1.5;
            color: #FFFFFF;
            background: none;
            border: none;
            font-size: inherit;
        }
        .nav-link:hover {
            color: #FFFFFF;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -2px;
            left: 0;
            background-color: #FFFFFF;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after {
            width: 100%;
        }
        .modal-enter {
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .spinner {
            border: 4px solid #F7F7F7;
            border-top: 4px solid #FFFFFF;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @media (prefers-reduced-motion: reduce) {
            .cricket-logo, .modal-enter, .spinner {
                animation: none;
            }
        }
        .btn-primary {
            background-color: #FFFFFF;
            border-color: #FFFFFF;
            color: #198754;
        }
        .btn-primary:hover {
            background-color: #e0e0e0;
            border-color: #e0e0e0;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- Header -->
    <header>
        <nav class="navbar navbar-expand-lg navbar-dark bg-success" aria-label="Main navigation">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                    <img src="images/cage-cricket-logo.png" alt="Cage Cricket Logo" class="cricket-logo" loading="lazy">
                    <span>Cage Cricket</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <?php if (isset($_SESSION['user_id'])) { ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $dashboard_link; ?>">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <form action="logout.php" method="POST" id="logout-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <button type="submit" class="nav-link">Logout</button>
                                </form>
                            </li>
                        <?php } else { ?>
                            <li class="nav-item">
                                <a class="nav-link" href="login.php">Login</a>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="flex-grow-1 d-flex align-items-center justify-content-center">
        <div class="container py-5">
            <?php
            if (isset($page_content)) {
                echo $page_content;
            } else {
                echo '<div class="alert alert-warning">Content not found. Please try again.</div>';
            }
            ?>
            <?php if ($page_title === 'Book Turf') { ?>
                <div class="weather-widget card p-3 mb-3">
                    <h5>Weather Forecast</h5>
                    <div id="weather-data" class="spinner"></div>
                </div>
            <?php } ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-success text-white p-4">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5>Cage Cricket</h5>
                    <p>Book your turf, play your game!</p>
                </div>
                <div class="col-md-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="about.php" class="text-white">About Us</a></li>
                        <li><a href="contact.php" class="text-white">Contact</a></li>
                        <li><a href="terms.php" class="text-white">Terms & Conditions</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5>Follow Us</h5>
                    <a href="#" class="text-white mx-2"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="text-white mx-2"><i class="bi bi-twitter"></i></a>
                    <a href="#" class="text-white mx-2"><i class="bi bi-instagram"></i></a>
                </div>
            </div>
            <hr class="bg-white">
            <p class="text-center mb-0">© <?php echo date('Y'); ?> Cage Cricket. All rights reserved.</p>
            <p class="text-center mb-0 small">Image by <a href="https://pngtree.com" class="text-white">Pngtree</a></p>
        </div>
    </footer>

    <!-- Scripts -->
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>