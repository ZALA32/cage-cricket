<?php
// turf_owner_template.php - Unified HTML Layout
require_once 'config.php';

if (!isset($page_title)) {
    $page_title = "Turf Owner - Cage Cricket";
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/turf_owner_dashboard.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F7F7F7;
        }
        .navbar { z-index: 1100; }
        
        /* Sidebar */
        .sidebar {
            background-color: #198754;
            position: fixed;
            top: 64px;
            left: 0;
            height: calc(100vh - 64px);
            width: 240px;
            padding-top: 10px;
            overflow-y: auto;
            z-index: 1000;
            color: white;
        }
        .sidebar .nav-link {
            color: white;
            font-weight: 500;
            padding: 12px 20px;
            display: block;
            transition: background 0.2s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: #157347;
            color: white;
            border-radius: 5px;
        }
        .sidebar i { font-size: 1.1rem; }
        
        /* Main content */
        .main-content {
            margin-left: 240px;
            padding: calc(64px + 2rem) 2rem 2rem;
            position: static;
            z-index: auto;
            min-height: calc(100vh - 64px);
        }
        
        footer {
            background-color: #198754;
            color: #fff;
            text-align: center;
            padding: 1rem;
        }
        
        /* Animations */
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .hover-scale { transition: transform 0.2s; }
        .hover-scale:hover { transform: scale(1.05); }
        
        /* Modal stacking */
        .modal-backdrop { z-index: 2000 !important; }
        .modal { z-index: 2050 !important; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content {
                margin-left: 0;
                padding-top: 90px;
            }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- HEADER -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-success fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="turf_owner_dashboard.php">
                <i class="bi bi-house-fill"></i>
                <span>Cage Cricket - Turf Owner</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="turf_owner_dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="add_turf.php"><i class="bi bi-plus-circle me-1"></i>Add Turf</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="turf_owner_manage_turfs.php"><i class="bi bi-tools me-1"></i>Manage Turfs</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="turf_owner_manage_bookings.php"><i class="bi bi-calendar-event me-1"></i>Manage Bookings</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="ratings_feedback.php"><i class="bi bi-chat-dots me-1"></i>Ratings</a>
                    </li>
                    <li class="nav-item d-lg-none">
                        <a class="nav-link" href="turf_owner_profile.php"><i class="bi bi-person-circle me-1"></i>Profile</a>
                    </li>
                    <li class="nav-item">
                        <form action="logout.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <button type="submit" class="btn btn-outline-light btn-sm">
                                <i class="bi bi-box-arrow-right me-1"></i>Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- SIDEBAR (Desktop only) -->
    <div class="sidebar d-none d-md-block">
        <a class="nav-link" href="turf_owner_dashboard.php">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>
        <a class="nav-link" href="add_turf.php">
            <i class="bi bi-plus-circle me-2"></i> Add Turf
        </a>
        <a class="nav-link" href="turf_owner_manage_turfs.php">
            <i class="bi bi-tools me-2"></i> Manage Turfs
        </a>
        <a class="nav-link" href="turf_owner_manage_bookings.php">
            <i class="bi bi-calendar-event me-2"></i> Manage Bookings
        </a>
        <a class="nav-link" href="ratings_feedback.php">
            <i class="bi bi-chat-dots me-2"></i> Ratings & Feedback
        </a>
        <a class="nav-link" href="turf_owner_profile.php">
            <i class="bi bi-person-circle me-2"></i> Profile
        </a>
    </div>

    <!-- MAIN CONTENT -->
    <main class="main-content flex-grow-1">
        <?php
        if (isset($page_content)) {
            echo $page_content;
        } else {
            echo '<div class="alert alert-warning">No content available</div>';
        }
        ?>
    </main>

    <!-- FOOTER -->
    <footer class="mt-auto">
        <div class="container">
            <p class="mb-0">© <?php echo date('Y'); ?> Cage Cricket. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Auto-adjust layout to actual navbar height -->
    <script>
    (function () {
        function adjustLayout() {
            const nav = document.querySelector('.navbar');
            const side = document.querySelector('.sidebar');
            const main = document.querySelector('.main-content');
            if (!nav) return;
            const h = Math.round(nav.getBoundingClientRect().height);
            if (side) {
                side.style.top = h + 'px';
                side.style.height = `calc(100vh - ${h}px)`;
            }
            if (main) {
                main.style.paddingTop = `calc(${h}px + 2rem)`;
            }
        }
        window.addEventListener('load', () => {
            adjustLayout();
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(adjustLayout);
            }
            setTimeout(adjustLayout, 0);
        });
        window.addEventListener('resize', (() => {
            let t;
            return () => { clearTimeout(t); t = setTimeout(adjustLayout, 100); };
        })());
    })();
    </script>
    
    <!-- Global modal safety + hoist -->
    <script>
    (function () {
        function cleanupBackdrops() {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        }
        
        function hoistModalsToBody() {
            document.querySelectorAll('.main-content .modal').forEach(function (m) {
                document.body.appendChild(m);
            });
        }
        
        document.addEventListener('hidden.bs.modal', cleanupBackdrops);
        
        document.addEventListener('shown.bs.modal', function () {
            const bd = document.querySelector('.modal-backdrop');
            if (bd) bd.style.pointerEvents = 'auto';
        });
        
        document.addEventListener('submit', function () {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const inst = bootstrap.Modal.getInstance(openModal) || new bootstrap.Modal(openModal);
                try { inst.hide(); } catch (_) {}
            }
            setTimeout(cleanupBackdrops, 200);
        }, true);
        
        window.addEventListener('pageshow', cleanupBackdrops);
        window.addEventListener('load', hoistModalsToBody);
    })();
    </script>
</body>
</html>