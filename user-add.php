<?php
/**
 * Krishi Sathi Research System - Add Researcher
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
$errors = [];

// Temporary password constant
define('TEMP_PASSWORD', 'admin123');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }
    $username   = trim($_POST['username'] ?? '');
    $full_name  = trim($_POST['full_name'] ?? '');
    $role       = $_POST['role'] ?? 'contributor';
    $phone      = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');

    // Validation
    if ($username === '') $errors[] = 'Username is required.';
    elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3-50 characters (letters, numbers, underscores only).';
    }
    if ($full_name === '') $errors[] = 'Full name is required.';
    if (!in_array($role, ['lead', 'editor', 'contributor', 'viewer'])) $errors[] = 'Invalid role.';

    if (empty($errors)) {
        try {
            // Check for duplicate username
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM research_users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username "' . htmlspecialchars($username) . '" is already taken.';
            } else {
                $hashed = password_hash(TEMP_PASSWORD, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO research_users (username, password, full_name, role, phone, email, force_password_change, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([$username, $hashed, $full_name, $role, $phone ?: null, $email ?: null, currentUserId()]);
                $newId = $pdo->lastInsertId();
                setFlash('success', 'Researcher "' . htmlspecialchars($full_name) . '" created! Temporary password: <strong>' . TEMP_PASSWORD . '</strong> — they must change it on first login.');
                header('Location: ' . BASE_URL . '/users.php');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        saveOld($_POST);
    }
}

$pageTitle = 'Add Researcher - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-person-plus"></i> Add Researcher</h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?>
                            <div><?php echo $e; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?php echo htmlspecialchars(old('full_name')); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   value="<?php echo htmlspecialchars(old('username')); ?>"
                                   pattern="[a-zA-Z0-9_]{3,50}" required>
                            <div class="form-text">3-50 characters, letters, numbers, underscores.</div>
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars(old('phone')); ?>" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars(old('email')); ?>" placeholder="Optional">
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="viewer" <?php echo old('role') === 'viewer' ? 'selected' : ''; ?>>Research Viewer</option>
                            <option value="contributor" <?php echo old('role') === 'contributor' ? 'selected' : ''; ?>>Research Contributor</option>
                            <option value="editor" <?php echo old('role') === 'editor' ? 'selected' : ''; ?>>Research Editor</option>
                            <option value="lead" <?php echo old('role') === 'lead' ? 'selected' : ''; ?>>Research Lead</option>
                        </select>
                    </div>

                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle"></i>
                        Temporary password <strong>admin123</strong> will be auto-assigned.
                        User must change it on first login.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg"></i> Create Researcher
                        </button>
                        <a href="<?php echo BASE_URL; ?>/users.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
