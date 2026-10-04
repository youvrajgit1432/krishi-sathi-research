<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo $pageTitle ?? 'Krishi Sathi Research'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>/css/style.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>/css/dark-mode.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <!-- Dark mode: restore preference before paint to avoid flash -->
    <script>
    (function() {
        var theme = localStorage.getItem('krishi_theme');
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        }
    })();
    </script>
</head>
<body<?php echo !isset($_SESSION['research_user_id']) ? ' class="login-page"' : ''; ?>>

<?php if (isset($_SESSION['research_user_id'])):
$currentPage = basename($_SERVER['PHP_SELF']);
$isActive = function($pages) use ($currentPage) {
    foreach ((array) $pages as $p) {
        if (strpos($currentPage, $p) !== false) return true;
    }
    return false;
};
?>
<!-- ═══ Top Navigation Bar ═══ -->
<nav class="navbar navbar-dark bg-success fixed-top shadow-sm top-navbar">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?php echo BASE_URL; ?>/dashboard.php">
            🌾 Krishi Sathi
        </a>
        <!-- Desktop Menu -->
        <ul class="navbar-nav mx-auto d-none d-md-flex flex-row gap-1">
            <li class="nav-item">
                <a class="nav-link px-2 py-1 rounded <?php echo $isActive('dashboard.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/dashboard.php">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <!-- Farmer Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link px-2 py-1 rounded dropdown-toggle <?php echo $isActive(['farmer', 'interview', 'observation']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-people"></i> Farmer
                </a>
                <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
                    <li><a class="dropdown-item <?php echo $isActive('farmers.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/farmers.php"><i class="bi bi-list-ul"></i> View Farmers</a></li>
                    <li><a class="dropdown-item <?php echo $isActive('farmer-add.php') || $isActive('farmer-quick-add.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/farmer-add.php"><i class="bi bi-person-plus"></i> Add Farmer</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item <?php echo $isActive('interviews.php') && !$isActive('interview-add.php') && !$isActive('interview-edit.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/interviews.php"><i class="bi bi-chat-dots"></i> Interviews</a></li>
                    <li><a class="dropdown-item <?php echo $isActive('interview-add.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/interview-add.php"><i class="bi bi-plus-circle"></i> New Interview</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item <?php echo $isActive('observation') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/observations.php"><i class="bi bi-eye"></i> Observations</a></li>
                </ul>
            </li>
            <!-- Stakeholder Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link px-2 py-1 rounded dropdown-toggle <?php echo $isActive(['participant', 'participant-interview']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-badge"></i> Stakeholder
                </a>
                <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
                    <li><a class="dropdown-item <?php echo $isActive('participants.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/participants.php"><i class="bi bi-list-ul"></i> View Stakeholders</a></li>
                    <li><a class="dropdown-item <?php echo $isActive('participant-add.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/participant-add.php"><i class="bi bi-person-plus"></i> Add Stakeholder</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item <?php echo $isActive('participant-interviews.php') && !$isActive('participant-interview-add.php') && !$isActive('participant-interview-edit.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/participant-interviews.php"><i class="bi bi-chat-dots"></i> Interviews</a></li>
                    <li><a class="dropdown-item <?php echo $isActive('participant-interview-add.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/participant-interview-add.php"><i class="bi bi-plus-circle"></i> New Interview</a></li>
                </ul>
            </li>
            <?php if (isLead()): ?>
            <li class="nav-item">
                <a class="nav-link px-2 py-1 rounded <?php echo $isActive(['user', 'users']) ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/users.php">
                    <i class="bi bi-people-fill"></i> Team
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewApprovedContent()): ?>
            <li class="nav-item">
                <a class="nav-link px-2 py-1 rounded <?php echo $isActive('dashboard') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/dashboard.php">
                    <i class="bi bi-graph-up"></i> Analytics
                </a>
            </li>
            <?php endif; ?>
        </ul>
        <div class="d-flex align-items-center gap-2">
            <!-- Dark Mode Toggle -->
            <button class="dark-mode-toggle" id="darkModeToggle" type="button" title="Toggle dark mode" aria-label="Toggle dark mode">
                <i class="bi bi-moon-fill"></i>
                <i class="bi bi-sun-fill"></i>
            </button>
            <span class="text-white-50 small d-none d-sm-inline"><?php echo htmlspecialchars(currentUserName()); ?></span>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light rounded-circle p-1" style="width:34px;height:34px;"
                        data-bs-toggle="dropdown" aria-expanded="false" title="Profile">
                    <i class="bi bi-person-fill"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                        <span class="dropdown-item-text small text-muted">
                            <strong><?php echo htmlspecialchars(currentUserName()); ?></strong><br>
                            <?php
                            $roleLabels = ['lead' => 'Research Lead', 'editor' => 'Research Editor', 'contributor' => 'Research Contributor', 'viewer' => 'Research Viewer'];
                            echo $roleLabels[currentUserRole()] ?? ucfirst(currentUserRole());
                            ?>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/profile.php">
                            <i class="bi bi-person-gear"></i> My Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/my-contributions.php">
                            <i class="bi bi-person-check"></i> My Contributions
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/my-contributions-dashboard.php">
                            <i class="bi bi-graph-up"></i> My Stats
                        </a>
                    </li>
                    <?php if (isLead()): ?>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/users.php">
                            <i class="bi bi-people-fill"></i> Manage Team
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/settings.php">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/recycle-bin.php">
                            <i class="bi bi-trash"></i> Recycle Bin
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/media-trash.php">
                            <i class="bi bi-image"></i> Media Trash
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ═══ Bottom Navigation (mobile only) ═══ -->
<nav class="bottom-nav d-md-none">
    <a href="<?php echo BASE_URL; ?>/dashboard.php"
       class="<?php echo $isActive('dashboard.php') ? 'active' : ''; ?>">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/farmers.php"
       class="<?php echo $isActive('farmer') ? 'active' : ''; ?>">
        <i class="bi bi-people"></i>
        <span>Farmers</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/participants.php"
       class="<?php echo $isActive('participant') ? 'active' : ''; ?>">
        <i class="bi bi-person-badge"></i>
        <span>Stakeholders</span>
    </a>
    <button type="button" class="nav-add-btn" onclick="toggleAddMenu(event)" aria-label="Add new">
        <i class="bi bi-plus-lg"></i>
        <span>New</span>
    </button>
    <a href="<?php echo BASE_URL; ?>/interviews.php"
       class="<?php echo $isActive('interview') && !$isActive('interview-add.php') ? 'active' : ''; ?>">
        <i class="bi bi-chat-dots"></i>
        <span>Interviews</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/profile.php"
       class="<?php echo $isActive('profile.php') ? 'active' : ''; ?>">
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/observations.php"
       class="<?php echo $isActive('observation') ? 'active' : ''; ?>">
        <i class="bi bi-binoculars"></i>
        <span>Observations</span>
    </a>
</nav>

<!-- ═══ Add Menu Popup (mobile) ═══ -->
<div id="addMenuOverlay" class="add-menu-overlay" onclick="closeAddMenu()"></div>
<div id="addMenu" class="add-menu-popup">
    <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="add-menu-item">
        <i class="bi bi-person-plus"></i>
        <span>Add Farmer</span>
        <small class="text-muted">Register a new farmer</small>
    </a>
    <a href="<?php echo BASE_URL; ?>/interview-add.php" class="add-menu-item">
        <i class="bi bi-chat-dots"></i>
        <span>Add Interview</span>
        <small class="text-muted">Conduct a new interview</small>
    </a>
    <a href="<?php echo BASE_URL; ?>/participant-add.php" class="add-menu-item">
        <i class="bi bi-person-badge"></i>
        <span>Add Stakeholder</span>
        <small class="text-muted">Register a new stakeholder</small>
    </a>
    <a href="<?php echo BASE_URL; ?>/participant-interview-add.php" class="add-menu-item">
        <i class="bi bi-chat-quote"></i>
        <span>Stakeholder Interview</span>
        <small class="text-muted">Conduct a stakeholder interview</small>
    </a>
</div>

<style>
.add-menu-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1031;
    background: rgba(0,0,0,0.3);
}
.add-menu-overlay.show {
    display: block;
}
.add-menu-popup {
    display: none;
    position: fixed;
    bottom: calc(var(--bottom-nav-height, 64px) + var(--safe-bottom, 0px) + 12px);
    left: 50%;
    transform: translateX(-50%) translateY(10px);
    z-index: 1032;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 6px 30px rgba(0,0,0,0.18);
    overflow: hidden;
    min-width: 220px;
    animation: addMenuIn 0.2s ease forwards;
}
.add-menu-popup.show {
    display: block;
}
@keyframes addMenuIn {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}
.add-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    color: #212529;
    text-decoration: none;
    font-size: 0.95rem;
    transition: background 0.15s;
    border-left: 3px solid transparent;
}
.add-menu-item:hover {
    background: rgba(25,135,84,0.07);
    color: #212529;
    border-left-color: var(--brand-green, #198754);
}
.add-menu-item i {
    font-size: 1.3rem;
    color: var(--brand-green, #198754);
    width: 28px;
    text-align: center;
    flex-shrink: 0;
}
.add-menu-item > span {
    font-weight: 500;
    flex-shrink: 0;
}
.add-menu-item small {
    margin-left: auto;
    font-size: 0.7rem;
    white-space: nowrap;
}
.add-menu-item + .add-menu-item {
    border-top: 1px solid rgba(0,0,0,0.05);
}
[data-bs-theme="dark"] .add-menu-popup {
    background: #2b3035;
    box-shadow: 0 6px 30px rgba(0,0,0,0.4);
}
[data-bs-theme="dark"] .add-menu-item {
    color: #dee2e6;
}
[data-bs-theme="dark"] .add-menu-item:hover {
    background: rgba(25,135,84,0.15);
}
</style>

<script>
function toggleAddMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('addMenu');
    var overlay = document.getElementById('addMenuOverlay');
    var btn = e.currentTarget;
    menu.classList.toggle('show');
    overlay.classList.toggle('show');
    btn.classList.toggle('active-nav');
}
function closeAddMenu() {
    document.getElementById('addMenu').classList.remove('show');
    document.getElementById('addMenuOverlay').classList.remove('show');
    var btn = document.querySelector('.nav-add-btn');
    if (btn) btn.classList.remove('active-nav');
}
</script>
<?php endif; ?>

<!-- ═══ Dark Mode Toggle Script ═══ -->
<script>
(function() {
    'use strict';

    var html = document.documentElement;
    var storageKey = 'krishi_theme';

    function setTheme(theme) {
        if (theme === 'dark') {
            html.setAttribute('data-bs-theme', 'dark');
        } else {
            html.removeAttribute('data-bs-theme');
        }
        try { localStorage.setItem(storageKey, theme); } catch(e) {}
    }

    function toggleTheme() {
        var current = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        setTheme(current);
    }

    // Bind toggle button
    var toggle = document.getElementById('darkModeToggle');

    if (toggle) toggle.addEventListener('click', toggleTheme);
})();
</script>

<main class="container py-3">
<?php
// Display flash messages
$flash = getFlash();
if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
