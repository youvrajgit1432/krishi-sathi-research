<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: Interview List
 *
 * Phase 3: Interview Engine
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

$pdo = getDB();

if (isViewer()) {
    setFlash('error', 'Viewers cannot access interview records.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ─── Handle Single Approve/Reject/Unlock ─────────────────────────
$singleAction = $_POST['single_action'] ?? '';
if ($singleAction !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participant-interviews.php');
        exit;
    }
    $interviewId = (int) ($_POST['interview_id'] ?? 0);

    try {
        if ($singleAction === 'approve' && canApproveParticipantInterviews()) {
            $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
            $stmt->execute([$interviewId]);
            $row = $stmt->fetch();
            if ($row && normalizeParticipantInterviewStatus($row['interview_status'] ?? '') === 'submitted') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE research_participant_interviews SET interview_status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                    ->execute([currentUserId(), $interviewId]);
                // Also approve the participant
                $pdo->prepare("UPDATE research_participants SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                    ->execute([currentUserId(), $row['participant_id']]);
                $pdo->commit();
                logAudit($pdo, 'participant_interview_approved', 'participant_interview', $interviewId, 'Approved from list');
                logAudit($pdo, 'participant_approved', 'participant', $row['participant_id'], 'Auto-approved via interview');
                setFlash('success', 'Interview approved.');
            } else {
                setFlash('error', 'Interview not found or not in submitted status.');
            }
        } elseif ($singleAction === 'reject' && canApproveParticipantInterviews()) {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') {
                setFlash('error', 'Rejection reason is required.');
            } else {
                $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
                $stmt->execute([$interviewId]);
                $row = $stmt->fetch();
                if ($row && normalizeParticipantInterviewStatus($row['interview_status'] ?? '') === 'submitted') {
                    $pdo->prepare("UPDATE research_participant_interviews SET interview_status='rejected', rejected_at=NOW(), rejected_by=?, rejection_reason=? WHERE id=?")
                        ->execute([currentUserId(), $reason, $interviewId]);
                    logAudit($pdo, 'participant_interview_rejected', 'participant_interview', $interviewId, 'Rejected: ' . mb_substr($reason, 0, 200));
                    setFlash('success', 'Interview rejected.');
                } else {
                    setFlash('error', 'Interview not found or not in submitted status.');
                }
            }
        } elseif ($singleAction === 'unlock' && canApproveParticipantInterviews()) {
            $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
            $stmt->execute([$interviewId]);
            $row = $stmt->fetch();
            if ($row && normalizeParticipantInterviewStatus($row['interview_status'] ?? '') === 'approved') {
                $pdo->prepare("UPDATE research_participant_interviews SET interview_status='draft', unlocked_at=NOW(), unlocked_by=? WHERE id=?")
                    ->execute([currentUserId(), $interviewId]);
                logAudit($pdo, 'participant_interview_unlocked', 'participant_interview', $interviewId, 'Unlocked from archived tab');
                setFlash('success', 'Interview unlocked and returned to draft.');
            } else {
                setFlash('error', 'Interview not found or not in approved status.');
            }
        }
    } catch (PDOException $e) {
        setFlash('error', 'Action failed: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/participant-interviews.php' . (in_array($singleAction, ['unlock']) ? '?tab=archived' : ''));
    exit;
}

// ─── Handle Bulk Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participant-interviews.php');
        exit;
    }
    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    $bulkAction = $_POST['bulk_action'];

    if ($bulkAction === 'delete') {
        $deleted = 0;
        foreach ($ids as $deleteId) {
            $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
            $stmt->execute([$deleteId]);
            $row = $stmt->fetch();
            if ($row && canDeleteParticipantInterview($row)) {
                softDeleteRecord($pdo, 'research_participant_interviews', $deleteId);
                logAudit($pdo, 'participant_interview_deleted', 'participant_interview', $deleteId, 'Bulk delete');
                $deleted++;
            }
        }
        setFlash($deleted > 0 ? 'success' : 'info', $deleted > 0 ? "$deleted interview(s) moved to recycle bin." : 'No interviews could be deleted.');
    } elseif ($bulkAction === 'reject' && canApproveParticipantInterviews()) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            setFlash('error', 'Rejection reason is required.');
            header('Location: ' . BASE_URL . '/participant-interviews.php');
            exit;
        }
        $rejected = 0;
        foreach ($ids as $rejectId) {
            $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
            $stmt->execute([$rejectId]);
            $row = $stmt->fetch();
            if ($row && normalizeParticipantInterviewStatus($row['interview_status'] ?? '') === 'submitted') {
                $pdo->prepare("UPDATE research_participant_interviews SET interview_status='rejected', rejected_at=NOW(), rejected_by=?, rejection_reason=? WHERE id=?")
                    ->execute([currentUserId(), $reason, $rejectId]);
                logAudit($pdo, 'participant_interview_rejected', 'participant_interview', $rejectId, 'Bulk reject');
                $rejected++;
            }
        }
        setFlash($rejected > 0 ? 'success' : 'info', $rejected > 0 ? "$rejected interview(s) rejected." : 'No interviews could be rejected.');
    } elseif ($bulkAction === 'approve' && canApproveParticipantInterviews()) {
        $approved = 0;
        $participantIds = [];
        try {
            $pdo->beginTransaction();
            foreach ($ids as $approveId) {
                $stmt = $pdo->prepare("SELECT * FROM research_participant_interviews WHERE id = ?");
                $stmt->execute([$approveId]);
                $row = $stmt->fetch();
                if ($row && normalizeParticipantInterviewStatus($row['interview_status'] ?? '') === 'submitted') {
                    $pdo->prepare("UPDATE research_participant_interviews SET interview_status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                        ->execute([currentUserId(), $approveId]);
                    logAudit($pdo, 'participant_interview_approved', 'participant_interview', $approveId, 'Bulk approve');
                    $participantIds[] = (int) $row['participant_id'];
                    $approved++;
                }
            }
            if (!empty($participantIds)) {
                $participantIds = array_unique($participantIds);
                foreach ($participantIds as $pid) {
                    $pdo->prepare("UPDATE research_participants SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                        ->execute([currentUserId(), $pid]);
                    logAudit($pdo, 'participant_approved', 'participant', $pid, 'Bulk-approved via interview');
                }
            }
            $pdo->commit();
            setFlash($approved > 0 ? 'success' : 'info', $approved > 0 ? "$approved interview(s) approved." : 'No interviews could be approved.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            setFlash('error', 'Bulk approval failed: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

// ─── Filters ─────────────────────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$statusFilter  = $_GET['status'] ?? '';
$districtF     = trim($_GET['district'] ?? '');
$municipalityF = trim($_GET['municipality'] ?? '');
$typeF         = trim($_GET['participant_type'] ?? '');
$voiceF        = $_GET['voice_recording_useful'] ?? '';
$reminderF     = $_GET['reminders_valuable'] ?? '';
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();
$interviewerF  = (int) ($_GET['interviewer_id'] ?? 0);

$showArchived  = isset($_GET['tab']) && $_GET['tab'] === 'archived';

$sql = "SELECT pi.*, p.name AS participant_name, p.participant_id AS pid, p.participant_type,
               p.district AS p_district, p.municipality AS p_municipality,
               ru.full_name AS interviewer_name,
               approver.full_name AS approved_by_name
        FROM research_participant_interviews pi
        JOIN research_participants p ON pi.participant_id = p.id
        JOIN research_users ru ON pi.interviewer_id = ru.id
        LEFT JOIN research_users approver ON pi.approved_by = approver.id";
$conditions = [];
$params = [];

if ($showArchived) {
    $conditions[] = visibleArchivedParticipantInterviewWhere('pi');
} else {
    $conditions[] = visibleParticipantInterviewWhere('pi');
}

if ($search !== '') {
    $conditions[] = "(p.name LIKE ? OR p.participant_id LIKE ? OR pi.location LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
if ($statusFilter !== '') {
    $conditions[] = "pi.interview_status = ?";
    $params[] = $statusFilter;
}
if ($districtF !== '') {
    $conditions[] = "p.district = ?";
    $params[] = $districtF;
}
if ($municipalityF !== '') {
    $conditions[] = "p.municipality = ?";
    $params[] = $municipalityF;
}
if ($typeF !== '') {
    $conditions[] = "p.participant_type = ?";
    $params[] = $typeF;
}
if ($voiceF !== '') {
    $conditions[] = "pi.voice_recording_useful = ?";
    $params[] = $voiceF;
}
if ($reminderF !== '') {
    $conditions[] = "pi.reminders_valuable = ?";
    $params[] = $reminderF;
}
if ($dateFrom !== '') {
    $conditions[] = "pi.interview_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "pi.interview_date <= ?";
    $params[] = $dateTo;
}
if ($interviewerF > 0) {
    $conditions[] = "pi.interviewer_id = ?";
    $params[] = $interviewerF;
}

$whereClause = !empty($conditions) ? " WHERE " . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) FROM research_participant_interviews pi
    JOIN research_participants p ON pi.participant_id = p.id
    JOIN research_users ru ON pi.interviewer_id = ru.id" . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalInterviews = (int) $countStmt->fetchColumn();

[$page, $totalPages, $offset] = paginationData($totalInterviews);

$sql .= $whereClause . " ORDER BY pi.interview_date DESC, pi.created_at DESC LIMIT " . PER_PAGE . " OFFSET " . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$interviews = $stmt->fetchAll();

// ─── Filter options ──────────────────────────────────────────────
$districtsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_participants WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$districtsStmt->execute();
$districts = $districtsStmt->fetchAll(PDO::FETCH_COLUMN);

$interviewersStmt = $pdo->prepare("SELECT id, full_name FROM research_users WHERE " . activeWhere() . " ORDER BY full_name");
$interviewersStmt->execute();
$interviewers = $interviewersStmt->fetchAll();

$locData = getLocationData();
$availableMunicipalities = [];
if ($districtF !== '' && isset($locData[$districtF])) {
    $availableMunicipalities = array_keys($locData[$districtF]);
}

$statuses = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];

function selected($a, $b) { return $a === $b ? 'selected' : ''; }

$pageTitle = 'Stakeholder Interviews - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-0 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-chat-dots"></i> Stakeholder Interviews</h4>
    <div class="d-flex gap-2">
        <?php if (count($interviews) > 0 && !$showArchived && canExportParticipantData()): ?>
            <a href="<?php echo BASE_URL; ?>/export-participant-interviews.php?<?php echo $_SERVER['QUERY_STRING']; ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download"></i> Export CSV
            </a>
        <?php endif; ?>
        <?php if (!$showArchived): ?>
        <a href="<?php echo BASE_URL; ?>/participant-interview-add.php" class="btn btn-success btn-sm">
            <i class="bi bi-plus-circle"></i> New Interview
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isContributor()):
    $archivedCount = 0;
    try {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM research_participant_interviews pi WHERE " . visibleArchivedParticipantInterviewWhere('pi'));
        $archivedCount = (int) $countStmt->fetchColumn();
    } catch (PDOException $e) {}
?>
<ul class="nav nav-tabs mb-3 mt-2">
    <li class="nav-item">
        <a class="nav-link <?php echo !$showArchived ? 'active fw-semibold' : ''; ?>"
           href="<?php echo BASE_URL; ?>/participant-interviews.php">
            <i class="bi bi-list-check"></i> Active
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $showArchived ? 'active fw-semibold' : ''; ?>"
           href="<?php echo BASE_URL; ?>/participant-interviews.php?tab=archived">
            <i class="bi bi-archive"></i> Archived
            <?php if ($archivedCount > 0): ?>
                <span class="badge bg-success ms-1 rounded-pill" style="font-size:0.65rem;"><?php echo $archivedCount; ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>
<?php endif; ?>

<?php if ($showArchived): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3">
    <i class="bi bi-info-circle-fill"></i>
    <div><strong>Archived Interviews</strong> &mdash; These interviews have been approved and are read-only.</div>
</div>
<?php endif; ?>

<?php if (!$showArchived): ?>
<form method="get" class="mb-3">
    <div id="piFilterExtra" class="d-none d-md-block">
    <div class="row g-2">
        <div class="col-12 col-md-3">
            <input type="text" name="search" aria-label="Search interviews" class="form-control form-control-sm" placeholder="Search name, ID, location..."
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-6 col-md-2">
            <select name="status" aria-label="Filter by status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo selected($statusFilter, $k); ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="participant_type" aria-label="Filter by participant type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <?php foreach (getParticipantTypes() as $pt): ?>
                    <option value="<?php echo $pt; ?>" <?php echo selected($typeF, $pt); ?>><?php echo $pt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md">
            <select name="district" aria-label="Filter by district" class="form-select form-select-sm" onchange="updateMunicipalityFilter(this)">
                <option value="">All Districts</option>
                <?php foreach ($districts as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo selected($districtF, $d); ?>><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md">
            <select name="municipality" aria-label="Filter by municipality" class="form-select form-select-sm">
                <option value="">All Municipalities</option>
                <?php if (!empty($availableMunicipalities)): ?>
                    <?php foreach ($availableMunicipalities as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo selected($municipalityF, $m); ?>><?php echo htmlspecialchars($m); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-6 col-md">
            <select name="voice_recording_useful" aria-label="Filter by voice interest" class="form-select form-select-sm">
                <option value="">Voice Interest</option>
                <option value="yes" <?php echo selected($voiceF, 'yes'); ?>>Yes</option>
                <option value="maybe" <?php echo selected($voiceF, 'maybe'); ?>>Maybe</option>
                <option value="no" <?php echo selected($voiceF, 'no'); ?>>No</option>
            </select>
        </div>
        <div class="col-6 col-md">
            <select name="interviewer_id" aria-label="Filter by interviewer" class="form-select form-select-sm">
                <option value="0">All Interviewers</option>
                <?php foreach ($interviewers as $iv): ?>
                    <option value="<?php echo $iv['id']; ?>" <?php echo selected($interviewerF, (int) $iv['id']); ?>><?php echo htmlspecialchars($iv['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
    <input type="hidden" name="date_preset" id="datePresetInput" value="<?php echo htmlspecialchars($datePreset); ?>">
    <div class="row g-2 mt-1">
        <div class="col-12 col-md-auto">
            <?php echo renderDatePresets($datePreset); ?>
        </div>
    </div>
    <div class="row g-2 mt-1" id="customDateRange" style="<?php echo $datePreset === 'custom' ? '' : 'display:none;'; ?>">
        <div class="col-6 col-md-2">
            <input type="date" name="date_from" aria-label="Date from" class="form-control form-control-sm" placeholder="From" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-6 col-md-2">
            <input type="date" name="date_to" aria-label="Date to" class="form-control form-control-sm" placeholder="To" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-6 d-md-none">
            <button type="button" class="btn btn-sm btn-outline-info w-100" onclick="document.getElementById('piFilterExtra').classList.toggle('d-none')"><i class="bi bi-funnel"></i> Filters</button>
        </div>
        <div class="col-6 col-md-8 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Filter</button>
            <?php $hasActiveFilters = $search !== '' || $statusFilter !== '' || $typeF !== '' || $districtF !== '' || $municipalityF !== '' || $voiceF !== '' || $reminderF !== '' || $datePreset !== '' || $dateFrom !== '' || $dateTo !== '' || $interviewerF > 0; ?>
            <?php if ($hasActiveFilters): ?>
                <a href="<?php echo BASE_URL; ?>/participant-interviews.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Clear</a>
            <?php endif; ?>
            <span class="text-muted align-self-center small ms-auto">
                <?php echo $totalInterviews; ?> result(s)
                <?php if ($totalPages > 1): ?> &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?><?php endif; ?>
            </span>
        </div>
    </div>
</form>
<?php endif; ?>

<?php if (count($interviews) > 0): ?>
    <?php if (!$showArchived && canDelete()): ?>
    <form method="post" onsubmit="return confirm('Apply bulk action?')">
        <?php echo csrf_field(); ?>
        <div class="d-flex gap-2 mb-2 flex-wrap">
            <select name="bulk_action" class="form-select form-select-sm" style="max-width:180px;">
                <option value="">Bulk action...</option>
                <?php if (isLead()): ?><option value="approve">Approve selected</option><?php endif; ?>
                <?php if (canDelete()): ?><option value="delete">Delete selected</option><?php endif; ?>
            </select>
            <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
        </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr class="<?php echo $showArchived ? 'table-success' : ''; ?>">
                    <?php if (!$showArchived && canDelete()): ?><th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th><?php endif; ?>
                    <th style="width:50px;">#</th>
                    <th>Date</th>
                    <th>Participant</th>
                    <th>Type</th>
                    <th>Interviewer</th>
                    <th class="d-none d-md-table-cell">Duration</th>
                    <th class="d-none d-md-table-cell">District</th>
                    <?php if (!$showArchived): ?>
                    <th class="d-none d-md-table-cell">Status</th>
                    <th class="d-none d-md-table-cell">Voice</th>
                    <?php endif; ?>
                    <?php if ($showArchived): ?>
                    <th class="d-none d-md-table-cell">Approved By</th>
                    <th class="d-none d-md-table-cell">Approved At</th>
                    <?php endif; ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($interviews as $index => $i): ?>
                    <tr class="<?php echo $showArchived ? 'table-success opacity-85' : ''; ?>">
                        <?php if (!$showArchived && canDelete()): ?>
                            <td><?php if (canDeleteParticipantInterview($i)): ?><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $i['id']; ?>"><?php endif; ?></td>
                        <?php endif; ?>
                        <td class="text-muted fw-medium"><?php echo $offset + $index + 1; ?></td>
                        <td data-label="Date"><?php echo $i['interview_date']; ?></td>
                        <td data-label="Participant">
                            <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $i['participant_id']; ?>" class="text-decoration-none fw-medium">
                                <?php echo htmlspecialchars($i['participant_name']); ?>
                            </a>
                            <?php if ($showArchived): ?><span class="badge bg-success-subtle text-success ms-1"><i class="bi bi-shield-check"></i> Approved</span><?php endif; ?>
                        </td>

                        <td data-label="Type"><?php echo participantTypeBadge($i['participant_type']); ?></td>
                        <td data-label="Interviewer"><small><?php echo htmlspecialchars($i['interviewer_name']); ?></small></td>
                        <td class="d-none d-md-table-cell" data-label="Duration"><small><?php echo $i['duration_minutes'] ? $i['duration_minutes'] . ' min' : '-'; ?></small></td>
                        <td class="d-none d-md-table-cell" data-label="District"><small class="text-muted"><?php echo htmlspecialchars($i['p_district'] ?: '-'); ?></small></td>
                        <?php if (!$showArchived): ?>
                        <td class="d-none d-md-table-cell" data-label="Status"><?php echo participantInterviewStatusBadge($i['interview_status'] ?? 'draft', isDeletedRow($i)); ?></td>
                        <td class="d-none d-md-table-cell" data-label="Voice">
                            <?php if ($i['voice_recording_useful'] === 'yes'): ?><span class="badge bg-success">Yes</span>
                            <?php elseif ($i['voice_recording_useful'] === 'maybe'): ?><span class="badge bg-warning text-dark">Maybe</span>
                            <?php elseif ($i['voice_recording_useful'] === 'no'): ?><span class="badge bg-danger">No</span>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showArchived): ?>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($i['approved_by_name'] ?? '-'); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo $i['approved_at'] ? date('M j, Y', strtotime($i['approved_at'])) : '-'; ?></small></td>
                        <?php endif; ?>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>/participant-interview-view.php?id=<?php echo $i['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if ($showArchived && canApproveParticipantInterviews() && normalizeParticipantInterviewStatus($i['interview_status'] ?? '') === 'approved'): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" title="Unlock"
                                        onclick="if(confirm('Unlock this approved interview?')){
                                            var f=document.createElement('form');f.method='post';f.style.display='none';
                                            var t=document.createElement('input');t.name='_csrf_token';t.value='<?php echo csrf_token(); ?>';f.appendChild(t);
                                            var a=document.createElement('input');a.name='single_action';a.value='unlock';f.appendChild(a);
                                            var b=document.createElement('input');b.name='interview_id';b.value='<?php echo (int) $i['id']; ?>';f.appendChild(b);
                                            document.body.appendChild(f);f.submit();
                                        }"><i class="bi bi-unlock"></i></button>
                            <?php endif; ?>
                            <?php if (canApproveParticipantInterviews() && normalizeParticipantInterviewStatus($i['interview_status'] ?? '') === 'submitted'): ?>
                                <button type="button" class="btn btn-sm btn-outline-success" title="Approve"
                                        onclick="if(confirm('Approve this interview?')){
                                            var f=document.createElement('form');f.method='post';f.style.display='none';
                                            var t=document.createElement('input');t.name='_csrf_token';t.value='<?php echo csrf_token(); ?>';f.appendChild(t);
                                            var a=document.createElement('input');a.name='single_action';a.value='approve';f.appendChild(a);
                                            var b=document.createElement('input');b.name='interview_id';b.value='<?php echo (int) $i['id']; ?>';f.appendChild(b);
                                            document.body.appendChild(f);f.submit();
                                        }"><i class="bi bi-shield-check"></i></button>
                            <?php endif; ?>
                            <?php if (canEditParticipantInterview($i) && !$showArchived): ?>
                                <a href="<?php echo BASE_URL; ?>/participant-interview-edit.php?id=<?php echo $i['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (!$showArchived && canDeleteParticipantInterview($i)): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" style="opacity:0.6;"
                                        onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'"
                                        onclick="if(confirm('Delete interview #<?php echo (int) $i['id']; ?>?')){
                                            var f=document.createElement('form');f.method='post';f.style.display='none';
                                            var t=document.createElement('input');t.name='_csrf_token';t.value='<?php echo csrf_token(); ?>';f.appendChild(t);
                                            var a=document.createElement('input');a.name='ids[]';a.value='<?php echo (int) $i['id']; ?>';f.appendChild(a);
                                            var b=document.createElement('input');b.name='bulk_action';b.value='delete';f.appendChild(b);
                                            document.body.appendChild(f);f.submit();
                                        }"><i class="bi bi-trash3"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$showArchived && !isViewer()): ?></form><?php endif; ?>
    <?php echo renderPagination($page, $totalPages, BASE_URL . '/participant-interviews.php' . ($showArchived ? '?tab=archived' : '')); ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi <?php echo $showArchived ? 'bi-archive' : 'bi-search'; ?>" style="font-size: 3rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php echo $showArchived ? 'No archived interviews yet.' : 'No interviews match your filters.'; ?></h5>
            <p class="text-muted"><?php echo $showArchived ? 'Approved interviews will appear here.' : 'Try adjusting your filter criteria.'; ?></p>
            <?php if ($showArchived): ?>
                <a href="<?php echo BASE_URL; ?>/participant-interviews.php" class="btn btn-sm btn-outline-secondary">Back to Active</a>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/participant-interview-add.php" class="btn btn-success">Conduct First Interview</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
const locationData = <?php echo getLocationDataJson(); ?>;
function updateMunicipalityFilter(el) {
    const district = el.value;
    const munSelect = el.closest('form') ? el.closest('form').querySelector('[name="municipality"]') : null;
    if (!munSelect) return;
    munSelect.innerHTML = '<option value="">All Municipalities</option>';
    if (district && locationData[district]) {
        const curVal = <?php echo json_encode($municipalityF, JSON_UNESCAPED_UNICODE); ?>;
        Object.keys(locationData[district]).forEach(function(m) {
            const opt = document.createElement('option');
            opt.value = m; opt.textContent = m;
            if (m === curVal) opt.selected = true;
            munSelect.appendChild(opt);
        });
    }
    el.form.submit();
}

// Date preset buttons
document.querySelectorAll('.date-preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var preset = this.getAttribute('data-preset');
        var form = this.closest('form');
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
