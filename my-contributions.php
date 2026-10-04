<?php
/**
 * Krishi Sathi Research System - My Contributions
 *
 * ROLE-ACCESS-01.1 Package A: Unified view of all user contributions.
 * Shows metadata-only summaries for all records the current user
 * has contributed to — without exposing confidential content.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

$pdo = getDB();
$uid = currentUserId();

// ─── Helper: render a contribution card ─────────────────────────
function renderContributionCard(string $icon, string $title, string $subtitle, string $link, string $status, string $date): string {
    $statusLabels = [
        'draft' => ['secondary', 'Draft'],
        'submitted' => ['primary', 'Submitted'],
        'approved' => ['success', 'Approved'],
        'rejected' => ['warning text-dark', 'Rejected'],
        'granted' => ['success', 'Granted'],
        'withdrawn' => ['warning text-dark', 'Withdrawn'],
    ];
    $statusKey = strtolower($status);
    $bg = $statusLabels[$statusKey][0] ?? 'secondary';
    $label = $statusLabels[$statusKey][1] ?? ucfirst($status);
    $bgClass = 'bg-' . explode(' ', $bg)[0];
    $textClass = strpos($bg, 'text-dark') !== false ? ' text-dark' : '';
    return '<a href="' . htmlspecialchars($link) . '" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3">
        <span class="fs-5 text-muted">' . $icon . '</span>
        <div class="flex-grow-1 min-width-0">
            <div class="fw-medium text-truncate">' . htmlspecialchars($title) . '</div>
            <small class="text-muted text-truncate d-block">' . htmlspecialchars($subtitle) . '</small>
        </div>
        <div class="text-end flex-shrink-0">
            <span class="badge ' . $bgClass . $textClass . '">' . htmlspecialchars($label) . '</span>
            <br><small class="text-muted">' . htmlspecialchars($date) . '</small>
        </div>
    </a>';
}

$pageTitle = 'My Contributions - Krishi Sathi Research';
include __DIR__ . '/header.php';

// ─── Fetch all contributions for the current user ──────────────

// Farmers I created + non-approved farmers
$farmersStmt = $pdo->prepare("
    SELECT id, name, farmer_id, created_at, approval_status,
           (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = research_farmers.id) AS interview_count
    FROM research_farmers
    WHERE (created_by = ? OR approval_status IS NULL OR approval_status != 'approved') AND " . activeWhere() . "
    ORDER BY created_at DESC
");
$farmersStmt->execute([$uid]);
$myFarmers = $farmersStmt->fetchAll();

// Stakeholders I created + non-approved stakeholders
$stakeholdersStmt = $pdo->prepare("
    SELECT id, name, participant_id, created_at, approval_status,
           (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = research_participants.id) AS interview_count
    FROM research_participants
    WHERE (created_by = ? OR approval_status IS NULL OR approval_status != 'approved') AND " . activeWhere() . "
    ORDER BY created_at DESC
");
$stakeholdersStmt->execute([$uid]);
$myStakeholders = $stakeholdersStmt->fetchAll();

// Farmer interviews I conducted
$interviewsStmt = $pdo->prepare("
    SELECT ri.id, rf.name AS farmer_name, rf.farmer_id AS fid,
           ri.interview_date, ri.interview_status, ri.created_at
    FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    WHERE ri.interviewer_id = ? AND " . activeWhere('ri') . "
    ORDER BY ri.created_at DESC
");
$interviewsStmt->execute([$uid]);
$myInterviews = $interviewsStmt->fetchAll();

// Stakeholder interviews I conducted
$stkInterviewsStmt = $pdo->prepare("
    SELECT pi.id, p.name AS participant_name, p.participant_id AS pid,
           pi.interview_date, pi.interview_status, pi.created_at
    FROM research_participant_interviews pi
    JOIN research_participants p ON pi.participant_id = p.id
    WHERE pi.interviewer_id = ? AND " . activeWhere('pi') . "
    ORDER BY pi.created_at DESC
");
$stkInterviewsStmt->execute([$uid]);
$myStkInterviews = $stkInterviewsStmt->fetchAll();

// Observations I recorded
$observationsStmt = $pdo->prepare("
    SELECT o.id, COALESCE(rf.name, rp.name) AS entity_name,
           o.farmer_id, o.participant_id,
           o.observation_date, o.created_at
    FROM research_observations o
    LEFT JOIN research_farmers rf ON o.farmer_id = rf.id
    LEFT JOIN research_participants rp ON o.participant_id = rp.id
    WHERE o.observer_id = ? AND " . activeWhere('o') . "
    ORDER BY o.created_at DESC
");
$observationsStmt->execute([$uid]);
$myObservations = $observationsStmt->fetchAll();

// Media I uploaded
$myMediaStmt = $pdo->prepare("
    SELECT m.id, m.original_name, m.media_type, m.consent, m.created_at,
           COALESCE(ri.farmer_id, ro.farmer_id) AS farmer_id,
           COALESCE(rf.name, rff.name) AS farmer_name,
           m.interview_id, m.observation_id
    FROM research_media m
    LEFT JOIN research_interviews ri ON m.interview_id = ri.id
    LEFT JOIN research_farmers rf ON ri.farmer_id = rf.id
    LEFT JOIN research_observations ro ON m.observation_id = ro.id
    LEFT JOIN research_farmers rff ON ro.farmer_id = rff.id
    WHERE m.uploaded_by = ? AND m.is_deleted = 0
    ORDER BY m.created_at DESC
    LIMIT 50
");
$myMediaStmt->execute([$uid]);
$myMedia = $myMediaStmt->fetchAll();

// Media types for icons
function mediaIcon(string $type): string {
    $icons = ['image' => 'bi-file-image', 'audio' => 'bi-file-music', 'video' => 'bi-file-play'];
    return '<i class="bi ' . ($icons[$type] ?? 'bi-file') . '"></i>';
}

// Consent records I created
$consentStmt = $pdo->prepare("
    SELECT cr.*,
           COALESCE(rf.name, rp.name) AS entity_name,
           cr.farmer_id, cr.participant_id
    FROM research_consent_records cr
    LEFT JOIN research_farmers rf ON cr.farmer_id = rf.id
    LEFT JOIN research_participants rp ON cr.participant_id = rp.id
    WHERE cr.researcher_id = ?
    ORDER BY cr.created_at DESC
    LIMIT 50
");
$consentStmt->execute([$uid]);
$myConsent = $consentStmt->fetchAll();

$totalContributions = count($myFarmers) + count($myStakeholders) + count($myInterviews)
    + count($myStkInterviews) + count($myObservations) + count($myMedia) + count($myConsent);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-person-check"></i> My Contributions</h4>
    <a href="<?php echo BASE_URL; ?>/my-contributions-dashboard.php" class="btn btn-sm btn-outline-success">
        <i class="bi bi-graph-up"></i> Dashboard
    </a>
</div>

<p class="text-muted small mb-3">
    Showing metadata-only summaries of your contributions to the research project.
    No confidential interview content, observations, or research data is displayed.
    <strong><?php echo $totalContributions; ?></strong> total contributions.
</p>

<?php if ($totalContributions === 0): ?>
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-person-check" style="font-size: 3rem; color: #ccc;"></i>
        <h5 class="mt-3">No Contributions Yet</h5>
        <p class="text-muted">Start by registering a farmer or conducting an interview.</p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="btn btn-success"><i class="bi bi-person-plus"></i> Add Farmer</a>
            <a href="<?php echo BASE_URL; ?>/interview-add.php" class="btn btn-primary"><i class="bi bi-chat-dots"></i> New Interview</a>
        </div>
    </div>
</div>
<?php else: ?>

<div class="row g-3">
    <!-- Farmers -->
    <?php if (count($myFarmers) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-person text-success"></i> Farmers</span>
                <span class="badge bg-success"><?php echo count($myFarmers); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myFarmers as $f):
                    $hasInterview = (int) ($f['interview_count'] ?? 0) > 0; ?>
                    <div class="list-group-item d-flex align-items-center gap-2 py-2 px-3">
                        <span class="fs-5 text-muted flex-shrink-0"><i class="bi bi-person"></i></span>
                        <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $f['id']; ?>" class="flex-grow-1 min-width-0 text-decoration-none">
                            <div class="fw-medium text-truncate"><?php echo htmlspecialchars($f['name']); ?></div>
                            <small class="text-muted text-truncate d-block"><?php echo htmlspecialchars($f['farmer_id']) . ' — ' . date('M j, Y', strtotime($f['created_at'])); ?></small>
                        </a>
                        <span class="badge bg-<?php echo empty($f['approval_status']) || $f['approval_status'] === 'draft' ? 'secondary' : ($f['approval_status'] === 'approved' ? 'success' : 'warning text-dark'); ?> flex-shrink-0">
                            <?php echo ucfirst($f['approval_status'] ?? 'draft'); ?>
                        </span>
                        <?php if (!isViewer()): ?>
                            <?php if (!$hasInterview): ?>
                                <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline-success flex-shrink-0" title="Add Interview"><i class="bi bi-plus-lg"></i></a>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/observation-add.php?farmer_id=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline-info flex-shrink-0" title="Add Observation"><i class="bi bi-binoculars"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stakeholders -->
    <?php if (count($myStakeholders) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-person-badge text-secondary"></i> Stakeholders</span>
                <span class="badge bg-secondary"><?php echo count($myStakeholders); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myStakeholders as $s):
                    $hasInterview = (int) ($s['interview_count'] ?? 0) > 0; ?>
                    <div class="list-group-item d-flex align-items-center gap-2 py-2 px-3">
                        <span class="fs-5 text-muted flex-shrink-0"><i class="bi bi-person-badge"></i></span>
                        <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $s['id']; ?>" class="flex-grow-1 min-width-0 text-decoration-none">
                            <div class="fw-medium text-truncate"><?php echo htmlspecialchars($s['name']); ?></div>
                            <small class="text-muted text-truncate d-block"><?php echo htmlspecialchars($s['participant_id']) . ' — ' . date('M j, Y', strtotime($s['created_at'])); ?></small>
                        </a>
                        <span class="badge bg-<?php echo empty($s['approval_status']) || $s['approval_status'] === 'draft' ? 'secondary' : ($s['approval_status'] === 'approved' ? 'success' : 'warning text-dark'); ?> flex-shrink-0">
                            <?php echo ucfirst($s['approval_status'] ?? 'draft'); ?>
                        </span>
                        <?php if (!isViewer()): ?>
                            <?php if (!$hasInterview): ?>
                                <a href="<?php echo BASE_URL; ?>/participant-interview-add.php?participant_id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-success flex-shrink-0" title="Add Interview"><i class="bi bi-plus-lg"></i></a>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/observation-add.php?participant_id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-info flex-shrink-0" title="Add Observation"><i class="bi bi-binoculars"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Farmer Interviews -->
    <?php if (count($myInterviews) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-chat-dots text-primary"></i> Farmer Interviews</span>
                <span class="badge bg-primary"><?php echo count($myInterviews); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myInterviews as $i):
                    echo renderContributionCard(
                        '<i class="bi bi-chat-dots"></i>',
                        $i['farmer_name'] . ' (#' . $i['id'] . ')',
                        $i['fid'] . ' — ' . $i['interview_date'],
                        BASE_URL . '/interview-view.php?id=' . $i['id'],
                        $i['interview_status'] ?? 'draft',
                        $i['interview_date']
                    );
                endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stakeholder Interviews -->
    <?php if (count($myStkInterviews) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-chat-quote text-info"></i> Stakeholder Interviews</span>
                <span class="badge bg-info"><?php echo count($myStkInterviews); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myStkInterviews as $i):
                    echo renderContributionCard(
                        '<i class="bi bi-chat-quote"></i>',
                        $i['participant_name'] . ' (#' . $i['id'] . ')',
                        $i['pid'] . ' — ' . $i['interview_date'],
                        BASE_URL . '/participant-interview-view.php?id=' . $i['id'],
                        $i['interview_status'] ?? 'draft',
                        $i['interview_date']
                    );
                endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Observations -->
    <?php if (count($myObservations) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-binoculars text-dark"></i> Observations</span>
                <span class="badge bg-dark"><?php echo count($myObservations); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myObservations as $o):
                    echo renderContributionCard(
                        '<i class="bi bi-binoculars"></i>',
                        'Observation #' . $o['id'] . ' — ' . $o['entity_name'],
                        $o['observation_date'],
                        BASE_URL . '/observation-view.php?id=' . $o['id'],
                        'submitted',
                        $o['observation_date']
                    );
                endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Media Uploads -->
    <?php if (count($myMedia) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-images text-purple"></i> Media Uploads</span>
                <span class="badge bg-secondary"><?php echo count($myMedia); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myMedia as $m):
                    $link = $m['interview_id']
                        ? BASE_URL . '/interview-view.php?id=' . $m['interview_id']
                        : ($m['observation_id']
                            ? BASE_URL . '/observation-view.php?id=' . $m['observation_id']
                            : '#');
                    $farmerLabel = $m['farmer_name'] ?: 'Unknown';
                    echo renderContributionCard(
                        mediaIcon($m['media_type']),
                        $m['original_name'],
                        $m['media_type'] . ' — ' . $farmerLabel . ' — ' . ($m['consent'] ?: 'no consent'),
                        $link,
                        'submitted',
                        date('M j, Y', strtotime($m['created_at']))
                    );
                endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Consent Records -->
    <?php if (count($myConsent) > 0): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-2 fw-semibold small d-flex justify-content-between">
                <span><i class="bi bi-shield-check text-success"></i> Consent Records</span>
                <span class="badge bg-success"><?php echo count($myConsent); ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
                <?php foreach ($myConsent as $c):
                    $entityName = $c['entity_name'] ?? 'Unknown';
                    $link = $c['farmer_id']
                        ? BASE_URL . '/farmer-view.php?id=' . $c['farmer_id']
                        : ($c['participant_id']
                            ? BASE_URL . '/participant-view.php?id=' . $c['participant_id']
                            : '#');
                    echo renderContributionCard(
                        '<i class="bi bi-shield-check"></i>',
                        $entityName,
                        $c['consent_method'] ?? 'Unknown method' . ' — ' . $c['consent_date'],
                        $link,
                        $c['consent_status'] ?? 'granted',
                        date('M j, Y', strtotime($c['created_at']))
                    );
                endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
