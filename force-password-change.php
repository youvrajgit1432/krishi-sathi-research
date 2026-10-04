<?php
/**
 * Krishi Sathi Research System - Force Password Change
 * Phase 1.9: First login with temporary password redirects here.
 */
require_once __DIR__ . '/config.php';

// Must be logged in but with force_password_change
if (!isset($_SESSION['research_user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// If not forced, redirect to dashboard
if (empty($_SESSION['research_user_force_change'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();
$userId = currentUserId();
$errors = [];
$success = false;

// Fetch user data
$user = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch();

if (!$user) {
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }

    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($new_password === '') $errors[] = 'New password is required.';
    elseif (strlen($new_password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($new_password !== $confirm_password) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE research_users SET password = ?, force_password_change = 0 WHERE id = ?");
            $stmt->execute([$hashed, $userId]);
            unset($_SESSION['research_user_force_change']);
            setFlash('success', 'Password updated successfully. Welcome to Krishi Sathi Research!');
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Set New Password - Krishi Sathi Research';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>/css/style.css" rel="stylesheet">
</head>
<body class="bg-light login-page">

<div class="container">
    <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="col-md-5">
            <div class="text-center mb-4">
                <h1 class="display-6 fw-bold text-success">🌾</h1>
                <h2 class="fw-bold">Krishi Sathi Research</h2>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                             style="width: 64px; height: 64px; font-size: 1.5rem;">
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        </div>
                        <h5 class="mt-2">Welcome, <?php echo htmlspecialchars($user['full_name']); ?></h5>
                        <p class="text-warning small mb-0">
                            <i class="bi bi-shield-lock"></i>
                            For security purposes, you must set a new password before continuing.
                        </p>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger py-2">
                            <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required
                                   autocomplete="new-password" placeholder="At least 6 characters">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required
                                   autocomplete="new-password" placeholder="Repeat your new password">
                        </div>
                        <button type="submit" class="btn btn-success w-100 py-2">
                            <i class="bi bi-check-lg"></i> Set Password &amp; Continue
                        </button>
                    </form>
                </div>
            </div>

            <p class="text-center text-muted small mt-3">
                <a href="<?php echo BASE_URL; ?>/logout.php" class="text-danger">Logout</a> &bull; Krishi Sathi Research
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
