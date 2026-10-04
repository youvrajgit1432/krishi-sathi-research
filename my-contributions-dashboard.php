<?php
/**
 * Krishi Sathi Research System - My Contributions Dashboard
 *
 * ROLE-ACCESS-01.1 Package H: Contributor dashboard with stats, charts,
 * and approval status breakdowns — no confidential interview content.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

$pdo = getDB();
$uid = currentUserId();

// ─── Fetch all contribution counts ──────────────────────────────

// My Farmers — contributors see all non-approved farmers
$farmerWhere = activeWhere();
if (isContributor()) {
    $farmerWhere .= " AND (approval_status IS NULL OR approval_status != 'approved')";
}
$farmersStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(COALESCE(approval_status, 'draft') = 'approved') AS approved,
           SUM(COALESCE(approval_status, 'draft') = 'rejected') AS rejected,
           SUM(COALESCE(approval_status, 'draft') NOT IN ('approved','rejected')) AS pending
    FROM research_farmers
    WHERE {$farmerWhere}
");
$farmersStmt->execute();
$myFarmers = $farmersStmt->fetch();

// My Stakeholders — contributors see all non-approved stakeholders
$stakeholderWhere = activeWhere();
if (isContributor()) {
    $stakeholderWhere .= " AND (approval_status IS NULL OR approval_status != 'approved')";
}
$stakeholdersStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(COALESCE(approval_status, 'draft') = 'approved') AS approved,
           SUM(COALESCE(approval_status, 'draft') = 'rejected') AS rejected,
           SUM(COALESCE(approval_status, 'draft') NOT IN ('approved','rejected')) AS pending
    FROM research_participants
    WHERE {$stakeholderWhere}
");
$stakeholdersStmt->execute();
$myStakeholders = $stakeholdersStmt->fetch();

// My Farmer Interviews — contributors see all non-approved interviews
$ivWhere = activeWhere();
if (isContributor()) {
    $ivWhere .= " AND interview_status IN ('draft', 'submitted', 'rejected')";
}
$interviewsRawStmt = $pdo->prepare("
    SELECT interview_status, COUNT(*) AS cnt
    FROM research_interviews
    WHERE {$ivWhere}
    GROUP BY interview_status
");
$interviewsRawStmt->execute();
$interviewRows = $interviewsRawStmt->fetchAll();

$myInterviews = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'drafted' => 0, 'submitted' => 0];
foreach ($interviewRows as $r) {
    $st = normalizeInterviewStatus($r['interview_status']);
    $cnt = (int) $r['cnt'];
    $myInterviews['total'] += $cnt;
    if (isset($myInterviews[$st])) {
        $myInterviews[$st] += $cnt;
    }
}

// My Stakeholder Interviews — contributors see all non-approved interviews
$stkIvWhere = activeWhere();
if (isContributor()) {
    $stkIvWhere .= " AND interview_status IN ('draft', 'submitted', 'rejected')";
}
$stkInterviewsRawStmt = $pdo->prepare("
    SELECT interview_status, COUNT(*) AS cnt
    FROM research_participant_interviews
    WHERE {$stkIvWhere}
    GROUP BY interview_status
");
$stkInterviewsRawStmt->execute();
$stkInterviewRows = $stkInterviewsRawStmt->fetchAll();

$myStkInterviews = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'drafted' => 0, 'submitted' => 0];
foreach ($stkInterviewRows as $r) {
    $st = normalizeInterviewStatus($r['interview_status']);
    $cnt = (int) $r['cnt'];
    $myStkInterviews['total'] += $cnt;
    if (isset($myStkInterviews[$st])) {
        $myStkInterviews[$st] += $cnt;
    }
}

// Combined interview stats
$combinedInterviews = [
    'total' => $myInterviews['total'] + $myStkInterviews['total'],
    'approved' => $myInterviews['approved'] + $myStkInterviews['approved'],
    'rejected' => $myInterviews['rejected'] + $myStkInterviews['rejected'],
    'drafted' => $myInterviews['drafted'] + $myStkInterviews['drafted'],
    'submitted' => $myInterviews['submitted'] + $myStkInterviews['submitted'],
];

// My Observations — contributors see all observations not linked to approved interviews
$obsWhere = activeWhere('o');
if (isContributor()) {
    $obsWhere .= " AND (o.interview_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM research_interviews ri2
        WHERE ri2.id = o.interview_id
        AND ri2.interview_status = 'approved'
    ))";
}
$obsStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM research_observations o
    WHERE {$obsWhere}
");
$obsStmt->execute();
$myObservationsTotal = (int) $obsStmt->fetchColumn();

// My Media Uploads (by type)
$mediaStmt = $pdo->prepare("
    SELECT media_type, COUNT(*) AS cnt
    FROM research_media
    WHERE uploaded_by = ? AND is_deleted = 0
    GROUP BY media_type
");
$mediaStmt->execute([$uid]);
$mediaRows = $mediaStmt->fetchAll();

$myMedia = ['total' => 0, 'image' => 0, 'audio' => 0, 'video' => 0];
foreach ($mediaRows as $m) {
    $type = $m['media_type'] ?? 'file';
    $cnt = (int) $m['cnt'];
    $myMedia['total'] += $cnt;
    if (isset($myMedia[$type])) {
        $myMedia[$type] += $cnt;
    }
}

// My Consent Records
$consentStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(consent_status = 'granted') AS granted,
           SUM(consent_status = 'withdrawn') AS withdrawn
    FROM research_consent_records
    WHERE researcher_id = ?
");
$consentStmt->execute([$uid]);
$myConsent = $consentStmt->fetch();

// ─── Monthly contribution trends (interviews) ─────────────────
$trendWhere = activeWhere();
if (isContributor()) {
    $trendWhere .= " AND interview_status IN ('draft', 'submitted', 'rejected')";
}
$myTrendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM research_interviews
    WHERE {$trendWhere}
    GROUP BY month ORDER BY month ASC LIMIT 12
");
$myTrendStmt->execute();
$myTrends = $myTrendStmt->fetchAll();

$myTrendLabels = [];
$myTrendData = [];
foreach ($myTrends as $t) {
    $ts = strtotime($t['month'] . '-01');
    $myTrendLabels[] = $ts ? date('M Y', $ts) : $t['month'];
    $myTrendData[] = (int) $t['cnt'];
}

// ─── Chart data ──────────────────────────────────────────────────
$statusLabels = ['Approved', 'Submitted', 'Draft', 'Rejected'];
$statusData = [
    $combinedInterviews['approved'],
    $combinedInterviews['submitted'],
    $combinedInterviews['drafted'],
    $combinedInterviews['rejected'],
];
$statusColors = ['#198754', '#0d6efd', '#6c757d', '#ffc107'];

$totalContributions = (int) $myFarmers['total'] + (int) $myStakeholders['total']
    + $combinedInterviews['total'] + $myObservationsTotal
    + $myMedia['total'] + (int) $myConsent['total'];

$pageTitle = 'Contribution Dashboard - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-graph-up"></i> My Contribution Dashboard</h4>
    <a href="<?php echo BASE_URL; ?>/my-contributions.php" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-list-ul"></i> All Contributions
    </a>
</div>

<p class="text-muted small mb-3">
    Summary of your contributions to the research project.
    <strong><?php echo $totalContributions; ?></strong> total contributions across all categories.
    No confidential research data is displayed.
</p>

<?php if ($totalContributions === 0): ?>
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-graph-up" style="font-size: 3rem; color: #ccc;"></i>
        <h5 class="mt-3">No Contributions Yet</h5>
        <p class="text-muted">Start by registering a farmer or conducting an interview to see your stats here.</p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="btn btn-success"><i class="bi bi-person-plus"></i> Add Farmer</a>
            <a href="<?php echo BASE_URL; ?>/interview-add.php" class="btn btn-primary"><i class="bi bi-chat-dots"></i> New Interview</a>
        </div>
    </div>
</div>
<?php else: ?>

<!-- ═══ Stats Cards Row ═══ -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-4 col-lg">
        <div class="card border-0 shadow-sm bg-success text-white text-center py-2 stat-card-hover" onclick="location.href='<?php echo BASE_URL; ?>/my-contributions.php'" style="cursor:pointer;">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold"><?php echo (int) $myFarmers['total']; ?></div>
                <div class="small opacity-75">My Farmers</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
        <div class="card border-0 shadow-sm bg-secondary text-white text-center py-2 stat-card-hover" onclick="location.href='<?php echo BASE_URL; ?>/my-contributions.php'" style="cursor:pointer;">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold"><?php echo (int) $myStakeholders['total']; ?></div>
                <div class="small opacity-75">Stakeholders</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
        <div class="card border-0 shadow-sm bg-primary text-white text-center py-2 stat-card-hover" onclick="location.href='<?php echo BASE_URL; ?>/interviews.php'" style="cursor:pointer;">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold"><?php echo $combinedInterviews['total']; ?></div>
                <div class="small opacity-75">My Interviews</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
        <div class="card border-0 shadow-sm bg-dark text-white text-center py-2 stat-card-hover" onclick="location.href='<?php echo BASE_URL; ?>/observations.php'" style="cursor:pointer;">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold"><?php echo $myObservationsTotal; ?></div>
                <div class="small opacity-75">Observations</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
        <div class="card border-0 shadow-sm bg-info text-white text-center py-2 stat-card-hover" onclick="location.href='<?php echo BASE_URL; ?>/my-contributions.php'" style="cursor:pointer;">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold"><?php echo $myMedia['total']; ?></div>
                <div class="small opacity-75">Media Files</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Second Stats Row (Media breakdown + consent) — hidden for contributors ═══ -->
<?php if (!isContributor()): ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-1 h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-center gap-3 small">
                    <span><i class="bi bi-file-image text-success"></i> <strong><?php echo $myMedia['image']; ?></strong></span>
                    <span><i class="bi bi-file-music text-warning"></i> <strong><?php echo $myMedia['audio']; ?></strong></span>
                    <span><i class="bi bi-file-play text-danger"></i> <strong><?php echo $myMedia['video']; ?></strong></span>
                </div>
                <div class="text-muted mt-1" style="font-size:0.7rem;">Media Uploads by Type</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-1 h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-center gap-3 small">
                    <span><i class="bi bi-shield-check text-success"></i> <strong><?php echo (int) ($myConsent['granted'] ?? 0); ?></strong></span>
                    <span><i class="bi bi-x-circle text-warning"></i> <strong><?php echo (int) ($myConsent['withdrawn'] ?? 0); ?></strong></span>
                </div>
                <div class="text-muted mt-1" style="font-size:0.7rem;">Consent Records</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-1 h-100">
            <div class="card-body py-2">
                <div class="d-flex justify-content-center gap-3 small">
                    <span><i class="bi bi-person text-success"></i> <strong><?php echo (int) $myFarmers['approved']; ?></strong></span>
                    <span><i class="bi bi-person text-secondary"></i> <strong><?php echo (int) $myFarmers['pending']; ?></strong></span>
                </div>
                <div class="text-muted mt-1" style="font-size:0.7rem;">Farmers: Approved / Pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-1 h-100">
            <div class="card-body py-2">
                <div class="text-center small">
                    <i class="bi bi-check-circle text-success"></i>
                    <strong><?php echo $combinedInterviews['approved']; ?></strong>
                    /
                    <strong><?php echo $combinedInterviews['total']; ?></strong>
                    Approved
                </div>
                <div class="text-muted mt-1" style="font-size:0.7rem;">Interview Approval Rate</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Charts Row ═══ -->
<div class="row g-3 mb-3">
    <!-- Approval Status Doughnut Chart -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 chart-container" style="min-height: 300px;">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-pie-chart text-primary"></i> My Interview Status
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if ($combinedInterviews['total'] > 0): ?>
                    <canvas id="statusChart" role="img" aria-label="Interview status distribution"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">No interviews yet.<br><a href="<?php echo BASE_URL; ?>/interview-add.php" class="small">Start an interview</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Monthly Contribution Trends Line Chart -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 chart-container" style="min-height: 300px;">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-graph-up-arrow text-success"></i> My Monthly Activity
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (count($myTrendData) > 0): ?>
                    <canvas id="trendChart" role="img" aria-label="Monthly contribution trends"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">No activity yet.<br><a href="<?php echo BASE_URL; ?>/interview-add.php" class="small">Conduct an interview</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Approval Summary Cards Row ═══ -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between">
                <span><i class="bi bi-person text-success"></i> My Farmers</span>
                <span class="badge bg-success"><?php echo (int) $myFarmers['total']; ?></span>
            </div>
            <div class="card-body p-3">
                <div class="d-flex justify-content-around text-center">
                    <div>
                        <div class="fw-bold text-success fs-5"><?php echo (int) $myFarmers['approved']; ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                    <div>
                        <div class="fw-bold text-primary fs-5"><?php echo (int) $myFarmers['pending']; ?></div>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div>
                        <div class="fw-bold text-warning fs-5"><?php echo (int) $myFarmers['rejected']; ?></div>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
                <?php
                $fTotal = max(1, (int) $myFarmers['total']);
                $fApprovedPct = round(((int) $myFarmers['approved'] / $fTotal) * 100);
                ?>
                <div class="progress mt-2" style="height:6px;">
                    <div class="progress-bar bg-success" style="width:<?php echo $fApprovedPct; ?>%"
                         title="<?php echo $fApprovedPct; ?>% approved"></div>
                </div>
                <small class="text-muted d-block text-center mt-1"><?php echo $fApprovedPct; ?>% approval rate</small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between">
                <span><i class="bi bi-chat-dots text-primary"></i> My Interviews</span>
                <span class="badge bg-primary"><?php echo $combinedInterviews['total']; ?></span>
            </div>
            <div class="card-body p-3">
                <div class="d-flex justify-content-around text-center">
                    <div>
                        <div class="fw-bold text-success fs-5"><?php echo $combinedInterviews['approved']; ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                    <div>
                        <div class="fw-bold text-primary fs-5"><?php echo $combinedInterviews['submitted']; ?></div>
                        <small class="text-muted">Submitted</small>
                    </div>
                    <div>
                        <div class="fw-bold text-secondary fs-5"><?php echo $combinedInterviews['drafted']; ?></div>
                        <small class="text-muted">Draft</small>
                    </div>
                    <div>
                        <div class="fw-bold text-warning fs-5"><?php echo $combinedInterviews['rejected']; ?></div>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
                <?php
                $iTotal = max(1, $combinedInterviews['total']);
                $iApprovedPct = round(($combinedInterviews['approved'] / $iTotal) * 100);
                ?>
                <div class="progress mt-2" style="height:6px;">
                    <div class="progress-bar bg-success" style="width:<?php echo $iApprovedPct; ?>%"
                         title="<?php echo $iApprovedPct; ?>% approved"></div>
                </div>
                <small class="text-muted d-block text-center mt-1"><?php echo $iApprovedPct; ?>% approval rate</small>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Quick Stats Row ═══ -->
<div class="row g-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-person-badge text-secondary"></i> Stakeholders
            </div>
            <div class="card-body p-3 text-center">
                <div class="d-flex justify-content-around">
                    <div>
                        <div class="fw-bold text-success"><?php echo (int) $myStakeholders['approved']; ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                    <div>
                        <div class="fw-bold text-primary"><?php echo (int) $myStakeholders['pending']; ?></div>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div>
                        <div class="fw-bold text-warning"><?php echo (int) $myStakeholders['rejected']; ?></div>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-binoculars text-dark"></i> Observations
            </div>
            <div class="card-body p-3 text-center d-flex align-items-center justify-content-center">
                <div>
                    <div class="fs-1 fw-bold"><?php echo $myObservationsTotal; ?></div>
                    <small class="text-muted">Total Recorded</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-shield-check text-success"></i> Consent
            </div>
            <div class="card-body p-3 text-center">
                <div class="d-flex justify-content-around">
                    <div>
                        <div class="fw-bold text-success fs-5"><?php echo (int) ($myConsent['granted'] ?? 0); ?></div>
                        <small class="text-muted">Granted</small>
                    </div>
                    <div>
                        <div class="fw-bold text-warning fs-5"><?php echo (int) ($myConsent['withdrawn'] ?? 0); ?></div>
                        <small class="text-muted">Withdrawn</small>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?php echo (int) ($myConsent['total'] ?? 0); ?></div>
                        <small class="text-muted">Total</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ═══ Chart.js Initialization ═══ -->
<script>
(function() {
    'use strict';
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--bs-body-color') || '#6c757d';
    Chart.defaults.borderColor = 'rgba(0,0,0,0.06)';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.legend.labels.padding = 12;

    function isDark() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function getPluginOpts() {
        var dark = isDark();
        return {
            legend: {
                labels: { color: dark ? '#94a3b8' : '#6c757d' }
            }
        };
    }

    // 1. Interview Status Doughnut Chart
    var statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusData); ?>,
                    backgroundColor: <?php echo json_encode($statusColors); ?>,
                    borderWidth: 2,
                    borderColor: isDark() ? '#1e293b' : '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: getPluginOpts(),
                cutout: '60%',
            }
        });
    }

    // 2. Monthly Contribution Trends Line Chart
    var trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($myTrendLabels); ?>,
                datasets: [{
                    label: 'Interviews',
                    data: <?php echo json_encode($myTrendData); ?>,
                    fill: true,
                    backgroundColor: 'rgba(25, 135, 84, 0.12)',
                    borderColor: '#198754',
                    borderWidth: 2,
                    pointBackgroundColor: '#198754',
                    pointBorderColor: isDark() ? '#1e293b' : '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: getPluginOpts(),
                scales: {
                    x: {
                        ticks: {
                            color: isDark() ? '#94a3b8' : '#6c757d',
                            font: { size: 10 },
                            maxRotation: 45,
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: isDark() ? '#94a3b8' : '#6c757d',
                        },
                        grid: {
                            color: isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                }
            }
        });
    }

    // Redraw charts on dark mode toggle
    var htmlEl = document.documentElement;
    var observer = new MutationObserver(function() {
        location.reload();
    });
    observer.observe(htmlEl, { attributes: true, attributeFilter: ['data-bs-theme'] });

})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
