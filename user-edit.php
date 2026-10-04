<?php
/**
 * Krishi Sathi Research System - Edit Researcher / Change Password
 * Only accessible by Research Lead role.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!isLead()) {
    setFlash('error', 'Access denied. Research Lead role required.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$user = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
$user->execute([$id]);
$user = $user->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_URL . '/users.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF for all POST actions
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }

    $action = $_POST['action'] ?? '';

    // ─── Update Profile Info ────────────────────────────────────
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'contributor';
        if ($full_name === '') $errors[] = 'Full name is required.';
        if (!in_array($role, ['lead', 'editor', 'contributor', 'viewer'])) $errors[] = 'Invalid role.';

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE research_users SET full_name = ?, role = ? WHERE id = ?");
                $stmt->execute([$full_name, $role, $id]);
                setFlash('success', 'User updated successfully.');
                header('Location: ' . BASE_URL . '/users.php');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ─── Toggle Status ──────────────────────────────────────────
    if ($action === 'toggle_status') {
        $newStatus = $_POST['new_status'] ?? 'active';
        try {
            $pdo->prepare("UPDATE research_users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            $statusLabel = $newStatus === 'active' ? 'activated' : 'deactivated';
            setFlash('success', 'User ' . $statusLabel . ' successfully.');
            header('Location: ' . BASE_URL . '/user-edit.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // ─── Reset Password ─────────────────────────────────────────
    if ($action === 'reset_password') {
        try {
            $tempPass = 'admin123';
            $hashed = password_hash($tempPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE research_users SET password = ?, force_password_change = 1 WHERE id = ?");
            $stmt->execute([$hashed, $id]);
            setFlash('success', 'Password reset to <strong>admin123</strong>. User must change it on next login.');
            header('Location: ' . BASE_URL . '/user-edit.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Researcher - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-pencil"></i> Edit Researcher</h5>
                <p class="text-muted small">Username: <code><?php echo htmlspecialchars($user['username']); ?></code></p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?>
                            <div><?php echo $e; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <!-- Profile Info -->
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? $user['full_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="viewer" <?php echo ($_POST['role'] ?? $user['role']) === 'viewer' ? 'selected' : ''; ?>>Research Viewer</option>
                            <option value="contributor" <?php echo ($_POST['role'] ?? $user['role']) === 'contributor' ? 'selected' : ''; ?>>Research Contributor</option>
                            <option value="editor" <?php echo ($_POST['role'] ?? $user['role']) === 'editor' ? 'selected' : ''; ?>>Research Editor</option>
                            <option value="lead" <?php echo ($_POST['role'] ?? $user['role']) === 'lead' ? 'selected' : ''; ?>>Research Lead</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2 mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Save Changes
                        </button>
                        <a href="<?php echo BASE_URL; ?>/users.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>

                <hr>

                <!-- Reset Password -->
                <form method="post" class="mb-3" onsubmit="return confirm('Reset password for <?php echo str_replace("'", "\\'", $user['full_name']); ?> to admin123? They must change it on next login.')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <h6 class="text-muted mb-2"><i class="bi bi-key"></i> Reset Password</h6>
                    <p class="small text-muted">Reset password to <strong>admin123</strong>. User will be forced to change on next login.</p>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-clockwise"></i> Reset to Default Password
                    </button>
                </form>
                <hr>

                <!-- Status Toggle -->
                <?php $currentStatus = $user['status'] ?? 'active'; ?>
                <form method="post" class="mb-3" onsubmit="return confirm('<?php echo $currentStatus === 'active' ? 'Deactivate' : 'Activate'; ?> this user?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="new_status" value="<?php echo $currentStatus === 'active' ? 'inactive' : 'active'; ?>">
                    <h6 class="text-muted mb-2"><i class="bi bi-toggle-on"></i> Account Status</h6>
                    <p class="small">
                        Current status:
                        <span class="badge bg-<?php echo $currentStatus === 'active' ? 'success' : 'danger'; ?>">
                            <?php echo ucfirst($currentStatus); ?>
                        </span>
                    </p>
                    <button type="submit" class="btn btn-<?php echo $currentStatus === 'active' ? 'danger' : 'success'; ?>">
                        <i class="bi bi-<?php echo $currentStatus === 'active' ? 'pause-circle' : 'play-circle'; ?>"></i>
                        <?php echo $currentStatus === 'active' ? 'Deactivate User' : 'Reactivate User'; ?>
                    </button>
                </form>

                <hr>

                <!-- Audit Info -->
                <h6 class="text-muted mb-2"><i class="bi bi-clock-history"></i> Audit Info</h6>
                <table class="table table-sm table-borderless small mb-0">
                    <tr>
                        <th class="text-muted" style="width:120px;">Created</th>
                        <td><?php echo $user['created_at'] ? date('M j, Y g:i a', strtotime($user['created_at'])) : '-'; ?></td>
                    </tr>
                    <?php if (!empty($user['created_by'])): ?>
                    <?php
                        $creator = $pdo->prepare("SELECT full_name FROM research_users WHERE id = ?");
                        $creator->execute([$user['created_by']]);
                        $creatorName = $creator->fetchColumn();
                    ?>
                    <tr>
                        <th class="text-muted">Created By</th>
                        <td><?php echo htmlspecialchars($creatorName ?: 'Unknown'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th class="text-muted">Last Login</th>
                        <td><?php echo $user['last_login'] ? date('M j, Y g:i a', strtotime($user['last_login'])) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Password Change</th>
                        <td>
                            <?php if (!empty($user['force_password_change'])): ?>
                                <span class="badge bg-warning text-dark">Required</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>