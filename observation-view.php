<?php
/**
 * Krishi Sathi Research System - View Observation (Phase 2)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-media.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$obs = $pdo->prepare("
    SELECT o.*,
           COALESCE(rf.name, rp.name) AS entity_name,
           rf.farmer_id AS farmer_code, rp.participant_id AS participant_code,
           ru.full_name AS observer_name
    FROM research_observations o
    LEFT JOIN research_farmers rf ON o.farmer_id = rf.id
    LEFT JOIN research_participants rp ON o.participant_id = rp.id
    JOIN research_users ru ON o.observer_id = ru.id
    WHERE o.id = ?
");
$obs->execute([$id]);
$obs = $obs->fetch();

if (!$obs) {
    setFlash('error', 'Observation not found.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

// ROLE-ACCESS-01.3: Contributors cannot view observations linked to approved interviews
if (!canViewObservation($obs)) {
    logUnauthorizedAccess($pdo, 'view_observation', 'observation', $id, 'Contributor attempted to view approved/restricted observation');
    setFlash('error', 'Access denied. Approved research records are part of the protected dataset and are not accessible to Research Contributors.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

// Media — unified gallery
$mediaItems = buildMediaGallery($pdo, ['observation_id' => $id]);

// Linked interview
$interview = null;
if ($obs['interview_id']) {
    $stmt = $pdo->prepare("SELECT ri.*, ru.full_name AS interviewer FROM research_interviews ri JOIN research_users ru ON ri.interviewer_id = ru.id WHERE ri.id = ?");
    $stmt->execute([$obs['interview_id']]);
    $interview = $stmt->fetch();
}

// Virtual field for isRecordLocked(): if linked interview is approved, mark observation as locked
if ($interview && normalizeInterviewStatus($interview['interview_status']) === 'approved') {
    $obs['interview_status'] = 'approved';
}

// Parse comma-separated values
$smartphone = $obs['observed_smartphone_usage'] ? array_map('trim', explode(',', $obs['observed_smartphone_usage'])) : [];
$records    = $obs['observed_record_books'] ? array_map('trim', explode(',', $obs['observed_record_books'])) : [];
$tech       = $obs['observed_technology'] ? array_map('trim', explode(',', $obs['observed_technology'])) : [];
$fcDetails  = $obs['farm_condition_details'] ? array_map('trim', explode(',', $obs['farm_condition_details'])) : [];

$pageTitle = 'Observation - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<?php if (isRecordLocked($obs)): ?>
<div class="alert alert-success d-flex align-items-center gap-2 py-2">
    <i class="bi bi-shield-check fs-5"></i>
    <div>
        <strong>Observation Linked to Approved Interview</strong> &mdash;
        This observation is part of an approved research record and is read-only.
    </div>
</div>
<?php endif; ?>

<?php if (isRecordLocked($obs)): ?>
    <?php echo renderApprovalSummaryCard($obs, 'interview', $interview['approved_by_name'] ?? ''); ?>
<?php endif; ?>

<?php
$obsObservationCount = $obs['interview_id'] ? 1 : 0;
echo renderRecordTimeline($obs, 'farmer', [
    'media_count' => count($mediaItems),
    'observation_count' => $obsObservationCount,
]);

// Unlock History — show linked interview's unlock history if available
if ($interview && !empty($interview['id'])) {
    echo renderUnlockHistory($pdo, 'interview', (int) $interview['id']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-binoculars"></i> Field Observation
        </h4>
        <p class="text-muted mb-0">
            <?php echo $obs['observation_date']; ?>
            &bull; Observer: <?php echo htmlspecialchars($obs['observer_name']); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canEditObservation($obs) && !isRecordLocked($obs)): ?>
            <a href="<?php echo BASE_URL; ?>/observation-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
        <?php if (canDelete() && !isRecordLocked($obs)): ?>
            <form method="post" action="<?php echo BASE_URL; ?>/observation-edit.php?id=<?php echo $id; ?>" class="d-inline" onsubmit="return confirm('Delete this observation and all its photos? This cannot be undone.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_observation">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </form>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/observations.php" class="btn btn-outline-secondary">
            <i class="bi bi-list"></i> All Observations
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- Subject Info -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-person"></i> Subject</div>
            <div class="card-body py-2">
                <?php if ($obs['farmer_id']): ?>
                    <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $obs['farmer_id']; ?>" class="fw-medium text-decoration-none">
                        <?php echo htmlspecialchars($obs['entity_name']); ?>
                    </a>
                    <small class="d-block text-muted"><?php echo htmlspecialchars($obs['farmer_code']); ?></small>
                <?php elseif ($obs['participant_id']): ?>
                    <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $obs['participant_id']; ?>" class="fw-medium text-decoration-none">
                        <?php echo htmlspecialchars($obs['entity_name']); ?>
                    </a>
                    <small class="d-block text-muted"><?php echo htmlspecialchars($obs['participant_code']); ?></small>
                <?php endif; ?>
                <?php if ($interview): ?>
                    <div class="mt-2">
                        <span class="badge bg-info"><i class="bi bi-chat-dots"></i>
                            <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $obs['interview_id']; ?>" class="text-white text-decoration-none">Linked to Interview #<?php echo $obs['interview_id']; ?></a>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Farm Condition & Crop Health -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-tree"></i> Farm Condition &amp; Crop Health</div>
            <div class="card-body py-2">
                <div class="row g-1 small">
                    <div class="col-6">
                        <span class="text-muted">Crop Health:</span><br>
                        <?php if ($obs['crop_health_assessment']): ?>
                            <span class="badge bg-<?php echo $obs['crop_health_assessment'] === 'excellent' ? 'success' : ($obs['crop_health_assessment'] === 'good' ? 'info' : ($obs['crop_health_assessment'] === 'fair' ? 'warning text-dark' : 'danger')); ?>"><?php echo ucfirst($obs['crop_health_assessment']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Pest/Disease:</span><br>
                        <?php if ($obs['pest_disease_presence']): ?>
                            <span class="badge bg-<?php echo $obs['pest_disease_presence'] === 'yes' ? 'danger' : 'success'; ?>"><?php echo ucfirst($obs['pest_disease_presence']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted">Water Source:</span><br>
                        <?php if ($obs['water_source']): ?>
                            <span class="badge bg-primary"><?php echo ucfirst($obs['water_source']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted">Overall:</span><br>
                        <?php if ($obs['farm_condition']): ?>
                            <span class="badge bg-<?php echo $obs['farm_condition'] === 'Well-maintained' ? 'success' : ($obs['farm_condition'] === 'Moderate' ? 'warning text-dark' : 'danger'); ?>"><?php echo htmlspecialchars($obs['farm_condition']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($fcDetails)): ?>
                    <div class="mt-2 small">
                        <?php foreach ($fcDetails as $d): ?>
                            <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars($d); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <!-- Smartphone Usage -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-phone"></i> Smartphone Usage</div>
            <div class="card-body py-2">
                <?php if (!empty($smartphone)): ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($smartphone as $s): ?>
                            <li><i class="bi bi-check-circle text-success"></i> <?php echo htmlspecialchars($s); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0 small">Not recorded</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Record Books -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-journal-text"></i> Record Books</div>
            <div class="card-body py-2">
                <?php if (!empty($records)): ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($records as $r): ?>
                            <li><i class="bi bi-check-circle text-success"></i> <?php echo htmlspecialchars($r); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0 small">Not recorded</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Technology -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-router"></i> Technology</div>
            <div class="card-body py-2">
                <?php if (!empty($tech)): ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($tech as $t): ?>
                            <li><i class="bi bi-check-circle text-success"></i> <?php echo htmlspecialchars($t); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0 small">Not recorded</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Notes -->
<?php if (trim($obs['researcher_notes'] ?? '') || trim($obs['general_notes'] ?? '')): ?>
<div class="row g-3 mt-2">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-pencil-square"></i> Researcher Notes</div>
            <div class="card-body py-2">
                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($obs['researcher_notes'])); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-journal"></i> General Notes</div>
            <div class="card-body py-2">
                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($obs['general_notes'])); ?></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Media Gallery (unified: photos + audio + video) -->
<?php
// Permission for delete buttons in media gallery
$canDeleteMedia = canEditObservation($obs);
?>
<?php if (count($mediaItems) > 0 || true): ?>
<div class="mt-3">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-images"></i> Media Gallery (<?php echo count($mediaItems); ?>)</span>
            <span class="small text-muted"><?php
                $imgC = 0; $audC = 0; $vidC = 0;
                foreach ($mediaItems as $m) {
                    if ($m['type'] === 'image') $imgC++;
                    elseif ($m['type'] === 'audio') $audC++;
                    elseif ($m['type'] === 'video') $vidC++;
                }
                $parts = [];
                if ($imgC) $parts[] = "$imgC photo" . ($imgC > 1 ? 's' : '');
                if ($audC) $parts[] = "$audC audio" . ($audC > 1 ? 's' : '');
                if ($vidC) $parts[] = "$vidC video" . ($vidC > 1 ? 's' : '');
                echo implode(', ', $parts) ?: 'none';
            ?></span>
        </div>
        <div class="card-body">
            <?php include __DIR__ . '/inc/media-gallery.php'; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
