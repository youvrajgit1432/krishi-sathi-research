<?php
/**
 * Krishi Sathi Research System - Observations List (Phase 2)
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'delete') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observations.php');
        exit;
    }
    if (!canEdit()) {
        setFlash('error', 'You do not have permission to delete observations.');
    } else {
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        foreach ($ids as $deleteId) {
            softDeleteRecord($pdo, 'research_observations', $deleteId);
            logAudit($pdo, 'observation_deleted', 'observation', $deleteId, 'Bulk delete');
        }
        setFlash('success', count($ids) . ' observation(s) moved to recycle bin.');
    }
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

// ─── Filters ────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$farmCondF    = trim($_GET['farm_condition'] ?? '');
$districtF    = trim($_GET['district'] ?? '');
$observerF    = (int) ($_GET['observer_id'] ?? 0);
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();

$sql = "SELECT o.*,
        COALESCE(rf.name, rp.name) AS entity_name,
        rf.farmer_id AS farmer_code, rp.participant_id AS participant_code,
        rf.district AS farmer_district, rp.district AS participant_district,
        ru.full_name AS observer_name,
        (SELECT COUNT(*) FROM research_photos WHERE observation_id = o.id) AS photo_count,
        ri.interview_status AS interview_status
        FROM research_observations o
        LEFT JOIN research_farmers rf ON o.farmer_id = rf.id
        LEFT JOIN research_participants rp ON o.participant_id = rp.id
        JOIN research_users ru ON o.observer_id = ru.id
        LEFT JOIN research_interviews ri ON o.interview_id = ri.id";
$conditions = [activeWhere('o')];

// ROLE-ACCESS-01.3: Contributors can see observations not linked to approved interviews
if (isContributor()) {
    $conditions[] = "((ri.id IS NULL OR COALESCE(ri.interview_status, '') != 'approved') "
        . "AND (o.interview_id IS NULL OR NOT EXISTS (
            SELECT 1 FROM research_interviews ri2 
            WHERE ri2.id = o.interview_id 
            AND (ri2.interview_status = 'approved' OR ri2.interview_status = 'completed')
        )))";
}
$params = [];

if ($search !== '') {
    $conditions[] = "(rf.name LIKE ? OR rf.farmer_id LIKE ? OR rp.name LIKE ? OR rp.participant_id LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($farmCondF !== '') {
    $conditions[] = "o.farm_condition = ?";
    $params[] = $farmCondF;
}
if ($districtF !== '') {
    $conditions[] = "(rf.district = ? OR rp.district = ?)";
    $params = array_merge($params, [$districtF, $districtF]);
}
if ($observerF > 0) {
    $conditions[] = "o.observer_id = ?";
    $params[] = $observerF;
}
if ($dateFrom !== '') {
    $conditions[] = "o.observation_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "o.observation_date <= ?";
    $params[] = $dateTo;
}

$whereClause = " WHERE " . implode(' AND ', $conditions);

// Count total for pagination
$countSql = "SELECT COUNT(*) FROM research_observations o
    LEFT JOIN research_farmers rf ON o.farmer_id = rf.id
    LEFT JOIN research_participants rp ON o.participant_id = rp.id
    JOIN research_users ru ON o.observer_id = ru.id
    LEFT JOIN research_interviews ri ON o.interview_id = ri.id" . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalObservations = (int) $countStmt->fetchColumn();

// Pagination
[$page, $totalPages, $offset] = paginationData($totalObservations);

$sql .= $whereClause;
$sql .= " ORDER BY o.observation_date DESC, o.created_at DESC";
$sql .= " LIMIT " . PER_PAGE . " OFFSET " . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$observations = $stmt->fetchAll();

// ─── Filter dropdown options ────────────────────────────────────
$districtsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_farmers WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$districtsStmt->execute();
$farmerDistricts = $districtsStmt->fetchAll(PDO::FETCH_COLUMN);
$partDistrictsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_participants WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$partDistrictsStmt->execute();
$partDistricts = $partDistrictsStmt->fetchAll(PDO::FETCH_COLUMN);
$districts = array_unique(array_merge($farmerDistricts, $partDistricts));
sort($districts);
$observersStmt = $pdo->prepare("SELECT id, full_name FROM research_users WHERE " . activeWhere() . " ORDER BY full_name");
$observersStmt->execute();
$observers = $observersStmt->fetchAll();

$farmConditionFilterOpts = ['Well-maintained', 'Moderate', 'Poor'];

function selected($a, $b) { return $a === $b ? 'selected' : ''; }

$pageTitle = 'Observations - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-binoculars"></i> Observations</h4>
    <a href="<?php echo BASE_URL; ?>/observation-add.php" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> New Observation
    </a>
</div>

<!-- Filters -->
<form method="get" class="mb-3">
    <div id="obsFilterExtra" class="d-none d-md-block">
    <div class="row g-2">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name or ID..."
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-2">
            <select name="farm_condition" class="form-select form-select-sm">
                <option value="">All Conditions</option>
                <?php foreach ($farmConditionFilterOpts as $fc): ?>
                    <option value="<?php echo $fc; ?>" <?php echo selected($farmCondF, $fc); ?>><?php echo $fc; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="district" class="form-select form-select-sm">
                <option value="">All Districts</option>
                <?php foreach ($districts as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo selected($districtF, $d); ?>><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="observer_id" class="form-select form-select-sm">
                <option value="0">All Observers</option>
                <?php foreach ($observers as $ob): ?>
                    <option value="<?php echo $ob['id']; ?>" <?php echo selected($observerF, (int) $ob['id']); ?>><?php echo htmlspecialchars($ob['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><i class="bi bi-funnel"></i></button>
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
            <input type="date" name="date_from" class="form-control form-control-sm" placeholder="From"
                   value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-6 col-md-2">
            <input type="date" name="date_to" class="form-control form-control-sm" placeholder="To"
                   value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-6 d-md-none">
            <button type="button" class="btn btn-sm btn-outline-info w-100" onclick="document.getElementById('obsFilterExtra').classList.toggle('d-none')"><i class="bi bi-funnel"></i> Filters</button>
        </div>
        <div class="col-6 col-md-8 d-flex gap-2">
            <?php if ($search !== '' || $farmCondF !== '' || $districtF !== '' || $observerF > 0 || $datePreset !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                <a href="<?php echo BASE_URL; ?>/observations.php" class="btn btn-sm btn-outline-danger">Clear Filters</a>
            <?php endif; ?>
            <span class="text-muted align-self-center small">
                <?php echo $totalObservations; ?> result(s)
                <?php if ($totalPages > 1): ?>
                    &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
</form>

<?php if (count($observations) > 0): ?>
    <?php if (canEdit()): ?>
    <form method="post" onsubmit="return confirm('Move selected observations to recycle bin?');">
        <?php echo csrf_field(); ?>
        <div class="d-flex gap-2 mb-2">
            <select name="bulk_action" class="form-select form-select-sm" style="max-width:180px;">
                <option value="">Bulk action...</option>
                <option value="delete">Delete selected</option>
            </select>
            <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
        </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <?php if (canEdit()): ?><th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th><?php endif; ?>
                    <th style="width:50px;">#</th>
                    <th>Date</th>
                    <th>Subject</th>
                    <th>Observer</th>
                    <th>Condition</th>
                    <th>Interview</th>
                    <th class="text-center">Photos</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($observations as $index => $o): ?>
                    <tr>
                        <?php if (canEdit()): ?><td><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $o['id']; ?>"></td><?php endif; ?>
                        <td class="text-muted fw-medium"><?php echo $offset + $index + 1; ?></td>
                        <td><?php echo $o['observation_date']; ?></td>
                        <td>
                            <?php if ($o['farmer_id']): ?>
                                <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $o['farmer_id']; ?>" class="text-decoration-none fw-medium">
                                    <?php echo htmlspecialchars($o['entity_name']); ?>
                                </a>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($o['farmer_code']); ?></small>
                            <?php elseif ($o['participant_id']): ?>
                                <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $o['participant_id']; ?>" class="text-decoration-none fw-medium">
                                    <?php echo htmlspecialchars($o['entity_name']); ?>
                                </a>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($o['participant_code']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($o['observer_name']); ?></td>
                        <td><?php echo $o['farm_condition'] ? ucfirst($o['farm_condition']) : '-'; ?></td>
                        <td>
                            <?php if ($o['interview_id']): ?>
                                <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $o['interview_id']; ?>" class="text-decoration-none">
                                    <span class="badge bg-info"><i class="bi bi-chat-dots"></i> #<?php echo $o['interview_id']; ?></span>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($o['photo_count'] > 0): ?>
                                <span class="badge bg-info"><i class="bi bi-camera"></i> <?php echo $o['photo_count']; ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>/observation-view.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if (canEditObservation($o)): ?>
                                <a href="<?php echo BASE_URL; ?>/observation-edit.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (canEdit()): ?></form><?php endif; ?>
    <?php echo renderPagination($page, $totalPages, BASE_URL . '/observations.php'); ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi bi-binoculars" style="font-size: 3rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php echo $search ? 'No observations match your search.' : 'No field observations recorded yet.'; ?></h5>
            <p class="text-muted"><?php echo $search ? 'Try a different search term.' : 'Record your first field observation.'; ?></p>
            <?php if (!$search): ?>
                <a href="<?php echo BASE_URL; ?>/observation-add.php" class="btn btn-success">New Observation</a>
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
