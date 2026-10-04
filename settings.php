<?php
/**
 * Krishi Sathi Research System - Research Settings
 * Phase 1.5: Research Lead can configure interview target.
 * Phase 6: Added pagination size, default status, photo quality settings.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!isLead()) {
    setFlash('error', 'Only Research Lead can access settings.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();
$errors = [];
$saved = false;

// Fetch current settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM research_settings");
$stmt->execute();
$settings = [];
foreach ($stmt as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }

    $target = (int) ($_POST['interview_target'] ?? 100);
    if ($target < 1) $target = 1;

    $perPage = (int) ($_POST['pagination_size'] ?? PER_PAGE);
    if ($perPage < 10) $perPage = 10;
    if ($perPage > 200) $perPage = 200;

    $defaultStatus = trim($_POST['default_interview_status'] ?? 'draft');
    if (!in_array($defaultStatus, ['draft', 'submitted'], true)) $defaultStatus = 'draft';

    $photoQuality = trim($_POST['photo_quality'] ?? 'original');
    if (!in_array($photoQuality, ['original', 'high', 'medium', 'low'], true)) $photoQuality = 'original';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO research_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
        $stmt->execute(['interview_target', (string) $target, currentUserId(), (string) $target, currentUserId()]);

        $stmt->execute(['pagination_size', (string) $perPage, currentUserId(), (string) $perPage, currentUserId()]);
        $stmt->execute(['default_interview_status', $defaultStatus, currentUserId(), $defaultStatus, currentUserId()]);
        $stmt->execute(['photo_quality', $photoQuality, currentUserId(), $photoQuality, currentUserId()]);

        $pdo->commit();
        $saved = true;
        $settings['interview_target'] = (string) $target;
        $settings['pagination_size'] = (string) $perPage;
        $settings['default_interview_status'] = $defaultStatus;
        $settings['photo_quality'] = $photoQuality;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}

$pageTitle = 'Settings - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-gear"></i> Research Settings</h5>
                <p class="text-muted small mb-3">Configure research targets, pagination, and system preferences.</p>

                <?php if ($saved): ?>
                    <div class="alert alert-success py-2">Settings saved successfully.</div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>

                    <div class="card bg-light border-0 mb-3">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-bullseye"></i> Research Target</div>
                        <div class="card-body py-3">
                            <label class="form-label">Target Number of Interviews</label>
                            <input type="number" name="interview_target" class="form-control" min="1"
                                   value="<?php echo (int) ($settings['interview_target'] ?? 100); ?>" required>
                            <div class="form-text">Set the total interview target. Dashboard progress will use this value.</div>
                        </div>
                    </div>

                    <div class="card bg-light border-0 mb-3">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-list"></i> Pagination</div>
                        <div class="card-body py-3">
                            <label class="form-label">Records Per Page</label>
                            <input type="number" name="pagination_size" class="form-control" min="10" max="200"
                                   value="<?php echo (int) ($settings['pagination_size'] ?? PER_PAGE); ?>" required>
                            <div class="form-text">Number of records shown per page in list views (10&ndash;200).</div>
                        </div>
                    </div>

                    <div class="card bg-light border-0 mb-3">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-ui-checks"></i> Defaults</div>
                        <div class="card-body py-3">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Default Interview Status</label>
                                    <select name="default_interview_status" class="form-select">
                                        <?php $dis = $settings['default_interview_status'] ?? 'draft'; ?>
                                        <option value="draft" <?php echo $dis === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="submitted" <?php echo $dis === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                    </select>
                                    <div class="form-text">Default status for new interviews created by Contributors.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Photo Upload Quality</label>
                                    <select name="photo_quality" class="form-select">
                                        <?php $pq = $settings['photo_quality'] ?? 'original'; ?>
                                        <option value="original" <?php echo $pq === 'original' ? 'selected' : ''; ?>>Original (no compression)</option>
                                        <option value="high" <?php echo $pq === 'high' ? 'selected' : ''; ?>>High Quality</option>
                                        <option value="medium" <?php echo $pq === 'medium' ? 'selected' : ''; ?>>Medium Quality</option>
                                        <option value="low" <?php echo $pq === 'low' ? 'selected' : ''; ?>>Low Quality (smallest file)</option>
                                    </select>
                                    <div class="form-text">Default compression for photo uploads.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
                        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
