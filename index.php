<?php
/**
 * Krishi Sathi Research System - Login Page
 */
require_once __DIR__ . '/config.php';

// Redirect if already logged in
if (isset($_SESSION['research_user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

// Session expired notification
if (isset($_GET['expired']) && $_GET['expired'] === '1') {
    $error = 'Your session has expired due to inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting: allow max 5 failed attempts per 15 minutes (stored in session)
    $now = time();
    $window = 15 * 60; // 15 minutes
    $maxAttempts = 5;
    $_SESSION['_failed_logins'] = array_filter($_SESSION['_failed_logins'] ?? [], function($ts) use ($now, $window) {
        return ($ts > $now - $window);
    });
    if (count($_SESSION['_failed_logins']) >= $maxAttempts) {
        $error = 'Too many failed login attempts. Please try again later.';
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate CSRF token
    if (empty($error)) {
        $token = $_POST['_csrf_token'] ?? '';
        if (!verify_csrf((string) $token)) {
            $error = 'Invalid form submission.';
        }
    }

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM research_users WHERE username = ? AND " . activeWhere());
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Check if user is active
                $userStatus = $user['status'] ?? 'active';
                if ($userStatus === 'inactive') {
                    $error = 'Your account has been deactivated. Contact the Research Lead.';
                } else {
                    $_SESSION['research_user_id']   = (int) $user['id'];
                    $_SESSION['research_user_name'] = $user['full_name'];
                    $_SESSION['research_user_role'] = $user['role'];

                    // Store force_password_change flag
                    $forceChange = !empty($user['force_password_change']);
                    if ($forceChange) {
                        $_SESSION['research_user_force_change'] = true;
                    }

                    // Update last login
                    try {
                        $pdo->prepare("UPDATE research_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                    } catch (PDOException $e) {
                        // Silently fail if column doesn't exist yet
                    }

                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    // Clear failed login attempts on successful login
                    unset($_SESSION['_failed_logins']);

                    setFlash('success', 'Welcome, ' . htmlspecialchars($user['full_name']) . '!');

                    if ($forceChange) {
                        header('Location: ' . BASE_URL . '/force-password-change.php');
                    } else {
                        header('Location: ' . BASE_URL . '/dashboard.php');
                    }
                    exit;
                }
            } else {
                $error = 'Invalid username or password.';
                $_SESSION['_failed_logins'][] = $now;
            }
        } catch (PDOException $e) {
            $error = 'System error. Please contact administrator.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$pageTitle = 'Login - Krishi Sathi Research';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container">
    <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="col-md-5">
            <div class="text-center mb-4">
                <h1 class="display-6 fw-bold text-success">🌾</h1>
                <h2 class="fw-bold">Krishi Sathi Research</h2>
                <p class="text-muted">Farmer Interview &amp; Research System</p>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h5 class="card-title text-center mb-4">Sign In</h5>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   autocomplete="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control"
                                   autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100 py-2">
                            <i class="bi bi-box-arrow-in-right"></i> Sign In
                        </button>
                    </form>
                </div>
            </div>

            <p class="text-center text-muted small mt-3">
                Research System v1.0 &bull; Phase 1
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
