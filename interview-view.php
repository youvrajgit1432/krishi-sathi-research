<?php
/**
 * Krishi Sathi Research System - View Interview (Refined)
 * Displays all fields including assumption validation and new sections.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-media.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$interview = $pdo->prepare("
    SELECT ri.*, rf.name AS farmer_name, rf.farmer_id AS fid,
           rf.district, rf.municipality, rf.ward,
           rf.phone, rf.age_group, rf.gender,
           ru.full_name AS interviewer_name
    FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    JOIN research_users ru ON ri.interviewer_id = ru.id
    WHERE ri.id = ?
");
$interview->execute([$id]);
$interview = $interview->fetch();

if (!$interview) {
    setFlash('error', 'Interview not found.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

// ROLE-ACCESS-01.3: Contributors cannot view approved interviews
if (!canViewInterview($interview)) {
    logUnauthorizedAccess($pdo, 'view_interview', 'interview', $id, 'Contributor attempted to view approved/restricted interview');
    setFlash('error', 'Access denied. Approved research records are part of the protected dataset and are not accessible to Research Contributors.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-view.php?id=' . $id);
        exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'submit' && canSubmitInterview($interview)) {
            $pdo->prepare("UPDATE research_interviews SET interview_status='submitted', submitted_at=NOW(), submitted_by=? WHERE id=?")
                ->execute([currentUserId(), $id]);
            logAudit($pdo, 'interview_submitted', 'interview', $id, 'Submitted for approval');
            setFlash('success', 'Interview submitted for Research Lead approval.');
        } elseif ($action === 'approve' && canApproveInterviews()) {
            $pdo->prepare("UPDATE research_interviews SET interview_status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                ->execute([currentUserId(), $id]);
            logAudit($pdo, 'interview_approved', 'interview', $id, 'Approved');
            setFlash('success', 'Interview approved and locked.');
        } elseif ($action === 'reject' && canApproveInterviews()) {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') {
                setFlash('error', 'Rejection reason is required.');
            } else {
                $pdo->prepare("UPDATE research_interviews SET interview_status='rejected', rejected_at=NOW(), rejected_by=?, rejection_reason=? WHERE id=?")
                    ->execute([currentUserId(), $reason, $id]);
                logAudit($pdo, 'interview_rejected', 'interview', $id, 'Rejected: ' . mb_substr($reason, 0, 200));
                setFlash('success', 'Interview rejected and returned to creator.');
            }
        } elseif ($action === 'unlock' && canApproveInterviews()) {
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
                $pdo->prepare("UPDATE research_interviews SET interview_status='draft', unlocked_at=NOW(), unlocked_by=? WHERE id=?")
                    ->execute([currentUserId(), $id]);
                $auditDetails = buildUnlockDetails($unlockCategory, $unlockReason, $unlockNotes);
                logAudit($pdo, 'interview_unlocked', 'interview', $id, $auditDetails);
                setFlash('success', 'Record Successfully Unlocked. Reason recorded. Audit updated.');
            }
        } elseif ($action === 'delete' && canDeleteInterview($interview)) {
            softDeleteRecord($pdo, 'research_interviews', $id);
            logAudit($pdo, 'interview_deleted', 'interview', $id, 'Deleted (soft)');
            setFlash('success', 'Interview moved to recycle bin.');
            header('Location: ' . BASE_URL . '/interviews.php');
            exit;
        } else {
            setFlash('error', 'Action not allowed for your role or this interview status.');
        }
    } catch (PDOException $e) {
        setFlash('error', 'Workflow action failed: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/interview-view.php?id=' . $id);
    exit;
}

$problems = $pdo->prepare("SELECT * FROM research_problem_rankings WHERE interview_id = ? ORDER BY problem_number");
$problems->execute([$id]);
$problems = $problems->fetchAll();

$farmProfile = $pdo->prepare("SELECT * FROM research_farm_profiles WHERE farmer_id = ?");
$farmProfile->execute([$interview['farmer_id']]);
$farmProfile = $farmProfile->fetch();

$pageTitle = 'Interview #' . $id . ' - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-chat-dots"></i> Interview with
            <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $interview['farmer_id']; ?>" class="text-decoration-none">
                <?php echo htmlspecialchars($interview['farmer_name']); ?>
            </a>
        </h4>
        <p class="text-muted mb-0">
            <code><?php echo htmlspecialchars($interview['fid']); ?></code>
            &bull; <?php echo $interview['interview_date']; ?>
            &bull; <?php echo htmlspecialchars($interview['interviewer_name']); ?>
            <?php if (isset($interview['interview_status'])): 
                $st = normalizeInterviewStatus($interview['interview_status']);
                echo '&bull; ' . interviewStatusBadge($st, false);
            endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canEditInterview($interview)): ?>
            <a href="<?php echo BASE_URL; ?>/interview-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>            <a href="<?php echo BASE_URL; ?>/interviews.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i> All</a>
            <?php if (canPrint()): ?>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Print"><i class="bi bi-printer"></i></button>
            <?php endif; ?>
    </div>
</div>

<?php if (!isDeleted($interview)): ?>
    <!-- Approval Summary Card -->
    <?php
    // Fetch approver name
    $approverName = '';
    if (!empty($interview['approved_by'])) {
        $stmt = $pdo->prepare("SELECT full_name FROM research_users WHERE id = ?");
        $stmt->execute([$interview['approved_by']]);
        $approverName = (string) $stmt->fetchColumn();
    }
    echo renderApprovalSummaryCard($interview, 'interview', $approverName);
    ?>

    <!-- Record Timeline -->
    <?php
    // Count media and observations for timeline
    $mediaCount = (int) $pdo->prepare("SELECT COUNT(*) FROM research_media WHERE interview_id = ? AND is_deleted = 0")->execute([$id]) ? 0 : 0;
    $mediaStmt = $pdo->prepare("SELECT COUNT(*) FROM research_media WHERE interview_id = ? AND is_deleted = 0");
    $mediaStmt->execute([$id]);
    $mediaCount = (int) $mediaStmt->fetchColumn();
    echo renderRecordTimeline($interview, 'interview', ['media_count' => $mediaCount]);
    ?>

    <!-- Unlock History -->
    <?php echo renderUnlockHistory($pdo, 'interview', $id); ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <div class="mb-2"><?php echo interviewStatusBadge($interview['interview_status'], false); ?></div>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <?php echo csrf_field(); ?>
            <?php if (canSubmitInterview($interview)): ?>
                <button name="action" value="submit" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Submit for Approval</button>
            <?php endif; ?>
            <?php if (canApproveInterviews()): ?>
                <?php if (normalizeInterviewStatus($interview['interview_status']) === 'submitted'): ?>
                    <button name="action" value="approve" class="btn btn-sm btn-success"><i class="bi bi-shield-check"></i> Approve</button>
                    <div class="input-group input-group-sm" style="max-width:420px;">
                        <input type="text" name="rejection_reason" class="form-control" placeholder="Rejection reason">
                        <button name="action" value="reject" class="btn btn-outline-warning">Reject</button>
                    </div>
                <?php endif; ?>
                <?php if (normalizeInterviewStatus($interview['interview_status']) === 'approved'): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning"
                        data-bs-toggle="modal" data-bs-target="#unlockModal">
                        <i class="bi bi-unlock"></i> Unlock
                    </button>
                <?php endif; ?>
            <?php elseif (canReviewInterviews()): ?>
                <span class="text-muted small">Editor review access only. Lead approval required.</span>
            <?php endif; ?>
            <?php if (canDeleteInterview($interview)): ?>
                <button name="action" value="delete" class="btn btn-sm btn-outline-danger ms-auto" onclick="return confirm('Move this interview to recycle bin?')"><i class="bi bi-trash"></i> Delete</button>
            <?php endif; ?>
        </form>
        <?php if (!empty($interview['rejection_reason'])): ?>
            <div class="alert alert-warning py-2 small mt-2 mb-0"><strong>Rejection reason:</strong> <?php echo htmlspecialchars($interview['rejection_reason']); ?></div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
    <div class="alert alert-danger py-2"><?php echo interviewStatusBadge($interview['interview_status'], true); ?> This interview is in the recycle bin.</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Farmer & Interview Info side by side -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-person"></i> Farmer</div>
            <div class="card-body py-2">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted" style="width:100px;">Name</th><td><?php echo htmlspecialchars($interview['farmer_name']); ?></td></tr>
                    <tr><th class="text-muted">ID</th><td><code><?php echo htmlspecialchars($interview['fid']); ?></code></td></tr>
                    <tr><th class="text-muted">Phone</th><td><?php echo htmlspecialchars($interview['phone'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">District</th><td><?php echo htmlspecialchars($interview['district'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Municipality</th><td><?php echo htmlspecialchars($interview['municipality'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Ward</th><td><?php echo htmlspecialchars($interview['ward'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Age</th><td><?php echo htmlspecialchars($interview['age_group'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Gender</th><td><?php echo htmlspecialchars($interview['gender'] ?: '-'); ?></td></tr>
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
                        <tr><th class="text-muted">Round</th><td><?php echo $interview['interview_round'] ? 'Round ' . (int) $interview['interview_round'] : '-'; ?></td></tr>
                        <tr><th class="text-muted">Mode</th><td><?php echo $interview['interview_mode'] ? ucfirst(str_replace('_', ' ', $interview['interview_mode'])) : '-'; ?></td></tr>
                        <tr><th class="text-muted">Language</th><td><?php echo htmlspecialchars($interview['interview_language'] ?: '-'); ?></td></tr>
                    <?php if ($interview['latitude'] && $interview['longitude']): ?>
                        <tr><th class="text-muted">GPS</th><td>
                            <code><?php echo $interview['latitude']; ?>, <?php echo $interview['longitude']; ?></code>
                            <?php if ($interview['gps_accuracy']): 
                                $acc = (float) $interview['gps_accuracy'];
                                if ($acc > 0 && $acc <= 10): ?>
                                    <span class="badge bg-success ms-1" title="Accuracy: <?php echo $acc; ?>m">Excellent</span>
                                <?php elseif ($acc > 10 && $acc <= 50): ?>
                                    <span class="badge bg-warning text-dark ms-1" title="Accuracy: <?php echo $acc; ?>m">Good</span>
                                <?php elseif ($acc > 50): ?>
                                    <span class="badge bg-secondary ms-1" title="Accuracy: <?php echo $acc; ?>m">Poor</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php if ($interview['altitude']): ?>
                        <tr><th class="text-muted">Altitude</th><td><?php echo htmlspecialchars($interview['altitude']); ?>m <small class="text-muted">(manual)</small></td></tr>
                    <?php endif; ?>
                    <?php if ($interview['gps_altitude']): ?>
                        <tr><th class="text-muted">GPS Alt</th><td><?php echo $interview['gps_altitude']; ?>m</td></tr>
                    <?php endif; ?>
                    <?php if ($interview['follow_up_required']): ?>
                        <tr><th class="text-muted">Follow-Up</th><td><?php echo $interview['follow_up_date'] ? date('M j, Y', strtotime($interview['follow_up_date'])) : 'Scheduled'; ?><?php echo $interview['follow_up_reason'] ? ' — ' . htmlspecialchars($interview['follow_up_reason']) : ''; ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Farm Profile -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-house-heart"></i> Farm Profile</div>
            <div class="card-body py-2">
                <?php if ($farmProfile): ?>
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted" style="width:100px;">Type</th><td><?php echo htmlspecialchars($farmProfile['farm_type'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Size</th><td><?php echo htmlspecialchars($farmProfile['farm_size'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Years</th><td><?php echo htmlspecialchars($farmProfile['years_farming'] ?: '-'); ?></td></tr>
                    <tr><th class="text-muted">Commercial</th><td><?php echo $farmProfile['is_commercial'] ? '✅ Yes' : '❌ No'; ?></td></tr>
                </table>
                <?php else: ?>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle"></i>
                        No farm profile recorded for this farmer yet.
                        <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $interview['farmer_id']; ?>" class="text-decoration-none">
                            Edit this interview to add farm data
                        </a>.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Problem Analysis -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-triangle text-danger"></i> Problems</span>
                            <?php if ($interview['problem_severity']): ?>
                                <span class="badge bg-<?php echo (int) $interview['problem_severity'] >= 4 ? 'danger' : ((int) $interview['problem_severity'] >= 2 ? 'warning text-dark' : 'success'); ?>">Severity: <?php echo (int) $interview['problem_severity']; ?>/5</span>
                            <?php endif; ?>
                        </div>
            <div class="card-body py-2">
                <?php if ($interview['problems_selected']): ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php foreach (explode(',', $interview['problems_selected']) as $ps): ?>
                            <span class="badge bg-secondary fw-normal"><?php echo htmlspecialchars(trim($ps)); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (count($problems) > 0): ?>
                    <hr class="my-1">
                    <div class="small fw-medium">Top ranked:</div>
                    <?php foreach ($problems as $p): ?>
                        <div class="small"><span class="badge bg-danger">#<?php echo $p['problem_number']; ?></span> <?php echo htmlspecialchars($p['problem_description'] ?: '-'); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!$interview['problems_selected'] && count($problems) === 0): ?>
                    <p class="text-muted small mb-0">No problems recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Loss Analysis -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-arrow-down-circle"></i> Loss</div>
            <div class="card-body py-2">
                <?php if ($interview['loss_contributing_causes']): ?>
                    <div class="small fw-medium">Contributing causes:</div>
                    <div class="d-flex flex-wrap gap-1 mb-2"><?php foreach (explode(',', $interview['loss_contributing_causes']) as $lc): ?><span class="badge bg-secondary fw-normal"><?php echo htmlspecialchars(trim($lc)); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($interview['loss_cause']): ?>
                    <div class="small fw-medium">Main cause:</div>
                    <span class="badge bg-danger"><?php echo htmlspecialchars($interview['loss_cause']); ?></span>
                    <?php if ($interview['loss_amount']): ?><span class="badge bg-warning text-dark ms-1">~<?php echo htmlspecialchars($interview['loss_amount']); ?></span><?php endif; ?>
                    <?php if ($interview['loss_amount_npr']): ?><span class="badge bg-info ms-1">NPR <?php echo number_format((float) $interview['loss_amount_npr'], 2); ?></span><?php endif; ?>
                    <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($interview['loss_description'] ?: ''); ?></p>
                    <?php if ($interview['loss_period']): ?><p class="small text-muted mb-0">Period: <?php echo htmlspecialchars($interview['loss_period']); ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if (!$interview['loss_contributing_causes'] && !$interview['loss_cause']): ?>
                    <p class="text-muted small mb-0">No loss data.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Record Keeping -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-journal-text"></i> Record Keeping</div>
            <div class="card-body py-2">
                <?php if ($interview['record_keeping_method']): ?>
                    <div class="d-flex flex-wrap gap-1"><?php foreach (explode(',', $interview['record_keeping_method']) as $r): ?><span class="badge bg-secondary fw-normal"><?php echo htmlspecialchars(trim($r)); ?></span><?php endforeach; ?></div>
                <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
                <?php if ($interview['record_frequency']): ?>
                    <div class="small mt-1">Frequency: <strong><?php echo htmlspecialchars($interview['record_frequency']); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Technology -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-phone"></i> Technology</div>
            <div class="card-body py-2">
                <?php if ($interview['technology_used']): ?>
                    <div class="d-flex flex-wrap gap-1"><?php foreach (explode(',', $interview['technology_used']) as $t): ?><span class="badge bg-primary fw-normal"><?php echo htmlspecialchars(trim($t)); ?></span><?php endforeach; ?></div>
                <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
                <?php if ($interview['smartphone_independence']): ?>
                    <div class="small mt-1">Smartphone: <strong><?php echo htmlspecialchars($interview['smartphone_independence']); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Voice Validation -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-mic"></i> Voice Validation</span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#voiceCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse show" id="voiceCollapse">
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <div class="col-auto">
                        <?php $vi = $interview['voice_interest']; ?>
                        <?php if ($vi === 'yes'): ?><span class="badge bg-success fs-6">Yes</span>
                        <?php elseif ($vi === 'maybe'): ?><span class="badge bg-warning text-dark fs-6">Maybe</span>
                        <?php elseif ($vi === 'no'): ?><span class="badge bg-danger fs-6">No</span>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </div>
                    <?php if ($interview['voice_reason_options']): ?>
                        <div class="col-12"><strong>Reasons:</strong> <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach (explode(',', $interview['voice_reason_options']) as $vr): ?><span class="badge bg-success fw-normal"><?php echo htmlspecialchars(trim($vr)); ?></span><?php endforeach; ?></div></div>
                    <?php endif; ?>
                    <?php if ($interview['voice_rejection_reasons']): ?>
                        <div class="col-12"><strong>Rejection reasons:</strong> <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach (explode(',', $interview['voice_rejection_reasons']) as $vj): ?><span class="badge bg-danger fw-normal"><?php echo htmlspecialchars(trim($vj)); ?></span><?php endforeach; ?></div></div>
                    <?php endif; ?>
                    <?php if ($interview['voice_use_cases']): ?>
                        <div class="col-12"><strong>Use cases:</strong> <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach (explode(',', $interview['voice_use_cases']) as $uc): ?><span class="badge bg-success fw-normal"><?php echo htmlspecialchars(trim($uc)); ?></span><?php endforeach; ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- Reminder Validation -->
    <?php if ($interview['assumption_reminders']): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bell text-info"></i> Reminder Validation</span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#reminderCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="reminderCollapse">
            <div class="card-body py-2">
                <div class="small">Useful? <strong><?php echo ucfirst($interview['assumption_reminders']); ?></strong></div>
                <?php if ($interview['reminder_types']): ?>
                    <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach (explode(',', $interview['reminder_types']) as $rt): ?><span class="badge bg-info fw-normal"><?php echo htmlspecialchars(trim($rt)); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- App Motivation -->
    <?php if ($interview['app_motivations']): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-phone text-primary"></i> App Motivation</span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#appMotivationCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="appMotivationCollapse">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-1"><?php foreach (explode(',', $interview['app_motivations']) as $am): ?><span class="badge bg-primary fw-normal"><?php echo htmlspecialchars(trim($am)); ?></span><?php endforeach; ?></div>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recommendation Validation -->
    <?php if ($interview['recommendation_types']): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-star text-warning"></i> Recommendation Validation</span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#recommendationCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="recommendationCollapse">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-1"><?php foreach (explode(',', $interview['recommendation_types']) as $rc): ?><span class="badge bg-warning text-dark fw-normal"><?php echo htmlspecialchars(trim($rc)); ?></span><?php endforeach; ?></div>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Research Notes -->
    <?php if ($interview['interview_summary'] || $interview['important_findings']): ?>
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-richtext"></i> Research Notes</span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#notesCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="notesCollapse">
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <?php if ($interview['interview_summary']): ?>
                        <div class="col-md-6"><strong>Summary</strong><p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($interview['interview_summary'])); ?></p></div>
                    <?php endif; ?>
                    <?php if ($interview['important_findings']): ?>
                        <div class="col-md-6"><strong>Findings</strong><p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($interview['important_findings'])); ?></p></div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3">
    Recorded: <?php echo $interview['created_at']; ?>
    <?php if ($interview['updated_at'] !== $interview['created_at']): ?>&bull; Last updated: <?php echo $interview['updated_at']; ?><?php endif; ?>
</p>

<!-- Media Gallery (unified: photos + audio + video) -->
<?php
$mediaItems = buildMediaGallery($pdo, ['interview_id' => $id]);
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

<!-- Upload Section (multi-file unified) -->
<?php if (canUploadMedia($interview)): ?>
<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-upload"></i> Upload Media</div>
    <div class="card-body py-2">
        <div id="uploadArea">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Consent</label>
                    <select id="uploadConsent" class="form-select form-select-sm">
                        <option value="research">Research Use Allowed</option>
                        <option value="internal">Internal Research Only</option>
                        <option value="none">No Permission</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Caption (optional, applies to all)</label>
                    <input type="text" id="uploadCaption" class="form-control form-control-sm" placeholder="e.g., Farmer's field">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Files (select multiple)</label>
                    <input type="file" id="mediaFileInput" class="form-control form-control-sm" multiple
                           accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,audio/mpeg,audio/wav,audio/ogg,audio/aac,audio/x-m4a,video/mp4,video/quicktime,video/webm,video/x-msvideo">
                </div>
                <div class="col-12 d-flex gap-2 mt-2">
                    <button class="btn btn-sm btn-success" onclick="uploadAllFiles(<?php echo $id; ?>, 'interview')">
                        <i class="bi bi-upload"></i> Upload All Files
                    </button>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    Max file size: <?php echo formatBytes(getMediaMaxSize(MEDIA_TYPE_VIDEO)); ?>.
                    Supports JPEG, PNG, WebP, HEIC, MP3, WAV, M4A, AAC, OGG, MP4, MOV, WebM, AVI.
                </small>
            </div>
            <div class="mt-2" id="uploadProgressContainer" style="display:none;">
                <div class="progress" style="height:6px;">
                    <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <small id="uploadStatus" class="text-muted mt-1"></small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function uploadAllFiles(entityId, entityType) {
    var fileInput = document.getElementById('mediaFileInput');
    var consent = document.getElementById('uploadConsent').value;
    var caption = document.getElementById('uploadCaption').value;
    var container = document.getElementById('uploadProgressContainer');
    var bar = document.getElementById('uploadProgressBar');
    var status = document.getElementById('uploadStatus');

    if (consent === 'none') {
        status.innerHTML = '<span class="text-danger">Cannot upload: No permission selected.</span>';
        return;
    }

    if (!fileInput.files.length) {
        status.innerHTML = '<span class="text-danger">Please select at least one file.</span>';
        return;
    }

    var files = Array.from(fileInput.files);
    var total = files.length;
    var completed = 0;
    var failed = [];

    container.style.display = 'block';

    function uploadNext(index) {
        if (index >= total) {
            var msg = 'Uploaded ' + completed + ' of ' + total + ' files.';
            if (failed.length) msg += ' ' + failed.length + ' failed.';
            status.innerHTML = '<span class="text-' + (failed.length === total ? 'danger' : 'success') + '">' + msg + '</span>';
            bar.style.width = '100%';
            if (completed > 0) setTimeout(function() { location.reload(); }, 1500);
            return;
        }

        var file = files[index];
        var formData = new FormData();
        formData.append('file', file);
        formData.append('consent', consent);
        if (caption) formData.append('caption', caption);

        if (entityType === 'interview') formData.append('interview_id', entityId);
        else if (entityType === 'observation') formData.append('observation_id', entityId);

        status.innerHTML = 'Uploading ' + (index + 1) + ' of ' + total + ': ' + file.name;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo BASE_URL; ?>/api/upload-handler.php', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var overall = (index / total) * 100 + (e.loaded / e.total) * (100 / total);
                bar.style.width = Math.round(overall) + '%';
            }
        };

        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) completed++;
                else failed.push(file.name + ': ' + (data.error || 'unknown'));
            } catch(e) {
                failed.push(file.name + ': parse error');
            }
            bar.style.width = Math.round(((index + 1) / total) * 100) + '%';
            uploadNext(index + 1);
        };

        xhr.onerror = function() {
            failed.push(file.name + ': connection error');
            bar.style.width = Math.round(((index + 1) / total) * 100) + '%';
            uploadNext(index + 1);
        };

        xhr.send(formData);
    }

    uploadNext(0);
}
</script>

<!-- ═══ Print Styles ═══ -->
<style media="print">
    body { font-size: 11pt; color: #000; }
    .navbar, .bottom-nav, .btn, form, .step-nav, .step-indicator,
    .card-header .btn, .d-flex.gap-2 > .btn,
    #mediaFileInput, #mediaType, #mediaConsent, #mediaCaption, #mediaUploadStatus,
    #uploadConsent, #uploadCaption, #uploadProgressContainer,
    .media-thumb-link, button[onclick^="deleteMediaItem"], button[onclick^="uploadAllFiles"],
    .card-header .btn {
        display: none !important;
    }
    .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
    .card-header { background: #f5f5f5 !important; color: #000 !important; }
    .badge { border: 1px solid #999 !important; color: #000 !important; background: #eee !important; }
    .table { font-size: 10pt; }
    .collapse.show, .collapse { display: block !important; height: auto !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    a { color: #000 !important; text-decoration: underline !important; }
    .text-muted { color: #555 !important; }
    @page { margin: 1.5cm; }
    img.img-thumbnail { max-width: 120px !important; max-height: 120px !important; }
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


