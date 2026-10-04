<?php
/**
 * Krishi Sathi Research System - Profile Management
 * Phase 1.8: All users can edit their profile and change password.
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();
$userId = currentUserId();
$errors = [];
$success = '';

// Fetch current user data
$user = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── Update Profile Info ────────────────────────────────────
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($full_name === '') $errors[] = 'Full name is required.';

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE research_users SET full_name = ?, phone = ?, email = ? WHERE id = ?");
                $stmt->execute([$full_name, $phone ?: null, $email ?: null, $userId]);
                $_SESSION['research_user_name'] = $full_name;
                $success = 'Profile updated successfully.';
                // Refresh user data
                $user = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
                $user->execute([$userId]);
                $user = $user->fetch();
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ─── Change Password (no current password required) ──────────
    if ($action === 'change_password') {
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($new_password === '') $errors[] = 'New password is required.';
        elseif (strlen($new_password) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($new_password !== $confirm_password) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            try {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE research_users SET password = ?, force_password_change = 0 WHERE id = ?");
                $stmt->execute([$hashed, $userId]);
                unset($_SESSION['research_user_force_change']);
                $success = 'Password changed successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'My Profile - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="page-content">
    <div class="page-header">
        <h4><i class="bi bi-person-circle"></i> My Profile</h4>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Profile Info -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                     style="width: 72px; height: 72px; font-size: 2rem;">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
                <h5 class="mt-2 mb-0"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                <span class="badge bg-<?php echo $user['role'] === 'lead' ? 'warning text-dark' : ($user['role'] === 'editor' ? 'info' : ($user['role'] === 'viewer' ? 'secondary' : 'primary')); ?> mt-1">
                    <?php
                    $roleLabels = ['lead' => 'Research Lead', 'editor' => 'Research Editor', 'contributor' => 'Research Contributor', 'viewer' => 'Research Viewer'];
                    echo $roleLabels[$user['role']] ?? ucfirst($user['role']);
                    ?>
                </span>
            </div>

            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                    <div class="form-text">Username cannot be changed.</div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Enter phone number">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter email address">
                    </div>
                </div>
                <button type="submit" class="btn btn-success w-100 mt-3">
                    <i class="bi bi-check-lg"></i> Save Profile
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-lock"></i> Change Password
        </div>
        <div class="card-body p-4">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required
                               autocomplete="new-password" placeholder="At least 6 characters">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" required
                               autocomplete="new-password" placeholder="Repeat new password">
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary w-100 mt-3">
                    <i class="bi bi-key"></i> Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- Account Info -->
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-info-circle"></i> Account Info
        </div>
        <div class="card-body py-2">
            <table class="table table-sm table-borderless mb-0 small">
                <tr>
                    <th class="text-muted" style="width:120px;">Role</th>
                    <td>
                        <span class="badge bg-<?php echo $user['role'] === 'lead' ? 'warning text-dark' : ($user['role'] === 'editor' ? 'info' : ($user['role'] === 'viewer' ? 'secondary' : 'primary')); ?>">
                            <?php echo $roleLabels[$user['role']] ?? ucfirst($user['role']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">Member Since</th>
                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                </tr>
                <tr>
                    <th class="text-muted">Last Updated</th>
                    <td><?php echo $user['updated_at'] ? date('M j, Y g:i a', strtotime($user['updated_at'])) : '-'; ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
