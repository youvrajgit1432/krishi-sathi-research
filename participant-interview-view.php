<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: View Interview
 *
 * Phase 3: Interview Engine
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-media.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/config-participant-questions.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$interview = $pdo->prepare("
    SELECT pi.*, p.name AS participant_name, p.participant_id AS pid,
           p.participant_type, p.district, p.municipality, p.ward, p.phone,
           p.experience_years, p.service_area, p.main_work_area, p.organization,
           ru.full_name AS interviewer_name,
           approver.full_name AS approved_by_name
    FROM research_participant_interviews pi
    JOIN research_participants p ON pi.participant_id = p.id
    JOIN research_users ru ON pi.interviewer_id = ru.id
    LEFT JOIN research_users approver ON pi.approved_by = approver.id
    WHERE pi.id = ?
");
$interview->execute([$id]);
$interview = $interview->fetch();

if (!$interview) {
    setFlash('error', 'Interview not found.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

// RBAC
if (!canViewParticipantInterview($interview)) {
    setFlash('error', 'Interview not available.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

// Handle workflow actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participant-interview-view.php?id=' . $id);
        exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'submit' && canSubmitParticipantInterview($interview)) {
            $pdo->prepare("UPDATE research_participant_interviews SET interview_status='submitted', submitted_at=NOW(), submitted_by=? WHERE id=?")
                ->execute([currentUserId(), $id]);
            logAudit($pdo, 'participant_interview_submitted', 'participant_interview', $id, 'Submitted for approval');
            setFlash('success', 'Interview submitted for Lead approval.');
        } elseif ($action === 'approve' && canApproveParticipantInterviews()) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE research_participant_interviews SET interview_status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                ->execute([currentUserId(), $id]);
            $pdo->prepare("UPDATE research_participants SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([currentUserId(), $interview['participant_id']]);
            $pdo->commit();
            logAudit($pdo, 'participant_interview_approved', 'participant_interview', $id, 'Approved');
            logAudit($pdo, 'participant_approved', 'participant', $interview['participant_id'], 'Auto-approved via interview');
            setFlash('success', 'Interview approved.');
        } elseif ($action === 'reject' && canApproveParticipantInterviews()) {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') { setFlash('error', 'Rejection reason is required.'); }
            else {
                $pdo->prepare("UPDATE research_participant_interviews SET interview_status='rejected', rejected_at=NOW(), rejected_by=?, rejection_reason=? WHERE id=?")
                    ->execute([currentUserId(), $reason, $id]);
                logAudit($pdo, 'participant_interview_rejected', 'participant_interview', $id, 'Rejected: ' . mb_substr($reason, 0, 200));
                setFlash('success', 'Interview rejected.');
            }
        } elseif ($action === 'unlock' && canApproveParticipantInterviews()) {
            $unlockCategory = trim($_POST['unlock_category'] ?? '');
            if ($unlockCategory === 'Other' && !empty($_POST['unlock_category_other'])) {
                $unlockCategory = trim($_POST['unlock_category_other']);
            }
            $unlockReason = trim($_POST['unlock_reason'] ?? '');
            $unlockNotes = trim($_POST['unlock_notes'] ?? '');

            if ($unlockCategory === '' || $unlockCategory === '-- Select Category --') {
                setFlash('error', 'Please select an unlock category.');
            } elseif ($unlockReason === '') {
                setFlash('error', 'Unlock reason is required. Please explain why this record needs to be unlocked.');
            } elseif (mb_strlen($unlockReason) < 10) {
                setFlash('error', 'Unlock reason must be at least 10 characters.');
            } else {
                $pdo->prepare("UPDATE research_participant_interviews SET interview_status='draft', unlocked_at=NOW(), unlocked_by=? WHERE id=?")
                    ->execute([currentUserId(), $id]);
                $auditDetails = buildUnlockDetails($unlockCategory, $unlockReason, $unlockNotes);
                logAudit($pdo, 'participant_interview_unlocked', 'participant_interview', $id, $auditDetails);
                setFlash('success', 'Record Successfully Unlocked. Reason recorded. Audit updated.');
            }
        } elseif ($action === 'delete' && canDeleteParticipantInterview($interview)) {
            softDeleteRecord($pdo, 'research_participant_interviews', $id);
            logAudit($pdo, 'participant_interview_deleted', 'participant_interview', $id, 'Deleted');
            setFlash('success', 'Interview moved to recycle bin.');
            header('Location: ' . BASE_URL . '/participant-interviews.php');
            exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Action failed: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/participant-interview-view.php?id=' . $id);
    exit;
}

// Fetch role-specific responses
$responsesStmt = $pdo->prepare("SELECT * FROM research_participant_responses WHERE interview_id = ?");
$responsesStmt->execute([$id]);
$responses = $responsesStmt->fetchAll();

$isApproved = normalizeParticipantInterviewStatus($interview['interview_status'] ?? '') === 'approved';

function displaySection($label, $value) {
    if (!$value) return '';
    $items = array_map('trim', explode(',', $value));
    echo '<div class="mb-2"><span class="fw-medium small">' . htmlspecialchars($label) . ':</span> ';
    foreach ($items as $item) {
        echo '<span class="badge bg-secondary fw-normal me-1">' . htmlspecialchars($item) . '</span>';
    }
    echo '</div>';
}

$pageTitle = 'Interview #' . $id . ' - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-chat-dots"></i> Stakeholder Interview
        </h4>
        <p class="text-muted mb-0">
            <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $interview['participant_id']; ?>" class="text-decoration-none">
                <?php echo htmlspecialchars($interview['participant_name']); ?>
            </a>
            (<code><?php echo htmlspecialchars($interview['pid']); ?></code>)
            &bull; <?php echo participantTypeBadge($interview['participant_type']); ?>
            &bull; <?php echo $interview['interview_date']; ?>
            &bull; <?php echo htmlspecialchars($interview['interviewer_name']); ?>
            &bull; <?php echo participantInterviewStatusBadge($interview['interview_status'] ?? 'draft', isDeletedRow($interview)); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!$isApproved && canEditParticipantInterview($interview)): ?>
            <a href="<?php echo BASE_URL; ?>/participant-interview-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/participant-interview-add.php" class="btn btn-success btn-sm"><i class="bi bi-plus-circle"></i> Create Another</a>
        <a href="<?php echo BASE_URL; ?>/participant-interviews.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i> All</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Print"><i class="bi bi-printer"></i></button>
    </div>
</div>

<?php if ($isApproved && isContributor()): ?>
<div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3">
    <i class="bi bi-shield-check fs-5"></i>
    <div><strong>Approved by Research Lead</strong> &mdash; This interview is part of the protected dataset.
    </div>
</div>
<?php endif; ?>

<?php if ($isApproved): ?>
    <?php echo renderApprovalSummaryCard($interview, 'participant_interview', $interview['approved_by_name'] ?? ''); ?>
<?php endif; ?>

<?php
// Fetch media count for timeline (reused later by media gallery section)
$mediaItems = buildMediaGallery($pdo, ['participant_id' => $interview['participant_id']]);
echo renderRecordTimeline($interview, 'participant_interview', [
    'media_count' => count($mediaItems),
    'consent_status' => '',
    'observation_count' => 0,
]);
?>

<!-- Unlock History -->
<?php echo renderUnlockHistory($pdo, 'participant_interview', $id); ?>

<?php if (!isDeletedRow($interview)): ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <?php echo csrf_field(); ?>
            <?php if (!$isApproved && canSubmitParticipantInterview($interview)): ?>
                <button name="action" value="submit" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Submit for Approval</button>
            <?php endif; ?>
            <?php if (canApproveParticipantInterviews()): ?>
                <?php if (normalizeParticipantInterviewStatus($interview['interview_status']) === 'submitted'): ?>
                    <button name="action" value="approve" class="btn btn-sm btn-success"><i class="bi bi-shield-check"></i> Approve</button>
                    <div class="input-group input-group-sm" style="max-width:420px;">
                        <input type="text" name="rejection_reason" class="form-control" placeholder="Rejection reason">
                        <button name="action" value="reject" class="btn btn-outline-warning">Reject</button>
                    </div>
                <?php endif; ?>
                <?php if ($isApproved): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning"
                        data-bs-toggle="modal" data-bs-target="#unlockModal">
                        <i class="bi bi-unlock"></i> Unlock
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$isApproved && canDeleteParticipantInterview($interview)): ?>
                <button name="action" value="delete" class="btn btn-sm btn-outline-danger ms-auto" onclick="return confirm('Move to recycle bin?')"><i class="bi bi-trash"></i> Delete</button>
            <?php endif; ?>
        </form>
        <?php if (!empty($interview['rejection_reason'])): ?>
            <div class="alert alert-warning py-2 small mt-2 mb-0"><strong>Rejection:</strong> <?php echo htmlspecialchars($interview['rejection_reason']); ?></div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-danger py-2"><?php echo participantInterviewStatusBadge($interview['interview_status'], true); ?> This interview is in the recycle bin.</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Participant & Interview Info -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-person"></i> Participant</div>
            <div class="card-body py-2">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted" style="width:100px;">Name</th><td><?php echo htmlspecialchars($interview['participant_name']); ?></td></tr>
                    <tr><th class="text-muted">ID</th><td><code><?php echo htmlspecialchars($interview['pid']); ?></code></td></tr>
                    <tr><th class="text-muted">Type</th><td><?php echo participantTypeBadge($interview['participant_type']); ?></td></tr>
                    <tr><th class="text-muted">Phone</th><td><?php echo htmlspecialchars($interview['phone'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Organization</th><td><?php echo htmlspecialchars($interview['organization'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Experience</th><td><?php echo htmlspecialchars($interview['experience_years'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">District</th><td><?php echo htmlspecialchars($interview['district'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Municipality</th><td><?php echo htmlspecialchars($interview['municipality'] ?: '-'); ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-info-circle"></i> Interview</div>
            <div class="card-body py-2">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted" style="width:100px;">Date</th><td><?php echo $interview['interview_date']; ?></td></tr>
                    <tr><th class="text-muted">Interviewer</th><td><?php echo htmlspecialchars($interview['interviewer_name']); ?></td></tr>
                    <tr><th class="text-muted">Location</th><td><?php echo htmlspecialchars($interview['location'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Duration</th><td><?php echo $interview['duration_minutes'] ? $interview['duration_minutes'] . ' min' : '-'; ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><?php echo participantInterviewStatusBadge($interview['interview_status'] ?? 'draft', false); ?></td></tr>
                    <?php if ($interview['latitude'] && $interview['longitude']): ?>
                        <tr><th class="text-muted">GPS</th><td><code><?php echo $interview['latitude']; ?>, <?php echo $interview['longitude']; ?></code></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 2: Problems Observed -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-exclamation-triangle text-danger"></i> Problems Observed</div>
            <div class="card-body py-2">
                <?php displaySection('Problems', $interview['problems_observed']); ?>
                <?php if (!$interview['problems_observed']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 3: Problems Detail -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-arrow-up-circle text-warning"></i> Problems Detail</div>
            <div class="card-body py-2">
                <?php displaySection('Increasing', $interview['problems_increasing']); ?>
                <?php displaySection('Greatest Losses', $interview['problems_greatest_losses']); ?>
                <?php if (!$interview['problems_increasing'] && !$interview['problems_greatest_losses']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 4: Decision Making -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-question-circle text-info"></i> Decision Making</div>
            <div class="card-body py-2">
                <?php displaySection('Hardest', $interview['hardest_decisions']); ?>
                <?php displaySection('Why Difficult', $interview['decision_difficulty_reasons']); ?>
                <?php if (!$interview['hardest_decisions'] && !$interview['decision_difficulty_reasons']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 5: Trust & Information -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-share text-primary"></i> Trust &amp; Information</div>
            <div class="card-body py-2">
                <?php displaySection('Sources', $interview['info_sources']); ?>
                <?php displaySection('Trusted', $interview['trusted_sources']); ?>
                <?php displaySection('Late Info', $interview['info_arrives_late']); ?>
                <?php if (!$interview['info_sources'] && !$interview['trusted_sources'] && !$interview['info_arrives_late']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 6: Record Keeping -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-journal-text"></i> Record Keeping</div>
            <div class="card-body py-2">
                <?php displaySection('Methods', $interview['record_keeping_methods']); ?>
                <?php displaySection('Important', $interview['important_records']); ?>
                <?php displaySection('Barriers', $interview['record_keeping_barriers']); ?>
                <?php if (!$interview['record_keeping_methods'] && !$interview['important_records'] && !$interview['record_keeping_barriers']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 7: Technology Adoption -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-phone"></i> Technology Adoption</div>
            <div class="card-body py-2">
                <?php displaySection('Used', $interview['technologies_used']); ?>
                <?php displaySection('Barriers', $interview['tech_adoption_barriers']); ?>
                <?php if (!$interview['technologies_used'] && !$interview['tech_adoption_barriers']): ?><p class="text-muted small mb-0">-</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 8: Voice & Validation -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-mic text-success"></i> Voice &amp; Krishi Sathi Validation</div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <div class="col-md-4">
                        <strong>Voice useful?</strong>
                        <?php
                        $vru = $interview['voice_recording_useful'];
                        echo $vru === 'yes' ? '<span class="badge bg-success">Yes</span>' :
                            ($vru === 'maybe' ? '<span class="badge bg-warning text-dark">Maybe</span>' :
                            ($vru === 'no' ? '<span class="badge bg-danger">No</span>' : '<span class="text-muted">-</span>'));
                        ?>
                        <?php displaySection('Uses', $interview['voice_recording_uses']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Reminders valuable?</strong>
                        <?php $rv = $interview['reminders_valuable'];
                        echo $rv === 'yes' ? '<span class="badge bg-success">Yes</span>' :
                            ($rv === 'maybe' ? '<span class="badge bg-warning text-dark">Maybe</span>' :
                            ($rv === 'no' ? '<span class="badge bg-danger">No</span>' : '<span class="text-muted">-</span>'));
                        ?>
                        <?php displaySection('Types', $interview['useful_reminders']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Weekly recs useful?</strong>
                        <?php $wru = $interview['weekly_recs_useful'];
                        echo $wru === 'yes' ? '<span class="badge bg-success">Yes</span>' :
                            ($wru === 'maybe' ? '<span class="badge bg-warning text-dark">Maybe</span>' :
                            ($wru === 'no' ? '<span class="badge bg-danger">No</span>' : '<span class="text-muted">-</span>'));
                        ?>
                        <?php displaySection('Recommendations', $interview['valuable_recommendations']); ?>
                    </div>
                    <div class="col-12 mt-2"><?php displaySection('Most Valuable Feature', $interview['most_valuable_feature']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 9: Role-Specific Responses -->
    <?php if (!empty($responses)): ?>
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-person-badge"></i> Role-Specific Responses</div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <?php foreach ($responses as $r): ?>
                        <div class="col-md-6">
                            <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $r['question_key']))); ?>:</strong>
                            <div><?php echo htmlspecialchars($r['response_value']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section 10: Research Findings -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-journal-richtext"></i> Research Findings</div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <?php if ($interview['interview_summary']): ?>
                        <div class="col-md-6"><strong>Summary:</strong><p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($interview['interview_summary'])); ?></p></div>
                    <?php endif; ?>
                    <?php if ($interview['major_findings']): ?>
                        <div class="col-md-6"><strong>Major Findings:</strong><p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($interview['major_findings'])); ?></p></div>
                    <?php endif; ?>
                    <?php if ($interview['contradictions']): ?>
                        <div class="col-md-4"><strong>Contradictions:</strong><p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($interview['contradictions'])); ?></p></div>
                    <?php endif; ?>
                    <?php if ($interview['new_research_opportunities']): ?>
                        <div class="col-md-4"><strong>New Opportunities:</strong><p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($interview['new_research_opportunities'])); ?></p></div>
                    <?php endif; ?>
                    <?php if ($interview['product_opportunities']): ?>
                        <div class="col-md-4"><strong>Product Opportunities:</strong><p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($interview['product_opportunities'])); ?></p></div>
                    <?php endif; ?>
                    <div class="col-12">
                        <?php displaySection('Actions', $interview['recommended_action']); ?>
                        <?php if ($interview['research_importance']): ?>
                            <span class="badge bg-info me-1">Importance: <?php echo htmlspecialchars($interview['research_importance']); ?></span>
                        <?php endif; ?>
                        <?php if ($interview['interview_quality']): ?>
                            <span class="badge bg-primary me-1">Quality: <?php echo htmlspecialchars($interview['interview_quality']); ?></span>
                        <?php endif; ?>
                        <?php if ($interview['follow_up_needed']): ?>
                            <span class="badge bg-warning text-dark">Follow-up Needed</span>
                            <?php if ($interview['follow_up_notes']): ?><p class="text-muted small mt-1"><?php echo nl2br(htmlspecialchars($interview['follow_up_notes'])); ?></p><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Media Gallery (unified: photos + audio + video) -->
<?php
// $mediaItems already fetched above for the timeline
$isLead = isLead();
?>
<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
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
            echo implode(', ', $parts);
        ?></span>
    </div>
    <div class="card-body py-2">
        <?php include __DIR__ . '/inc/media-gallery.php'; ?>
    </div>
</div>

<p class="text-muted small mt-3">
    Recorded: <span title="<?php echo $interview['created_at']; ?>"><?php echo date('M j, Y g:i A', strtotime($interview['created_at'])); ?></span>
    <?php if (!empty($interview['updated_at']) && $interview['updated_at'] !== $interview['created_at']): ?>
        &bull; Last updated: <span title="<?php echo $interview['updated_at']; ?>"><?php echo date('M j, Y g:i A', strtotime($interview['updated_at'])); ?></span>
    <?php endif; ?>
</p>

<!-- ═══ Print Styles ═══ -->
<style media="print">
    body { font-size: 11pt; color: #000; }
    .navbar, .bottom-nav, .btn, form, .step-nav, .step-indicator,
    .card-header .btn, .d-flex.gap-2 > .btn,
    .media-thumb-link, button[onclick^="deleteMediaItem"] { display: none !important; }
    .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
    .card-header { background: #f5f5f5 !important; color: #000 !important; }
    .badge { border: 1px solid #999 !important; color: #000 !important; background: #eee !important; }
    .table { font-size: 10pt; }
    .container { max-width: 100% !important; padding: 0 !important; }
    a { color: #000 !important; text-decoration: underline !important; }
    .text-muted { color: #555 !important; }
    @page { margin: 1.5cm; }
</style>

<!-- ═══ Unlock Modal (ROLE-ACCESS-01.2) ═══ -->
<div class="modal fade" id="unlockModal" tabindex="-1" aria-labelledby="unlockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="unlock">
                <div class="modal-header bg-warning-subtle">
                    <h5 class="modal-title" id="unlockModalLabel"><i class="bi bi-unlock"></i> Unlock Research Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="unlockCategory" class="form-label small fw-medium">Reason Category <span class="text-danger">*</span></label>
                        <select name="unlock_category" id="unlockCategory" class="form-select form-select-sm" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach (UNLOCK_CATEGORIES as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="otherCategoryField" style="display:none;">
                        <label for="unlockCategoryOther" class="form-label small fw-medium">Specify Category</label>
                        <input type="text" name="unlock_category_other" id="unlockCategoryOther" class="form-control form-control-sm" placeholder="e.g., Compliance Update">
                    </div>
                    <div class="mb-3">
                        <label for="unlockReason" class="form-label small fw-medium">Reason <span class="text-danger">*</span></label>
                        <textarea name="unlock_reason" id="unlockReason" class="form-control form-control-sm" rows="3"
                            minlength="10" maxlength="500" required
                            placeholder="Explain why this record needs to be unlocked (min 10 characters)"></textarea>
                        <small class="text-muted" id="reasonCharCount">0 / 500</small>
                    </div>
                    <div class="mb-2">
                        <label for="unlockNotes" class="form-label small fw-medium">Additional Notes (Optional)</label>
                        <textarea name="unlock_notes" id="unlockNotes" class="form-control form-control-sm" rows="2" maxlength="500" placeholder="Any additional context"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-unlock"></i> Unlock Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo ASSETS_URL; ?>/js/unlock-modal.js"></script>

<?php include __DIR__ . '/footer.php'; ?>
