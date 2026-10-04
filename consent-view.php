<?php
/**
 * Krishi Sathi Research System - View Consent Record
 * Phase 2C: Evidence & Ethics Infrastructure
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();

// ROLE-ACCESS-01.3: Only Research Lead and Editor can view consent details
if (!canViewConsent()) {
    logUnauthorizedAccess($pdo, 'view_consent_detail', 'consent', $id, 'Contributor attempted to view consent details');
    setFlash('error', 'Access denied. Consent records contain confidential research data and are not accessible to Research Contributors.');
    header('Location: ' . BASE_URL . '/consent.php');
    exit;
}

if (!canRecordConsent()) {
    setFlash('error', 'Viewers cannot view consent records.');
    header('Location: ' . BASE_URL . '/consent.php');
    exit;
}
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT cr.*, rf.name AS farmer_name, rf.farmer_id AS farmer_code,
                              p.name AS participant_name, p.participant_id AS participant_code,
                              ru.full_name AS researcher_name,
                              wdru.full_name AS withdrawn_by_name
                       FROM research_consent_records cr
                       LEFT JOIN research_farmers rf ON cr.farmer_id = rf.id
                       LEFT JOIN research_participants p ON cr.participant_id = p.id
                       JOIN research_users ru ON cr.researcher_id = ru.id
                       LEFT JOIN research_users wdru ON cr.withdrawn_by = wdru.id
                       WHERE cr.id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    setFlash('error', 'Consent record not found.');
    header('Location: ' . BASE_URL . '/consent.php');
    exit;
}

// Check if form file exists
$formFileUrl = null;
if ($record['consent_form_filename']) {
    $formFileUrl = UPLOADS_ROOT . '/' . $record['consent_form_filename'];
    $fullPath = __DIR__ . '/../' . $record['consent_form_filename'];
    if (!file_exists($fullPath)) {
        $formFileUrl = null;
    }
}

$pageTitle = 'Consent Record - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-shield-check text-success"></i> Consent Record #<?php echo $id; ?></h4>
        <p class="text-muted mb-0">Recorded <?php echo date('M j, Y g:i A', strtotime($record['created_at'])); ?></p>
    </div>
    <a href="<?php echo BASE_URL; ?>/consent.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Consent Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width:140px;">Status</th>
                            <td><?php echo consentBadge($record); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Consent Date</th>
                            <td><?php echo date('F j, Y', strtotime($record['consent_date'])); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Type</th>
                            <td><span class="badge bg-info"><?php echo ucfirst($record['consent_type']); ?></span></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Method</th>
                            <td><?php
                                $methodLabels = ['verbal' => '🫂 Verbal', 'written_digital' => '📄 Written (Digital)', 'written_physical' => '📝 Written (Physical)', 'implied' => '🔍 Implied'];
                                echo $methodLabels[$record['consent_method']] ?? ucfirst($record['consent_method'] ?? '-');
                            ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Researcher</th>
                            <td><?php echo htmlspecialchars($record['researcher_name']); ?></td>
                        </tr>
                        <?php if ($record['irb_reference']): ?>
                        <tr>
                            <th class="text-muted">IRB Reference</th>
                            <td><code><?php echo htmlspecialchars($record['irb_reference']); ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($record['witness_name']): ?>
                        <tr>
                            <th class="text-muted">Witness</th>
                            <td><?php echo htmlspecialchars($record['witness_name']); ?>
                                <?php if ($record['witness_relationship']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($record['witness_relationship']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($record['withdrawn_at']): ?>
                        <tr>
                            <th class="text-muted">Withdrawn At</th>
                            <td><?php echo date('M j, Y g:i A', strtotime($record['withdrawn_at'])); ?>
                                <?php if ($record['withdrawn_by_name']): ?>
                                    <br><small>by <?php echo htmlspecialchars($record['withdrawn_by_name']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Participant</div>
            <div class="card-body">
                <?php if ($record['farmer_id']): ?>
                    <p><strong>Farmer:</strong> <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $record['farmer_id']; ?>">
                        <?php echo htmlspecialchars($record['farmer_name'] ?? 'Farmer #' . $record['farmer_id']); ?>
                    </a></p>
                    <p><strong>Farmer ID:</strong> <code><?php echo htmlspecialchars($record['farmer_code'] ?? ''); ?></code></p>
                <?php elseif ($record['participant_id']): ?>
                    <p><strong>Stakeholder:</strong> <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $record['participant_id']; ?>">
                        <?php echo htmlspecialchars($record['participant_name'] ?? 'Stakeholder #' . $record['participant_id']); ?>
                    </a></p>
                    <p><strong>Participant ID:</strong> <code><?php echo htmlspecialchars($record['participant_code'] ?? ''); ?></code></p>
                <?php else: ?>
                    <p class="text-muted">Not linked to a specific participant.</p>
                <?php endif; ?>

                <?php if ($formFileUrl): ?>
                    <hr>
                    <p><strong>Consent Form:</strong></p>
                    <div class="d-flex gap-2">
                        <a href="<?php echo $formFileUrl; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="bi bi-eye"></i> View Form
                        </a>
                        <a href="<?php echo $formFileUrl; ?>" class="btn btn-sm btn-outline-success" download="<?php echo htmlspecialchars($record['consent_form_original_name'] ?? 'consent-form'); ?>">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                    <?php if ($record['consent_form_original_name']): ?>
                        <small class="text-muted d-block mt-1">Original: <?php echo htmlspecialchars($record['consent_form_original_name']); ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($record['notes']): ?>
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Notes</div>
            <div class="card-body">
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
