<?php
/**
 * Krishi Sathi Research System — Other Stakeholder Participants List
 *
 * Phase 2: Participant CRUD
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

$pdo = getDB();

// Redirect viewers
if (isViewer()) {
    setFlash('error', 'Viewers cannot access participant records.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ─── Handle Bulk Actions (POST) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulkAction = $_POST['bulk_action'] ?? '';

    if ($bulkAction !== '') {
        $token = $_POST['_csrf_token'] ?? '';
        if (!verify_csrf((string) $token)) {
            setFlash('error', 'Invalid form submission (CSRF).');
            header('Location: ' . BASE_URL . '/participants.php');
            exit;
        }

        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));

        if ($bulkAction === 'delete') {
            if (!isLead() && !isEditor()) {
                setFlash('error', 'You do not have permission to delete stakeholders.');
            } else {
                try {
                    foreach ($ids as $deleteId) {
                        softDeleteRecord($pdo, 'research_participants', $deleteId);
                        logAudit($pdo, 'participant_deleted', 'participant', $deleteId, 'Bulk delete');
                    }
                    setFlash('success', count($ids) . ' stakeholder(s) moved to recycle bin.');
                } catch (PDOException $e) {
                    setFlash('error', 'Database error: ' . $e->getMessage());
                }
            }
        } elseif ($bulkAction === 'approve' && isLead()) {
            $approved = 0;
            try {
                $pdo->beginTransaction();
                foreach ($ids as $approveId) {
                    $stmt = $pdo->prepare("SELECT * FROM research_participants WHERE id = ?");
                    $stmt->execute([$approveId]);
                    $row = $stmt->fetch();
                    if ($row && (empty($row['approval_status']) || $row['approval_status'] !== 'approved')) {
                        $pdo->prepare("UPDATE research_participants SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                            ->execute([currentUserId(), $approveId]);
                        logAudit($pdo, 'participant_approved', 'participant', $approveId, 'Bulk approve: Participant #' . $approveId);
                        $approved++;
                    }
                }
                $pdo->commit();
                setFlash($approved > 0 ? 'success' : 'info',
                    $approved > 0 ? "$approved stakeholder(s) approved and transitioned to protected dataset."
                                  : 'No stakeholders could be approved (they may already be approved).');
            } catch (PDOException $e) {
                $pdo->rollBack();
                setFlash('error', 'Bulk approval failed: ' . $e->getMessage());
            }
        }

        header('Location: ' . BASE_URL . '/participants.php');
        exit;
    }
}

// ─── Handle Single Delete (POST) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'delete') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participants.php');
        exit;
    }
    $deleteId = (int) ($_POST['record_id'] ?? 0);
    if (!isLead() && !isEditor()) {
        setFlash('error', 'You do not have permission to delete stakeholders.');
    } else {
        try {
            softDeleteRecord($pdo, 'research_participants', $deleteId);
            logAudit($pdo, 'participant_deleted', 'participant', $deleteId, 'Deleted (soft)');
            setFlash('success', 'Stakeholder moved to recycle bin.');
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

// ─── Handle Single Approve (POST) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'approve' && isLead()) {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participants.php');
        exit;
    }
    $participantId = (int) ($_POST['record_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM research_participants WHERE id = ?");
        $stmt->execute([$participantId]);
        $row = $stmt->fetch();
        if ($row && (empty($row['approval_status']) || $row['approval_status'] !== 'approved')) {
            $pdo->prepare("UPDATE research_participants SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([currentUserId(), $participantId]);
            logAudit($pdo, 'participant_approved', 'participant', $participantId, 'Approved from list');
            setFlash('success', 'Stakeholder approved and transitioned to protected dataset.');
        } else {
            setFlash('error', 'Stakeholder not found or already approved.');
        }
    } catch (PDOException $e) {
        setFlash('error', 'Approval failed: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

// ─── Handle Restore (POST) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'restore') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participants.php');
        exit;
    }
    $restoreId = (int) ($_POST['record_id'] ?? 0);
    if (!isLead()) {
        setFlash('error', 'Research Lead role required to restore stakeholders.');
    } else {
        try {
            restoreRecord($pdo, 'research_participants', $restoreId);
            logAudit($pdo, 'participant_restored', 'participant', $restoreId, 'Restored from recycle bin');
            setFlash('success', 'Stakeholder restored from recycle bin.');
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/participants.php' . (isset($_POST['trash']) && $_POST['trash'] === '1' ? '?trash=1' : ''));
    exit;
}

// ─── Determine View ────────────────────────────────────────────
$showTrash = isset($_GET['trash']) && $_GET['trash'] === '1';
if ($showTrash && !canManageRecycleBin()) {
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}
$showArchived = isset($_GET['tab']) && $_GET['tab'] === 'archived';

$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'registered_desc';
$districtF = trim($_GET['district'] ?? '');
$typeF = trim($_GET['participant_type'] ?? '');
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();

function selected($a, $b) { return $a === $b ? 'selected' : ''; }

// ─── Build Query ───────────────────────────────────────────────
if ($showTrash) {
    $sql = "SELECT p.*, deleter.full_name AS deleted_by_name,
                   (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = p.id) AS interview_count,
                   (SELECT MAX(interview_date) FROM research_participant_interviews WHERE participant_id = p.id AND " . activeWhere() . ") AS last_interview_date
            FROM research_participants p
            LEFT JOIN research_users deleter ON p.deleted_by = deleter.id
            WHERE " . deletedWhere('p');
} elseif ($showArchived) {
    $sql = "SELECT p.*, approver.full_name AS approved_by_name,
                   (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = p.id) AS interview_count,
                   (SELECT MAX(interview_date) FROM research_participant_interviews WHERE participant_id = p.id AND " . activeWhere() . ") AS last_interview_date
            FROM research_participants p
            LEFT JOIN research_users approver ON p.approved_by = approver.id
            WHERE " . visibleArchivedParticipantWhere('p');
} else {
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = p.id) AS interview_count,
                   (SELECT MAX(interview_date) FROM research_participant_interviews WHERE participant_id = p.id AND " . activeWhere() . ") AS last_interview_date
            FROM research_participants p
            WHERE " . visibleParticipantWhere('p');
}
$params = [];

if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.phone LIKE ? OR p.participant_id LIKE ? OR p.district LIKE ? OR p.participant_type LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($districtF !== '') {
    $sql .= " AND p.district = ?";
    $params[] = $districtF;
}
if ($typeF !== '') {
    $sql .= " AND p.participant_type = ?";
    $params[] = $typeF;
}
if ($dateFrom !== '') {
    $sql .= " AND p.created_at >= DATE(?)";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= " AND p.created_at <= DATE(?)";
    $params[] = $dateTo . ' 23:59:59';
}

$sql .= " GROUP BY p.id";

// Count for pagination
$countWhere = $showTrash ? deletedWhere('p') : ($showArchived ? visibleArchivedParticipantWhere('p') : visibleParticipantWhere('p'));
$countSql = "SELECT COUNT(*) FROM research_participants p WHERE " . $countWhere;
$countParams = [];
if ($search !== '') {
    $countSql .= " AND (p.name LIKE ? OR p.phone LIKE ? OR p.participant_id LIKE ? OR p.district LIKE ? OR p.participant_type LIKE ?)";
    $countParams = array_merge($countParams, [$like, $like, $like, $like, $like]);
}
if ($districtF !== '') {
    $countSql .= " AND p.district = ?";
    $countParams[] = $districtF;
}
if ($typeF !== '') {
    $countSql .= " AND p.participant_type = ?";
    $countParams[] = $typeF;
}
if ($dateFrom !== '') {
    $countSql .= " AND p.created_at >= DATE(?)";
    $countParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $countSql .= " AND p.created_at <= DATE(?)";
    $countParams[] = $dateTo . ' 23:59:59';
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$totalParticipants = (int) $countStmt->fetchColumn();

// Pagination
[$page, $totalPages, $offset] = paginationData($totalParticipants);

// Sort
switch ($sort) {
    case 'registered_asc':
        $sql .= " ORDER BY p.created_at ASC";
        break;
    case 'last_interview_desc':
        $sql .= " ORDER BY last_interview_date DESC, p.created_at DESC";
        break;
    case 'last_interview_asc':
        $sql .= " ORDER BY last_interview_date ASC, p.created_at DESC";
        break;
    case 'name_asc':
        $sql .= " ORDER BY p.name ASC";
        break;
    case 'type':
        $sql .= " ORDER BY p.participant_type ASC, p.name ASC";
        break;
    default:
        $sql .= " ORDER BY p.created_at DESC";
}
$sql .= " LIMIT " . PER_PAGE . " OFFSET " . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$participants = $stmt->fetchAll();

// ─── Filter dropdown options ────────────────────────────────────
$districtsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_participants WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$districtsStmt->execute();
$districts = $districtsStmt->fetchAll(PDO::FETCH_COLUMN);
$participantTypes = getParticipantTypes();

$pageTitle = $showTrash ? 'Recycle Bin - Stakeholders - Krishi Sathi Research' : 'Stakeholders - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">
        <?php if ($showTrash): ?>
            <i class="bi bi-trash"></i> Stakeholder Recycle Bin
        <?php else: ?>
            <i class="bi bi-people"></i> Other Stakeholders
        <?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if (isLead()): ?>
            <a href="<?php echo BASE_URL . '/recycle-bin.php?type=participants'; ?>"
               class="btn btn-sm <?php echo $showTrash ? 'btn-outline-secondary' : 'btn-outline-warning'; ?>">
                <i class="bi bi-trash"></i> Recycle Bin
            </a>
        <?php endif; ?>
        <?php if (!$showTrash && !$showArchived): ?>
            <a href="<?php echo BASE_URL; ?>/participant-add.php" class="btn btn-success">
                <i class="bi bi-person-plus"></i> Add Stakeholder
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$showTrash && isContributor()):
    $archivedCount = 0;
    try {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM research_participants p WHERE " . visibleArchivedParticipantWhere('p'));
        $archivedCount = (int) $countStmt->fetchColumn();
    } catch (PDOException $e) {}
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo !$showArchived ? 'active fw-semibold' : ''; ?>"
           href="<?php echo BASE_URL; ?>/participants.php">
            <i class="bi bi-list-check"></i> Active
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $showArchived ? 'active fw-semibold' : ''; ?>"
           href="<?php echo BASE_URL; ?>/participants.php?tab=archived">
            <i class="bi bi-archive"></i> Archived
            <?php if ($archivedCount > 0): ?>
                <span class="badge bg-success ms-1 rounded-pill" style="font-size:0.65rem;">
                    <?php echo $archivedCount; ?>
                </span>
            <?php endif; ?>
        </a>
    </li>
</ul>
<?php endif; ?>

<?php if ($showArchived): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2">
    <i class="bi bi-info-circle-fill"></i>
    <div>
        <strong>Archived Stakeholders</strong> &mdash;
        These records have been approved by the Research Lead and moved to the protected dataset.
        Viewing is read-only.
    </div>
</div>
<?php endif; ?>

<!-- Search + Filters (active view only) -->
<form method="get" class="mb-3">
    <?php if ($showTrash): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
    <?php if ($showArchived): ?><input type="hidden" name="tab" value="archived"><?php endif; ?>
    <div class="card shadow-sm border-0">
        <div class="card-body py-3">
            <div class="row g-2">
                <div class="col-12 col-md-4 d-none d-md-block" id="partFilterExtra">
                    <input type="text" name="search" aria-label="Search stakeholders" class="form-control" placeholder="Search by name, phone, ID, type, or district..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-2 d-none d-md-block">
                    <select name="participant_type" aria-label="Filter by type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <?php foreach ($participantTypes as $pt): ?>
                            <option value="<?php echo $pt; ?>" <?php echo selected($typeF, $pt); ?>><?php echo $pt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-none d-md-block">
                    <select name="district" aria-label="Filter by district" class="form-select form-select-sm">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?php echo htmlspecialchars($d); ?>" <?php echo selected($districtF, $d); ?>><?php echo htmlspecialchars($d); ?></option>
                        <?php endforeach; ?>
                    </select>
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
                    <input type="date" name="date_from" aria-label="Date from" class="form-control form-control-sm" placeholder="From"
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" name="date_to" aria-label="Date to" class="form-control form-control-sm" placeholder="To"
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-12 col-md-3 d-none d-md-block" id="partFilterExtra2">
                    <select name="sort" aria-label="Sort order" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="registered_desc" <?php echo $sort === 'registered_desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="registered_asc" <?php echo $sort === 'registered_asc' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="last_interview_desc" <?php echo $sort === 'last_interview_desc' ? 'selected' : ''; ?>>Last Interview (newest)</option>
                        <option value="last_interview_asc" <?php echo $sort === 'last_interview_asc' ? 'selected' : ''; ?>>Last Interview (oldest)</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                        <option value="type" <?php echo $sort === 'type' ? 'selected' : ''; ?>>Participant Type</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><i class="bi bi-search"></i> Search</button>
                </div>
                <div class="col-6 d-md-none">
                    <button type="button" class="btn btn-sm btn-outline-info w-100" onclick="document.getElementById('partFilterExtra').classList.toggle('d-none');document.getElementById('partFilterExtra2').classList.toggle('d-none')"><i class="bi bi-funnel"></i> Filters</button>
                </div>
                <?php
                $hasActiveFilters = $search !== '' || $sort !== 'registered_desc' || $districtF !== '' || $typeF !== '' || $dateFrom !== '' || $dateTo !== '';
                if ($hasActiveFilters): ?>
                    <div class="col-6 col-md-auto">
                        <a href="<?php echo BASE_URL; ?>/participants.php<?php echo $showTrash ? '?trash=1' : ($showArchived ? '?tab=archived' : ''); ?>" class="btn btn-sm btn-outline-danger w-100">Clear Filters</a>
                    </div>
                <?php endif; ?>
                <div class="col-12 col-md-auto ms-auto">
                    <span class="text-muted align-self-center small">
                        <?php echo $totalParticipants; ?> result(s)
                        <?php if ($totalPages > 1): ?>
                            &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if (count($participants) > 0): ?>
    <?php if (!$showTrash && !$showArchived && (isLead() || isEditor())): ?>
    <form method="post" onsubmit="return confirm('Apply bulk action to selected stakeholders?');">
        <?php echo csrf_field(); ?>
        <div class="d-flex gap-2 mb-2">
            <select name="bulk_action" class="form-select form-select-sm" style="max-width:180px;">
                <option value="">Bulk action...</option>
                <?php if (isLead()): ?>
                <option value="approve">Approve selected</option>
                <?php endif; ?>
                <option value="delete">Delete selected</option>
            </select>
            <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
        </div>
    <?php endif; ?>
    <div class="table-responsive farmer-table">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <?php if (!$showTrash && !$showArchived && (isLead() || isEditor())): ?>
                        <th class="d-none d-md-table-cell" style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th>
                    <?php endif; ?>
                    <th class="d-none d-md-table-cell" style="width:50px;">#</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th class="d-none d-md-table-cell">Phone</th>
                    <th class="d-none d-md-table-cell">Organization</th>
                    <th class="d-none d-md-table-cell">District</th>
                    <th class="text-center d-none d-md-table-cell">GPS</th>
                    <th class="text-center d-none d-md-table-cell">Interviews</th>
                    <th class="d-none d-md-table-cell">Last Interview</th>
                    <th class="d-none d-md-table-cell">Registered</th>
                    <?php if ($showTrash): ?>
                        <th class="d-none d-md-table-cell">Deleted By</th>
                        <th class="d-none d-md-table-cell">Deleted At</th>
                    <?php endif; ?>
                    <?php if ($showArchived): ?>
                        <th class="d-none d-md-table-cell">Approved By</th>
                        <th class="d-none d-md-table-cell">Approved At</th>
                    <?php endif; ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $index => $p): ?>
                    <tr class="<?php echo $showTrash ? 'table-danger opacity-75' : ($showArchived ? 'table-success opacity-85' : ''); ?>">
                        <?php if (!$showTrash && !$showArchived && (isLead() || isEditor())): ?>
                            <td class="d-none d-md-table-cell"><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $p['id']; ?>"></td>
                        <?php endif; ?>
                        <td class="d-none d-md-table-cell text-muted fw-medium"><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $p['id']; ?>" class="text-decoration-none fw-medium">
                                <?php echo htmlspecialchars($p['name']); ?>
                            </a>
                            <?php if ($showArchived): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">
                                    <i class="bi bi-shield-check"></i> Approved
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Type"><?php echo participantTypeBadge($p['participant_type']); ?></td>
                        <td class="d-none d-md-table-cell" data-label="Phone"><?php echo htmlspecialchars($p['phone'] ?: '-'); ?></td>
                        <td class="d-none d-md-table-cell" data-label="Organization"><?php echo htmlspecialchars($p['organization'] ?: '-'); ?></td>
                        <td class="d-none d-md-table-cell" data-label="District"><?php echo htmlspecialchars($p['district'] ?: '-'); ?></td>
                        <td class="text-center d-none d-md-table-cell" data-label="GPS">
                            <?php if ($p['latitude'] && $p['longitude']): ?>
                                <span class="badge bg-success" title="GPS: <?php echo $p['latitude']; ?>, <?php echo $p['longitude']; ?>">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" style="opacity:0.3"><i class="bi bi-geo-alt"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center d-none d-md-table-cell" data-label="Interviews">
                            <span class="badge bg-primary rounded-pill"><?php echo $p['interview_count']; ?></span>
                        </td>
                        <td class="d-none d-md-table-cell" data-label="Last Interview">
                            <?php if ($p['last_interview_date']): ?>
                                <small title="Last interviewed on <?php echo $p['last_interview_date']; ?>"><?php echo date('M j, Y', strtotime($p['last_interview_date'])); ?></small>
                            <?php else: ?>
                                <small class="text-muted">-</small>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell" data-label="Registered"><span title="<?php echo $p['created_at']; ?>"><?php echo date('M j, Y', strtotime($p['created_at'])); ?></span></td>
                        <?php if ($showTrash): ?>
                            <td class="small d-none d-md-table-cell"><?php echo htmlspecialchars($p['deleted_by_name'] ?: 'Unknown'); ?></td>
                            <td class="small d-none d-md-table-cell"><?php echo date('M j, Y g:i a', strtotime($p['deleted_at'])); ?></td>
                        <?php endif; ?>
                        <?php if ($showArchived): ?>
                            <td class="small d-none d-md-table-cell">
                                <?php if (!empty($p['approved_by_name'])): ?>
                                    <?php echo htmlspecialchars($p['approved_by_name']); ?>
                                <?php elseif (!empty($p['approved_by'])): ?>
                                    <span class="text-muted">User #<?php echo (int) $p['approved_by']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="small d-none d-md-table-cell">
                                <?php if ($p['approved_at']): ?>
                                    <?php echo date('M j, Y', strtotime($p['approved_at'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $p['id']; ?>"
                               class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (!$showTrash && !$showArchived && !isViewer()): ?>
                                <a href="<?php echo BASE_URL; ?>/participant-interview-add.php?participant_id=<?php echo $p['id']; ?>"
                                   class="btn btn-sm btn-outline-success" title="Add Interview">
                                    <i class="bi bi-plus-lg"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($showTrash): ?>
                                <?php if (isLead()): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Restore <?php echo str_replace("'", "\\'", $p['name']); ?>?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="single_action" value="restore">
                                        <input type="hidden" name="record_id" value="<?php echo (int) $p['id']; ?>">
                                        <input type="hidden" name="trash" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($showArchived): ?>
                                <!-- Archived: view only -->
                            <?php else: ?>
                                <?php if (!isViewer()): ?>
                                    <?php if (isLead() && (empty($p['approval_status']) || $p['approval_status'] !== 'approved')): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-success"
                                                title="Approve stakeholder"
                                                onclick="if(confirm('Approve <?php echo str_replace("'", "\\'", $p['name']); ?>? This will transition to the protected dataset.')){
                                                    var f=document.createElement('form');f.method='post';f.style.display='none';
                                                    var t=document.createElement('input');t.name='_csrf_token';t.value='<?php echo csrf_token(); ?>';f.appendChild(t);
                                                    var a=document.createElement('input');a.name='single_action';a.value='approve';f.appendChild(a);
                                                    var b=document.createElement('input');b.name='record_id';b.value='<?php echo (int) $p['id']; ?>';f.appendChild(b);
                                                    document.body.appendChild(f);f.submit();
                                                }">
                                            <i class="bi bi-shield-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (empty($p['approval_status']) || $p['approval_status'] !== 'approved'): ?>
                                    <a href="<?php echo BASE_URL; ?>/participant-edit.php?id=<?php echo $p['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete()): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Move <?php echo str_replace("'", "\\'", $p['name']); ?> to recycle bin? Their interviews will be preserved.')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="single_action" value="delete">
                                        <input type="hidden" name="record_id" value="<?php echo (int) $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$showTrash && !$showArchived && (isLead() || isEditor())): ?></form><?php endif; ?>
    <?php if ($totalParticipants > 0): ?>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <small class="text-muted">
                <?php echo $totalParticipants; ?> stakeholder(s)
                <?php if ($totalPages > 1): ?>
                    &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                <?php endif; ?>
            </small>
            <?php echo renderPagination($page, $totalPages, BASE_URL . '/participants.php' . ($showTrash ? '?trash=1' : ($showArchived ? '?tab=archived' : ''))); ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi <?php echo $showTrash ? 'bi-trash' : ($showArchived ? 'bi-archive' : 'bi-people'); ?>" style="font-size: 3rem; color: #ccc;"></i>
            <h5 class="mt-3">
                <?php if ($showTrash): ?>
                    Recycle bin is empty.
                <?php elseif ($showArchived): ?>
                    No archived stakeholders yet.
                <?php else: ?>
                    <?php echo $search ? 'No stakeholders match your search.' : 'No stakeholders registered yet.'; ?>
                <?php endif; ?>
            </h5>
            <p class="text-muted">
                <?php if ($showTrash): ?>
                    Deleted stakeholders will appear here.
                <?php elseif ($showArchived): ?>
                    When approved by the Lead, they'll appear here as read-only records.
                <?php elseif ($search): ?>
                    Try a different search term.
                <?php else: ?>
                    Start by adding your first stakeholder.
                <?php endif; ?>
            </p>
            <?php if (!$search && !$showTrash && !$showArchived): ?>
                <a href="<?php echo BASE_URL; ?>/participant-add.php" class="btn btn-success">Add First Stakeholder</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
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
