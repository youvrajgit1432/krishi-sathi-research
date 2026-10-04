<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: View Participant
 *
 * Phase 2: Participant CRUD
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$participant = $pdo->prepare("SELECT p.*, deleter.full_name AS deleted_by_name, approver.full_name AS approved_by_name
                              FROM research_participants p
                              LEFT JOIN research_users deleter ON p.deleted_by = deleter.id
                              LEFT JOIN research_users approver ON p.approved_by = approver.id
                              WHERE p.id = ?");
$participant->execute([$id]);
$participant = $participant->fetch();

if (!$participant) {
    setFlash('error', 'Stakeholder not found.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

// RBAC
if (!canViewParticipant($participant)) {
    setFlash('error', 'Access denied. You can only view stakeholders you created.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

$isDeleted = isDeletedRow($participant);
$isApproved = ($participant['approval_status'] ?? '') === 'approved';

// Interviews for this participant
$interviews = $pdo->prepare("
    SELECT pi.*, ru.full_name AS interviewer_name
    FROM research_participant_interviews pi
    JOIN research_users ru ON pi.interviewer_id = ru.id
    WHERE pi.participant_id = ? AND " . activeWhere('pi') . "
    ORDER BY pi.interview_date DESC
");
$interviews->execute([$id]);
$interviews = $interviews->fetchAll();

// Parse comma-separated fields for display
$serviceAreas = !empty($participant['service_area']) ? explode(', ', $participant['service_area']) : [];
$mainWorkAreas = !empty($participant['main_work_area']) ? explode(', ', $participant['main_work_area']) : [];

$pageTitle = 'Stakeholder: ' . $participant['name'] . ' - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<?php if ($isDeleted): ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <strong><i class="bi bi-trash"></i> Deleted Stakeholder</strong>
        — Sent to recycle bin <?php echo date('M j, Y g:i a', strtotime($participant['deleted_at'])); ?>
        <?php if (!empty($participant['deleted_by_name'])): ?>
            by <?php echo htmlspecialchars($participant['deleted_by_name']); ?>
        <?php endif; ?>
    </div>
    <?php if (isLead()): ?>
        <form method="post" action="<?php echo BASE_URL; ?>/participants.php" class="d-inline" onsubmit="return confirm('Restore this stakeholder?')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="single_action" value="restore">
            <input type="hidden" name="record_id" value="<?php echo $id; ?>">
            <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-arrow-counterclockwise"></i> Restore
            </button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isApproved && !$isDeleted): ?>
<div class="alert alert-success d-flex align-items-center gap-2 py-2">
    <i class="bi bi-shield-check fs-5"></i>
    <div>
        <strong>Approved by Research Lead</strong> &mdash;
        This stakeholder's data has been reviewed and approved.
        The record is now part of the protected dataset and is view-only.
    </div>
</div>
<?php echo renderApprovalSummaryCard($participant, 'participant', $participant['approved_by_name'] ?? ''); ?>
<?php endif; ?>

<?php
$participantConsent = getParticipantConsent($pdo, $id);
echo renderRecordTimeline($participant, 'participant', [
    'consent_status' => $participantConsent['consent_status'] ?? '',
    'media_count' => 0,
    'observation_count' => 0,
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <?php if ($isDeleted): ?><span class="text-danger"><i class="bi bi-trash"></i></span> <?php endif; ?>
            <i class="bi bi-person"></i>
            <?php echo htmlspecialchars($participant['name']); ?>
        </h4>
        <p class="text-muted mb-0">
            ID: <code><?php echo htmlspecialchars($participant['participant_id']); ?></code>
            &bull; <?php echo participantTypeBadge($participant['participant_type']); ?>
            &bull; <?php echo count($interviews); ?> interview(s)
            &bull; <?php echo consentBadge(getParticipantConsent($pdo, $id)); ?>
        </p>
        <p class="text-muted small" style="font-size:0.75rem;">
            <i class="bi bi-calendar-plus"></i> Registered: <span title="<?php echo $participant['created_at']; ?>"><?php echo date('M j, Y g:i A', strtotime($participant['created_at'])); ?></span>
            <?php if (!empty($participant['updated_at']) && $participant['updated_at'] !== $participant['created_at']): ?>
                &bull; <i class="bi bi-pencil-square"></i> Last updated: <span title="<?php echo $participant['updated_at']; ?>"><?php echo date('M j, Y g:i A', strtotime($participant['updated_at'])); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canDelete() && !$isDeleted && !$isApproved): ?>
            <form method="post" action="<?php echo BASE_URL; ?>/participants.php" class="d-inline"
                  onsubmit="return confirm('Move <?php echo str_replace("'", "\\'", $participant['name']); ?> to recycle bin? Their interviews will be preserved.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="single_action" value="delete">
                <input type="hidden" name="record_id" value="<?php echo $id; ?>">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </form>
        <?php endif; ?>
        <?php if (!isViewer() && !$isDeleted && !$isApproved): ?>
            <a href="<?php echo BASE_URL; ?>/participant-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <a href="<?php echo BASE_URL; ?>/consent-add.php?participant_id=<?php echo $id; ?>" class="btn btn-outline-success">
                <i class="bi bi-shield-check"></i> Consent
            </a>
        <?php endif; ?>
        <?php if (!$isDeleted && !$isApproved): ?>
            <a href="<?php echo BASE_URL; ?>/participant-interview-add.php?participant_id=<?php echo $id; ?>" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> New Interview
            </a>
        <?php endif; ?>
        <?php if ($isApproved): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle align-self-center px-3 py-2">
                <i class="bi bi-shield-check"></i> Read-only
            </span>
        <?php endif; ?>
        <?php if (canPrint()): ?>
        <button onclick="window.print()" class="btn btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Participant Details -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Stakeholder Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Participant ID</th>
                            <td><code><?php echo htmlspecialchars($participant['participant_id']); ?></code></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Participant Type</th>
                            <td><?php echo participantTypeBadge($participant['participant_type']); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Phone</th>
                            <td><?php echo htmlspecialchars($participant['phone'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Organization</th>
                            <td><?php echo htmlspecialchars($participant['organization'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Experience</th>
                            <td><?php echo htmlspecialchars($participant['experience_years'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Primary Role</th>
                            <td><?php echo htmlspecialchars($participant['primary_role'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">District</th>
                            <td><?php echo htmlspecialchars($participant['district'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Municipality</th>
                            <td><?php echo htmlspecialchars($participant['municipality'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Ward</th>
                            <td><?php echo htmlspecialchars($participant['ward'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Tole</th>
                            <td><?php echo htmlspecialchars($participant['tole'] ?: '-'); ?></td>
                        </tr>
                        <?php if (!empty($serviceAreas) && $serviceAreas[0] !== ''): ?>
                        <tr>
                            <th class="text-muted">Service Area</th>
                            <td><?php foreach ($serviceAreas as $sa): ?><span class="badge bg-secondary me-1"><?php echo htmlspecialchars($sa); ?></span><?php endforeach; ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($mainWorkAreas) && $mainWorkAreas[0] !== ''): ?>
                        <tr>
                            <th class="text-muted">Work Area</th>
                            <td><?php foreach ($mainWorkAreas as $wa): ?><span class="badge bg-info me-1"><?php echo htmlspecialchars($wa); ?></span><?php endforeach; ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($participant['latitude'] && $participant['longitude']): ?>
                        <tr>
                            <th class="text-muted">GPS</th>
                            <td>
                                <code><?php echo $participant['latitude']; ?>, <?php echo $participant['longitude']; ?></code>
                                <?php if ($participant['gps_accuracy']): 
                                    $acc = (float) $participant['gps_accuracy'];
                                    if ($acc > 0 && $acc <= 10): ?>
                                        <span class="badge bg-success ms-1" title="Accuracy: <?php echo $acc; ?>m">Excellent</span>
                                    <?php elseif ($acc > 10 && $acc <= 50): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="Accuracy: <?php echo $acc; ?>m">Good</span>
                                    <?php elseif ($acc > 50): ?>
                                        <span class="badge bg-secondary ms-1" title="Accuracy: <?php echo $acc; ?>m">Poor</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($participant['gps_altitude']): ?>
                                    <br><small class="text-muted">GPS Altitude: <?php echo $participant['gps_altitude']; ?>m</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($participant['altitude']): ?>
                        <tr>
                            <th class="text-muted">Altitude</th>
                            <td><?php echo htmlspecialchars($participant['altitude']); ?>m <small class="text-muted">(manual)</small></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($participant['gps_accuracy'] && !$participant['latitude']): ?>
                        <tr>
                            <th class="text-muted">GPS Accuracy</th>
                            <td><?php echo htmlspecialchars($participant['gps_accuracy']); ?>m</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Profile Summary Card -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Profile Summary</div>
            <div class="card-body">
                <?php if (!empty($serviceAreas) && $serviceAreas[0] !== ''): ?>
                    <p><strong>Service Area:</strong></p>
                    <p><?php foreach ($serviceAreas as $sa): ?><span class="badge bg-secondary me-1 mb-1"><?php echo htmlspecialchars($sa); ?></span><?php endforeach; ?></p>
                <?php else: ?>
                    <p class="text-muted">No service area recorded.</p>
                <?php endif; ?>

                <?php if (!empty($mainWorkAreas) && $mainWorkAreas[0] !== ''): ?>
                    <p class="mt-3"><strong>Main Work Area:</strong></p>
                    <p><?php foreach ($mainWorkAreas as $wa): ?><span class="badge bg-info me-1 mb-1"><?php echo htmlspecialchars($wa); ?></span><?php endforeach; ?></p>
                <?php else: ?>
                    <p class="mt-3 text-muted">No work area recorded.</p>
                <?php endif; ?>

                <?php if ($participant['experience_years']): ?>
                    <p class="mt-3"><strong>Experience:</strong> <?php echo htmlspecialchars($participant['experience_years']); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="row g-2 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm bg-primary text-white text-center py-2">
            <div class="h3 mb-0 fw-bold"><?php echo count($interviews); ?></div>
            <small>Total Interviews</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm bg-success text-white text-center py-2">
            <div class="h3 mb-0 fw-bold"><?php
                $complete = array_filter($interviews, function($i) {
                    return in_array(normalizeParticipantInterviewStatus($i['interview_status'] ?? 'draft'), ['submitted', 'approved']);
                });
                echo count($complete);
            ?></div>
            <small>Complete</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm bg-warning text-white text-center py-2">
            <div class="h3 mb-0 fw-bold"><?php
                $voiceYes = array_filter($interviews, function($i) { return $i['voice_recording_useful'] === 'yes'; });
                echo count($voiceYes);
            ?></div>
            <small>Voice Interest</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm bg-info text-white text-center py-2">
            <div class="h3 mb-0 fw-bold"><?php echo $participant['district'] ?: '-'; ?></div>
            <small>District</small>
        </div>
    </div>
</div>

<!-- Interviews -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-chat-dots"></i> Stakeholder Interviews (<?php echo count($interviews); ?>)</span>
        <?php if (!$isApproved): ?>
        <a href="<?php echo BASE_URL; ?>/participant-interview-add.php?participant_id=<?php echo $id; ?>" class="btn btn-sm btn-success">
            <i class="bi bi-plus-circle"></i> New Interview
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (count($interviews) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Interviewer</th>
                            <th>Location</th>
                            <th class="d-none d-md-table-cell">Duration</th>
                            <th class="d-none d-md-table-cell">Status</th>
                            <th class="d-none d-md-table-cell">Voice</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interviews as $i): ?>
                            <tr>
                                <td data-label="Date"><?php echo $i['interview_date']; ?></td>
                                <td data-label="Interviewer"><?php echo htmlspecialchars($i['interviewer_name']); ?></td>
                                <td data-label="Location"><?php echo htmlspecialchars($i['location'] ?: '-'); ?></td>
                                <td class="d-none d-md-table-cell" data-label="Duration"><?php echo $i['duration_minutes'] ? $i['duration_minutes'] . ' min' : '-'; ?></td>
                                <td class="d-none d-md-table-cell" data-label="Status"><?php echo participantInterviewStatusBadge($i['interview_status'] ?? 'draft', isDeletedRow($i)); ?></td>
                                <td class="d-none d-md-table-cell" data-label="Voice">
                                    <?php if ($i['voice_recording_useful'] === 'yes'): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php elseif ($i['voice_recording_useful'] === 'maybe'): ?>
                                        <span class="badge bg-warning text-dark">Maybe</span>
                                    <?php elseif ($i['voice_recording_useful'] === 'no'): ?>
                                        <span class="badge bg-danger">No</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/participant-interview-view.php?id=<?php echo $i['id']; ?>"
                                       class="btn btn-sm btn-outline-primary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (canEditParticipantInterview($i) && !$isDeleted): ?>
                                        <a href="<?php echo BASE_URL; ?>/participant-interview-edit.php?id=<?php echo $i['id']; ?>"
                                           class="btn btn-sm btn-outline-secondary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card-body text-center py-4">
                <p class="text-muted mb-2">No interviews conducted with this stakeholder yet.</p>
                <?php if (!$isApproved): ?>
                <a href="<?php echo BASE_URL; ?>/participant-interview-add.php?participant_id=<?php echo $id; ?>" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Conduct First Interview
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ Print Styles ═══ -->
<style media="print">
    body { font-size: 11pt; color: #000; }
    .navbar, .bottom-nav, .btn, form, .step-nav, .step-indicator,
    .card-header .btn, .d-flex.gap-2 > .btn { display: none !important; }
    .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
    .card-header { background: #f5f5f5 !important; color: #000 !important; }
    .badge { border: 1px solid #999 !important; color: #000 !important; background: #eee !important; }
    .table { font-size: 10pt; }
    .container { max-width: 100% !important; padding: 0 !important; }
    a { color: #000 !important; text-decoration: underline !important; }
    .text-muted { color: #555 !important; }
    @page { margin: 1.5cm; }
</style>
<?php include __DIR__ . '/footer.php'; ?>
