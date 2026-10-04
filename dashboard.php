<?php
/**
 * Krishi Sathi Research System - Dashboard
 * Phase 1.5: Research progress card + Validation tracker.
 * Phase 6: Date range filter + Observations stats.
 */
require_once __DIR__ . '/config.php';
requireLogin();

// ROLE-ACCESS-01.3 Package C: Research Contributors see their own dashboard
if (isContributor()) {
    header('Location: ' . BASE_URL . '/my-contributions-dashboard.php');
    exit;
}

$pdo = getDB();

// ─── Date Range Filter ──────────────────────────────────────────
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();

// Build date filter closure — returns [sql_fragment, params_array]
$df = function(string $alias, string $dateFrom, string $dateTo): array {
    $conditions = [];
    $params = [];
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($dateFrom !== '') {
        $conditions[] = "{$prefix}created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $conditions[] = "{$prefix}created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    return [implode(' AND ', $conditions), $params];
};

[$dateSql, $dateP] = $df('', $dateFrom, $dateTo);
[$dateSqlRi, $datePRi] = $df('ri', $dateFrom, $dateTo);
[$dateSqlPi, $datePPi] = $df('pi', $dateFrom, $dateTo);
[$dateSqlRf, $datePRf] = $df('rf', $dateFrom, $dateTo);
[$dateSqlP, $datePP]   = $df('p', $dateFrom, $dateTo);

// Helper to count with date filter
function countWithDate(PDO $pdo, string $baseSql, array $extraParams, string $dateSql, array $dateP = []): int {
    $sql = $baseSql;
    $params = $extraParams;
    if ($dateSql !== '') {
        $sql .= ' AND ' . $dateSql;
        $params = array_merge($params, $dateP);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// ─── Total Counts ───────────────────────────────────────────────
$totalFarmers    = countWithDate($pdo, "SELECT COUNT(*) FROM research_farmers WHERE " . activeWhere(), [], $dateSql, $dateP);
$totalStakeholders = countWithDate($pdo, "SELECT COUNT(*) FROM research_participants WHERE " . activeWhere(), [], $dateSql, $dateP);

// Helper to build UNION count queries with date filter INSIDE each SELECT
function unionCountWithDate(PDO $pdo, string $where1, string $where2, string $dateSql, array $dateP): int {
    $sql = "SELECT COUNT(*) FROM (
        SELECT id FROM research_interviews WHERE $where1";
    $params = [];
    if ($dateSql !== '') {
        $sql .= " AND $dateSql";
        $params = array_merge($params, $dateP);
    }
    $sql .= " UNION ALL SELECT id FROM research_participant_interviews WHERE $where2";
    if ($dateSql !== '') {
        $sql .= " AND $dateSql";
        $params = array_merge($params, $dateP);
    }
    $sql .= ") c";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$totalInterviews = unionCountWithDate($pdo, activeWhere(), activeWhere(), $dateSql, $dateP);

// Observations
$totalObservations = countWithDate($pdo, "SELECT COUNT(*) FROM research_observations WHERE " . activeWhere(), [], $dateSql, $dateP);

// Districts
$districtCount = countWithDate($pdo,
    "SELECT COUNT(DISTINCT district) FROM research_farmers WHERE district IS NOT NULL AND district != '' AND " . activeWhere(),
    [], $dateSql, $dateP
);

// ─── Status Breakdown ───────────────────────────────────────────
$draftCount = unionCountWithDate($pdo,
    "interview_status = 'draft' AND " . activeWhere(),
    "interview_status = 'draft' AND " . activeWhere(),
    $dateSql, $dateP
);
$submittedCount = unionCountWithDate($pdo,
    "interview_status = 'submitted' AND " . activeWhere(),
    "interview_status = 'submitted' AND " . activeWhere(),
    $dateSql, $dateP
);
$approvedCount = unionCountWithDate($pdo,
    "interview_status = 'approved' AND " . activeWhere(),
    "interview_status = 'approved' AND " . activeWhere(),
    $dateSql, $dateP
);
$rejectedCount = unionCountWithDate($pdo,
    "interview_status = 'rejected' AND " . activeWhere(),
    "interview_status = 'rejected' AND " . activeWhere(),
    $dateSql, $dateP
);
$deletedCount = unionCountWithDate($pdo,
    deletedWhere(),
    deletedWhere(),
    $dateSql, $dateP
);

// ─── Target & Progress ──────────────────────────────────────────
$targetStmt = $pdo->prepare("SELECT setting_value FROM research_settings WHERE setting_key = ?");
$targetStmt->execute(['interview_target']);
$target = (int) ($targetStmt->fetchColumn() ?: 100);
$remaining = max(0, $target - $totalInterviews);
$progressPct = $target > 0 ? min(100, round(($totalInterviews / $target) * 100)) : 0;

// ─── Average Duration ───────────────────────────────────────────
$avgDuration = countWithDate($pdo,
    "SELECT COALESCE(AVG(duration_minutes), 0) FROM research_interviews WHERE duration_minutes IS NOT NULL AND duration_minutes > 0",
    [], $dateSql, $dateP
);

// ─── Follow-ups Due ─────────────────────────────────────────────
$followUpsDue = countWithDate($pdo,
    "SELECT COUNT(*) FROM research_interviews WHERE follow_up_required = 1 AND (follow_up_date IS NULL OR follow_up_date <= CURDATE()) AND " . activeWhere(),
    [], $dateSql, $dateP
);

// ─── Voice Interest ─────────────────────────────────────────────
$voiceYes   = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE voice_interest = 'yes' AND " . activeWhere(), [], $dateSql, $dateP);
$voiceMaybe = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE voice_interest = 'maybe' AND " . activeWhere(), [], $dateSql, $dateP);
$voiceNo    = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE voice_interest = 'no' AND " . activeWhere(), [], $dateSql, $dateP);
$voiceTotal = $voiceYes + $voiceMaybe + $voiceNo;
$voicePct   = $voiceTotal > 0 ? round(($voiceYes / $voiceTotal) * 100) : 0;

// ─── Reminder Interest ──────────────────────────────────────────
$remYes   = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE assumption_reminders = 'yes'", [], $dateSql, $dateP);
$remMaybe = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE assumption_reminders = 'maybe'", [], $dateSql, $dateP);
$remNo    = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE assumption_reminders = 'no'", [], $dateSql, $dateP);
$remTotal = $remYes + $remMaybe + $remNo;

// ─── Record Keeping ─────────────────────────────────────────────
$hasRecords = countWithDate($pdo,
    "SELECT COUNT(*) FROM research_interviews WHERE record_keeping_method NOT LIKE '%No Records%' AND record_keeping_method IS NOT NULL AND record_keeping_method != ''",
    [], $dateSql, $dateP
);
$noRecords = countWithDate($pdo,
    "SELECT COUNT(*) FROM research_interviews WHERE record_keeping_method LIKE '%No Records%'",
    [], $dateSql, $dateP
);
$rkTotal = $hasRecords + $noRecords;

// ─── App Motivation ─────────────────────────────────────────────
$appMotivated = countWithDate($pdo,
    "SELECT COUNT(*) FROM research_interviews WHERE app_motivations IS NOT NULL AND app_motivations != ''",
    [], $dateSql, $dateP
);
$appTotal = countWithDate($pdo, "SELECT COUNT(*) FROM research_interviews WHERE id IS NOT NULL", [], $dateSql, $dateP);

// ─── Recommendations ────────────────────────────────────────────
$recSelected = countWithDate($pdo,
    "SELECT COUNT(*) FROM research_interviews WHERE recommendation_types IS NOT NULL AND recommendation_types != ''",
    [], $dateSql, $dateP
);
$recTotal = $appTotal;

// ─── Top Problems (#1 ranked) ───────────────────────────────────
$topProblemsSql = "SELECT problem_description, COUNT(*) as cnt
    FROM research_problem_rankings pr
    JOIN research_interviews ri ON pr.interview_id = ri.id
    WHERE pr.problem_number = 1 AND " . activeWhere('ri');
$topProblemsParams = [];
if ($dateSqlRi !== '') {
    $topProblemsSql .= ' AND ' . $dateSqlRi;
    $topProblemsParams = array_merge($topProblemsParams, $datePRi);
}
$topProblemsSql .= " GROUP BY problem_description ORDER BY cnt DESC LIMIT 5";
$topProblemsStmt = $pdo->prepare($topProblemsSql);
$topProblemsStmt->execute($topProblemsParams);
$topProblems = $topProblemsStmt->fetchAll();

// ─── Recent interviews (farmers + stakeholders) ─────────────────
$recentSql = "(SELECT
        ri.id, rf.name AS participant_name, rf.farmer_id AS participant_code,
        ru.full_name AS interviewer_name, ri.interview_date, ri.created_at,
        ri.interview_status, 'Farmer' AS source_type
    FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    JOIN research_users ru ON ri.interviewer_id = ru.id
    WHERE " . activeWhere('ri');
$recentParams = [];
if ($dateSqlRi !== '') {
    $recentSql .= ' AND ' . $dateSqlRi;
    $recentParams = array_merge($recentParams, $datePRi);
}
$recentSql .= ")
UNION ALL
(SELECT
        pi.id, p.name AS participant_name, p.participant_id AS participant_code,
        ru.full_name AS interviewer_name, pi.interview_date, pi.created_at,
        pi.interview_status, 'Stakeholder' AS source_type
    FROM research_participant_interviews pi
    JOIN research_participants p ON pi.participant_id = p.id
    JOIN research_users ru ON pi.interviewer_id = ru.id
    WHERE " . activeWhere('pi');
if ($dateSqlPi !== '') {
    $recentSql .= ' AND ' . $dateSqlPi;
    $recentParams = array_merge($recentParams, $datePPi);
}
$recentSql .= ")
ORDER BY created_at DESC
LIMIT 3";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recentInterviews = $recentStmt->fetchAll();

// ─── Upcoming Follow-Ups ────────────────────────────────────────
$upcomingSql = "SELECT ri.follow_up_date, ri.follow_up_reason, rf.name AS farmer_name, rf.id AS farmer_id
    FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    WHERE ri.follow_up_required = 1 AND ri.follow_up_date IS NOT NULL
    AND " . activeWhere('ri');
$upcomingParams = [];
if ($dateSqlRi !== '') {
    $upcomingSql .= ' AND ' . $dateSqlRi;
    $upcomingParams = array_merge($upcomingParams, $datePRi);
}
$upcomingSql .= " ORDER BY ri.follow_up_date ASC LIMIT 5";
$upcomingStmt = $pdo->prepare($upcomingSql);
$upcomingStmt->execute($upcomingParams);
$upcomingFollowUps = $upcomingStmt->fetchAll();

// ─── Monthly Interview Trends ────────────────────────────────────
// When date range is set, show months within that range; otherwise show last 12 months
$trendSql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM (SELECT created_at FROM research_interviews WHERE " . activeWhere();
$trendParams = [];
if ($dateSql !== '') {
    $trendSql .= ' AND ' . $dateSql;
    $trendParams = array_merge($trendParams, $dateP);
}
$trendSql .= " UNION ALL SELECT created_at FROM research_participant_interviews WHERE " . activeWhere();
if ($dateSql !== '') {
    $trendSql .= ' AND ' . $dateSql;
    $trendParams = array_merge($trendParams, $dateP);
}
$trendSql .= ") combined GROUP BY month ORDER BY month ASC";
if ($dateFrom === '' && $dateTo === '') {
    $trendSql .= " LIMIT 12";
}
$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($trendParams);
$monthlyTrends = $trendStmt->fetchAll();

// ─── Monthly Observation Trends ──────────────────────────────────
$obsTrendSql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM research_observations WHERE " . activeWhere();
$obsTrendParams = [];
if ($dateSql !== '') {
    $obsTrendSql .= ' AND ' . $dateSql;
    $obsTrendParams = array_merge($obsTrendParams, $dateP);
}
$obsTrendSql .= " GROUP BY month ORDER BY month ASC";
if ($dateFrom === '' && $dateTo === '') {
    $obsTrendSql .= " LIMIT 12";
}
$obsTrendStmt = $pdo->prepare($obsTrendSql);
$obsTrendStmt->execute($obsTrendParams);
$obsTrends = $obsTrendStmt->fetchAll();

// ─── Build chart data arrays ────────────────────────────────────
$trendLabels = [];
$trendData = [];
foreach ($monthlyTrends as $t) {
    $ts = strtotime($t['month'] . '-01');
    $trendLabels[] = $ts ? date('M Y', $ts) : $t['month'];
    $trendData[] = (int) $t['cnt'];
}

$obsTrendLabels = [];
$obsTrendData = [];
foreach ($obsTrends as $t) {
    $ts = strtotime($t['month'] . '-01');
    $obsTrendLabels[] = $ts ? date('M Y', $ts) : $t['month'];
    $obsTrendData[] = (int) $t['cnt'];
}

// ─── Consent Compliance Stats (Phase 2C) ───────────────────────
$consentStats = getConsentStats($pdo);

// Voice interest counts for pie chart
$voiceChartData = [$voiceYes, $voiceMaybe, $voiceNo];
$voiceChartLabels = ['Yes', 'Maybe', 'No'];
$voiceChartColors = ['#198754', '#ffc107', '#dc3545'];

// Top problems for bar chart
$problemLabels = [];
$problemCounts = [];
$problemColors = ['#dc3545', '#fd7e14', '#ffc107', '#198754', '#0d6efd'];
foreach ($topProblems as $p) {
    $label = $p['problem_description'] ?? 'Unknown';
    if (mb_strlen($label) > 20) {
        $label = mb_substr($label, 0, 17) . '...';
    }
    $problemLabels[] = $label;
    $problemCounts[] = (int) $p['cnt'];
}

// Helper for filter form: preserve query params
$qsFilter = http_build_query(array_filter(['date_preset' => $datePreset, 'date_from' => $dateFrom, 'date_to' => $dateTo]));
$hasFilter = $datePreset !== '' || $dateFrom !== '' || $dateTo !== '';

$pageTitle = 'Dashboard - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="dashboard-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="mb-0">📊 Dashboard</h4>
        <span class="greeting">Welcome, <?php echo htmlspecialchars(currentUserName()); ?></span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>/farmer-quick-add.php" class="btn btn-sm btn-outline-success">
            <i class="bi bi-lightning"></i> Quick Farmer
        </a>
        <a href="<?php echo BASE_URL; ?>/interview-add.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle"></i> New Interview
        </a>
    </div>
</div>

<!-- ═══ Date Range Filter ═══ -->
<form method="get" class="mb-3" id="dateFilterForm">
    <input type="hidden" name="date_preset" id="datePresetInput" value="<?php echo htmlspecialchars($datePreset); ?>">
    <div class="card shadow-sm border-0">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-auto"><i class="bi bi-calendar-range text-muted"></i></div>
                <div class="col-12 col-md-auto">
                    <?php echo renderDatePresets($datePreset); ?>
                </div>
            </div>
            <div class="row g-2 align-items-center mt-1" id="customDateRange" style="<?php echo $datePreset === 'custom' ? '' : 'display:none;'; ?>">
                <div class="col-auto"><i class="bi bi-calendar-range text-muted"></i></div>
                <div class="col-4 col-md-2">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>" placeholder="From">
                </div>
                <div class="col-auto text-muted small">→</div>
                <div class="col-4 col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>" placeholder="To">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- ═══ Interview Status KPI Row ═══ -->
<div class="row g-1 mb-3">
    <div class="col-3 col-md"><a href="<?php echo BASE_URL; ?>/interviews.php?status=draft" class="text-decoration-none"><div class="kpi-card card"><div class="card-body"><div class="kpi-label">Draft</div><div class="kpi-value"><?php echo $draftCount; ?></div></div></div></a></div>
    <div class="col-3 col-md"><a href="<?php echo BASE_URL; ?>/interviews.php?status=submitted" class="text-decoration-none"><div class="kpi-card card"><div class="card-body"><div class="kpi-label">Submitted</div><div class="kpi-value text-primary"><?php echo $submittedCount; ?></div></div></div></a></div>
    <div class="col-3 col-md"><a href="<?php echo BASE_URL; ?>/interviews.php?status=approved" class="text-decoration-none"><div class="kpi-card card"><div class="card-body"><div class="kpi-label">Approved</div><div class="kpi-value text-success"><?php echo $approvedCount; ?></div></div></div></a></div>
    <div class="col-3 col-md"><a href="<?php echo BASE_URL; ?>/interviews.php?status=rejected" class="text-decoration-none"><div class="kpi-card card"><div class="card-body"><div class="kpi-label">Rejected</div><div class="kpi-value text-warning"><?php echo $rejectedCount; ?></div></div></div></a></div>
</div>

<div class="progress-card card shadow-sm border-0 mb-3">
    <div class="card-header bg-white fw-semibold py-1 px-3"><i class="bi bi-bullseye"></i> Research Progress</div>
    <div class="card-body">
        <div class="row align-items-center g-2">
            <div class="col-4 col-md-2 text-center">
                <div class="fw-bold text-primary" style="font-size:1.5rem;line-height:1.2;"><?php echo $totalInterviews; ?></div>
                <small class="text-muted" style="font-size:0.65rem;">of <?php echo $target; ?> target</small>
                <?php if ($hasFilter): ?>
                    <div><span class="badge bg-info" style="font-size:0.55rem;">filtered</span></div>
                <?php endif; ?>
                <?php if (isLead()): ?>
                    <a href="<?php echo BASE_URL; ?>/settings.php" class="d-block" style="font-size:0.6rem;">Change target</a>
                <?php endif; ?>
            </div>
            <div class="col-5 col-md-6">
                <div class="progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                         role="progressbar" style="width: <?php echo $progressPct; ?>%;"
                         aria-valuenow="<?php echo $progressPct; ?>" aria-valuemin="0" aria-valuemax="100">
                        <?php echo $progressPct; ?>%
                    </div>
                </div>
                <div class="d-flex justify-content-between" style="font-size:0.6rem;color:#6c757d;margin-top:2px;">
                    <span><?php echo $totalInterviews; ?> completed</span>
                    <span><?php echo $remaining; ?> remaining</span>
                </div>
            </div>
            <div class="col-3 col-md-4">
                <div class="row g-1 text-center">
                    <div class="col-6">
                        <div class="fw-bold" style="font-size:0.85rem;"><?php echo $avgDuration ? $avgDuration . 'm' : '-'; ?></div>
                        <small style="font-size:0.6rem;color:#6c757d;">Avg Duration</small>
                    </div>
                    <div class="col-6">
                        <div class="fw-bold text-<?php echo $followUpsDue > 0 ? 'warning' : 'success'; ?>" style="font-size:0.85rem;">
                            <?php echo $followUpsDue > 0 ? $followUpsDue . '' : '0'; ?>
                        </div>
                        <small style="font-size:0.6rem;color:#6c757d;">Follow-ups</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Quick Actions ═══ -->
<div class="quick-action-grid mb-3">
    <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="qa-btn" style="border-left:3px solid #198754;">
        <i class="bi bi-person-plus text-success"></i>
        <span>Farmer</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/interview-add.php" class="qa-btn" style="border-left:3px solid #0d6efd;">
        <i class="bi bi-chat-dots text-primary"></i>
        <span>Interview</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/observation-add.php" class="qa-btn" style="border-left:3px solid #212529;">
        <i class="bi bi-binoculars text-dark"></i>
        <span>Observation</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/participant-add.php" class="qa-btn" style="border-left:3px solid #6c757d;">
        <i class="bi bi-person-badge text-secondary"></i>
        <span>Stakeholder</span>
    </a>
</div>

<!-- ═══ Stats Cards — Clickable ═══ -->
<div class="row g-1 mb-3">
    <div class="col">
        <a href="<?php echo BASE_URL; ?>/farmers.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm bg-success text-white stat-card-compact stat-card-hover">
                <div class="card-body">
                    <div class="stat-number"><?php echo $totalFarmers; ?></div>
                    <div class="stat-label">Farmers</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?php echo BASE_URL; ?>/participants.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm bg-secondary text-white stat-card-compact stat-card-hover">
                <div class="card-body">
                    <div class="stat-number"><?php echo $totalStakeholders; ?></div>
                    <div class="stat-label">Stakeholders</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?php echo BASE_URL; ?>/interviews.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm bg-primary text-white stat-card-compact stat-card-hover">
                <div class="card-body">
                    <div class="stat-number"><?php echo $totalInterviews; ?></div>
                    <div class="stat-label">Interviews</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?php echo BASE_URL; ?>/observations.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm bg-dark text-white stat-card-compact stat-card-hover">
                <div class="card-body">
                    <div class="stat-number"><?php echo $totalObservations; ?></div>
                    <div class="stat-label">Observations</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col">
        <div class="card border-0 shadow-sm bg-info text-white stat-card-compact">
            <div class="card-body">
                <div class="stat-number"><?php echo $districtCount; ?></div>
                <div class="stat-label">Districts</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card border-0 shadow-sm bg-warning text-white stat-card-compact">
            <div class="card-body">
                <div class="stat-number"><?php echo $voicePct; ?>%</div>
                <div class="stat-label">Voice Interest</div>
            </div>
        </div>
    </div>
    <div class="col">
        <a href="<?php echo BASE_URL; ?>/consent.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm bg-purple text-white stat-card-compact stat-card-hover">
                <div class="card-body">
                    <div class="stat-number"><?php echo $consentStats['percentage']; ?>%</div>
                    <div class="stat-label">Consented</div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ═══ Interactive Charts (2-column grid on desktop) ═══ -->
<div class="row g-2 mb-3">
    <!-- Voice Interest Pie Chart -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 chart-container pie">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-mic text-success"></i> Voice Interest
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if ($voiceTotal > 0): ?>
                    <canvas id="voiceChart" role="img" aria-label="Voice interest distribution pie chart"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">No voice interest data yet.<br><a href="<?php echo BASE_URL; ?>/interview-add.php" class="small">Conduct interviews</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Problems Bar Chart -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 chart-container bar">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart-fill text-danger"></i> Top Problems
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (count($topProblems) > 0): ?>
                    <canvas id="problemsChart" role="img" aria-label="Top problems bar chart"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">No problem rankings yet.<br><a href="<?php echo BASE_URL; ?>/interview-add.php" class="small">Start an interview</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Interview & Observation Trends Line Chart -->
    <div class="col-md-12">
        <div class="card shadow-sm border-0 chart-container line">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-graph-up-arrow text-primary"></i> Monthly Trends
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (count($monthlyTrends) > 0 || count($obsTrends) > 0): ?>
                    <canvas id="trendsChart" role="img" aria-label="Monthly interview trends line chart"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">No data yet.<br><a href="<?php echo BASE_URL; ?>/interview-add.php" class="small">Start an interview</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 dashboard-bottom">
    <!-- ═══ Validation Tracker ═══ -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-check2-square text-info"></i> Hypothesis Validation Tracker
            </div>
            <div class="card-body p-2">
                <table class="table table-sm table-borderless mb-0" style="font-size:0.75rem;">
                    <thead>
                        <tr>
                            <th>Hypothesis</th>
                            <th class="text-center text-success">Yes %</th>
                            <th class="text-center text-warning">Maybe %</th>
                            <th class="text-center text-danger">No %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Voice Interest</td>
                            <td class="text-center fw-bold text-success"><?php echo $voiceTotal > 0 ? round(($voiceYes / $voiceTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-warning"><?php echo $voiceTotal > 0 ? round(($voiceMaybe / $voiceTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-danger"><?php echo $voiceTotal > 0 ? round(($voiceNo / $voiceTotal) * 100) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td>Reminder Interest</td>
                            <td class="text-center fw-bold text-success"><?php echo $remTotal > 0 ? round(($remYes / $remTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-warning"><?php echo $remTotal > 0 ? round(($remMaybe / $remTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-danger"><?php echo $remTotal > 0 ? round(($remNo / $remTotal) * 100) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td>Keeps Records</td>
                            <td class="text-center fw-bold text-success"><?php echo $rkTotal > 0 ? round(($hasRecords / $rkTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-muted" colspan="2"><?php echo $noRecords; ?> use No Records</td>
                        </tr>
                        <tr>
                            <td>Would Use App Regularly</td>
                            <td class="text-center fw-bold text-success"><?php echo $appTotal > 0 ? round(($appMotivated / $appTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-muted" colspan="2"><?php echo $appTotal - $appMotivated; ?> not interested</td>
                        </tr>
                        <tr>
                            <td>Want Recommendations</td>
                            <td class="text-center fw-bold text-success"><?php echo $recTotal > 0 ? round(($recSelected / $recTotal) * 100) : 0; ?>%</td>
                            <td class="text-center text-muted" colspan="2"><?php echo $recTotal - $recSelected; ?> not interested</td>
                        </tr>
                    </tbody>
                </table>
                <div style="font-size:0.6rem;color:#6c757d;" class="mt-1">Based on <?php echo $voiceTotal; ?> interview(s) with responses</div>
            </div>
        </div>
    </div>

    <!-- Top Problems -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-exclamation-triangle text-danger"></i> Top Problems (#1 Ranked)
            </div>
            <div class="card-body p-2">
                <?php if (count($topProblems) > 0): ?>
                    <table class="table table-sm table-borderless mb-0" style="font-size:0.75rem;">
                        <tbody>
                            <?php foreach ($topProblems as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['problem_description'] ?: '-'); ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-danger rounded-pill"><?php echo $p['cnt']; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted mb-0">No problem rankings yet. <a href="<?php echo BASE_URL; ?>/interview-add.php">Start an interview</a>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Follow-Ups -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-calendar-check text-info"></i> Upcoming Follow-Ups
            </div>
            <div class="card-body p-0">
                <?php if (count($upcomingFollowUps) > 0): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcomingFollowUps as $fu): ?>
                            <div class="list-group-item" style="padding:0.5rem 0.75rem;">
                                <div class="d-flex justify-content-between">
                                    <strong style="font-size:0.8rem;"><?php echo htmlspecialchars($fu['farmer_name']); ?></strong>
                                    <span class="badge bg-info"><?php echo $fu['follow_up_date'] ? date('M j', strtotime($fu['follow_up_date'])) : 'Soon'; ?></span>
                                </div>
                                <small class="text-muted" style="font-size:0.7rem;"><?php echo htmlspecialchars($fu['follow_up_reason'] ?: 'Follow-up'); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card-body">
                        <p class="text-muted mb-0">No follow-ups scheduled.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Interviews -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history text-primary"></i> Recent Interviews</span>
                <a href="<?php echo BASE_URL; ?>/interviews.php" class="small text-decoration-none">View All &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recentInterviews) > 0): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentInterviews as $i): 
                            $st = $i['interview_status'] ?? 'draft'; 
                            $stBadge = [
                                'draft' => 'secondary',
                                'submitted' => 'primary',
                                'approved' => 'success',
                                'rejected' => 'warning text-dark',
                            ];
                            $stLabels = [
                                'draft' => 'Draft',
                                'submitted' => 'Submitted',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ];
                            $badgeClass = $stBadge[$st] ?? 'secondary';
                            $label = $stLabels[$st] ?? ucfirst($st);
                            $sourceType = $i['source_type'] ?? 'Farmer';
                            $viewPage = $sourceType === 'Stakeholder' ? 'participant-interview-view' : 'interview-view';
                            $sourceBadge = $sourceType === 'Stakeholder' ? 'info' : 'success';
                        ?>
                            <a href="<?php echo BASE_URL; ?>/<?php echo $viewPage; ?>.php?id=<?php echo $i['id']; ?>"
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" style="padding:0.5rem 0.75rem;">
                                <div>
                                    <strong style="font-size:0.8rem;"><?php echo htmlspecialchars($i['participant_name']); ?></strong>
                                    <div class="text-muted" style="font-size:0.65rem;">
                                        <span class="badge bg-<?php echo $sourceBadge; ?>" style="font-size:0.55rem;"><?php echo $sourceType; ?></span>
                                        <?php echo htmlspecialchars($i['interviewer_name']); ?>
                                        &middot; <?php echo $i['interview_date']; ?>
                                        &middot; <span class="badge bg-<?php echo $badgeClass; ?>" style="font-size:0.55rem;"><?php echo $label; ?></span>
                                    </div>
                                </div>
                                <i class="bi bi-chevron-right text-muted" style="font-size:0.8rem;"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card-body">
                        <p class="text-muted mb-0">No interviews recorded yet. <a href="<?php echo BASE_URL; ?>/interview-add.php">Conduct your first interview</a>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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

    // 1. Voice Interest Pie Chart
    var voiceCanvas = document.getElementById('voiceChart');
    if (voiceCanvas) {
        new Chart(voiceCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($voiceChartLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($voiceChartData); ?>,
                    backgroundColor: <?php echo json_encode($voiceChartColors); ?>,
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

    // 2. Top Problems Bar Chart
    var problemsCanvas = document.getElementById('problemsChart');
    if (problemsCanvas) {
        new Chart(problemsCanvas, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($problemLabels); ?>,
                datasets: [{
                    label: 'Interviews',
                    data: <?php echo json_encode($problemCounts); ?>,
                    backgroundColor: <?php echo json_encode($problemColors); ?>,
                    borderRadius: 4,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                plugins: getPluginOpts(),
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: isDark() ? '#94a3b8' : '#6c757d',
                        },
                        grid: {
                            color: isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
                        }
                    },
                    y: {
                        ticks: {
                            color: isDark() ? '#94a3b8' : '#6c757d',
                            font: { size: 11 }
                        },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // 3. Monthly Interview & Observation Trends Line Chart
    var trendsCanvas = document.getElementById('trendsChart');
    if (trendsCanvas) {
        new Chart(trendsCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendLabels); ?>,
                datasets: [
                    {
                        label: 'Interviews',
                        data: <?php echo json_encode($trendData); ?>,
                        fill: true,
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        borderColor: '#0d6efd',
                        borderWidth: 2,
                        pointBackgroundColor: '#0d6efd',
                        pointBorderColor: isDark() ? '#1e293b' : '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                    },
                    {
                        label: 'Observations',
                        data: <?php echo json_encode($obsTrendData); ?>,
                        fill: true,
                        backgroundColor: 'rgba(33, 37, 41, 0.08)',
                        borderColor: '#212529',
                        borderWidth: 2,
                        borderDash: [5, 3],
                        pointBackgroundColor: '#212529',
                        pointBorderColor: isDark() ? '#1e293b' : '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                    }
                ]
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

// Date preset buttons
document.querySelectorAll('.date-preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var preset = this.getAttribute('data-preset');
        var form = document.getElementById('dateFilterForm');
        document.getElementById('datePresetInput').value = preset;
        if (preset === 'custom') {
            document.getElementById('customDateRange').style.display = '';
        } else {
            form.submit();
        }
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
