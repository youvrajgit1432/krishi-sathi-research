<?php
/**
 * Krishi Sathi Research System - Interviews List
 * Phase 1.5: Enhanced filters for status, district, voice interest, date range, interviewer.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'approve') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interviews.php');
        exit;
    }
    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    $approved = 0;
    try {
        $pdo->beginTransaction();
        foreach ($ids as $approveId) {
            $stmt = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
            $stmt->execute([$approveId]);
            $row = $stmt->fetch();
            if ($row && canApproveInterviews() && normalizeInterviewStatus($row['interview_status'] ?? '') === 'submitted') {
                $pdo->prepare("UPDATE research_interviews SET interview_status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                    ->execute([currentUserId(), $approveId]);
                logAudit($pdo, 'interview_approved', 'interview', $approveId, 'Bulk approve');
                $approved++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
    }
    setFlash($approved > 0 ? 'success' : 'info', $approved > 0 ? "$approved interview(s) approved." : 'No interviews could be approved.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'delete') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interviews.php');
        exit;
    }

    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    $deleted = 0;
    foreach ($ids as $deleteId) {
        $stmt = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
        $stmt->execute([$deleteId]);
        $row = $stmt->fetch();
        if ($row && canDeleteInterview($row)) {
            softDeleteRecord($pdo, 'research_interviews', $deleteId);
            logAudit($pdo, 'interview_deleted', 'interview', $deleteId, 'Bulk delete');
            $deleted++;
        }
    }
    setFlash($deleted > 0 ? 'success' : 'error', $deleted > 0 ? "$deleted interview(s) moved to recycle bin." : 'No selected interviews could be deleted.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

// ─── Filters ────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$districtF    = trim($_GET['district'] ?? '');
$municipalityF = trim($_GET['municipality'] ?? '');
$farmTypeF    = trim($_GET['farm_type'] ?? '');
$voiceF       = $_GET['voice_interest'] ?? '';
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();
$interviewerF = (int) ($_GET['interviewer_id'] ?? 0);
$showDeleted  = $statusFilter === 'deleted';
if ($showDeleted && isContributor()) {
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}
$myContrib    = isset($_GET['my_contributions']) && $_GET['my_contributions'] === '1';

$sql = "SELECT ri.*, rf.name AS farmer_name, rf.farmer_id AS fid, rf.district AS farmer_district, rf.municipality AS farmer_municipality, ru.full_name AS interviewer_name
        FROM research_interviews ri
        JOIN research_farmers rf ON ri.farmer_id = rf.id
        JOIN research_users ru ON ri.interviewer_id = ru.id
        LEFT JOIN research_farm_profiles fp ON fp.farmer_id = rf.id";
$conditions = [];
$params = [];

$conditions[] = $showDeleted ? deletedWhere('ri') : activeWhere('ri');

// Contributors see their own interviews + any unapproved interviews (to add observations)
if (isContributor() && !$showDeleted) {
    $conditions[] = "(ri.interviewer_id = " . (int) currentUserId()
        . " OR (ri.interview_status IS NULL OR ri.interview_status NOT IN ('approved', 'completed')))";
} elseif ($myContrib && !$showDeleted) {
    // My Contributions filter: show only interviews created by the current user
    $conditions[] = "ri.interviewer_id = ?";
    $params[] = currentUserId();
}

if ($search !== '') {
    $conditions[] = "(rf.name LIKE ? OR rf.farmer_id LIKE ? OR ri.location LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
if ($statusFilter !== '' && !$showDeleted) {
    $conditions[] = "ri.interview_status = ?";
    $params[] = $statusFilter;
}
if ($districtF !== '') {
    $conditions[] = "rf.district = ?";
    $params[] = $districtF;
}
if ($municipalityF !== '') {
    $conditions[] = "rf.municipality = ?";
    $params[] = $municipalityF;
}

if ($farmTypeF !== '') {
    $conditions[] = "fp.farm_type LIKE ?";
    $params[] = "%$farmTypeF%";
}
if ($voiceF !== '') {
    $conditions[] = "ri.voice_interest = ?";
    $params[] = $voiceF;
}
if ($dateFrom !== '') {
    $conditions[] = "ri.interview_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "ri.interview_date <= ?";
    $params[] = $dateTo;
}
if ($interviewerF > 0) {
    $conditions[] = "ri.interviewer_id = ?";
    $params[] = $interviewerF;
}

$whereClause = !empty($conditions) ? " WHERE " . implode(' AND ', $conditions) : '';

// Count total for pagination
$countSql = "SELECT COUNT(*) FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    JOIN research_users ru ON ri.interviewer_id = ru.id
    LEFT JOIN research_farm_profiles fp ON fp.farmer_id = rf.id" . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalInterviews = (int) $countStmt->fetchColumn();

// Pagination
[$page, $totalPages, $offset] = paginationData($totalInterviews);

// Main query with LIMIT
$sql .= $whereClause;
$sql .= " ORDER BY ri.interview_date DESC, ri.created_at DESC";
$sql .= " LIMIT " . PER_PAGE . " OFFSET " . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$interviews = $stmt->fetchAll();

// ─── Filter options ─────────────────────────────────────────────
$districtsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_farmers WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$districtsStmt->execute();
$districts = $districtsStmt->fetchAll(PDO::FETCH_COLUMN);
$interviewersStmt = $pdo->prepare("SELECT id, full_name FROM research_users WHERE " . activeWhere() . " ORDER BY full_name");
$interviewersStmt->execute();
$interviewers = $interviewersStmt->fetchAll();

// ─── Location data for cascading municipality filter ────────────
$locData = getLocationData();
$availableMunicipalities = [];
if ($districtF !== '' && isset($locData[$districtF])) {
    $availableMunicipalities = array_keys($locData[$districtF]);
}

$statuses = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];
if (!isContributor()) {
    $statuses['deleted'] = 'Deleted';
}

function selected($a, $b) { return $a === $b ? 'selected' : ''; }

$pageTitle = 'Interviews - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-chat-dots"></i> Interviews</h4>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>/interviews.php?my_contributions=1"
           class="btn btn-sm <?php echo $myContrib ? 'btn-success' : 'btn-outline-success'; ?>">
            <i class="bi bi-person-check"></i> My Contributions
        </a>
        <?php if (count($interviews) > 0 && !isContributor()): ?>
            <a href="<?php echo BASE_URL; ?>/export-interviews.php?<?php echo $_SERVER['QUERY_STRING']; ?>" class="btn btn-outline-success">
                <i class="bi bi-download"></i> Export CSV
            </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/interview-add.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> New Interview
        </a>
    </div>
</div>

<!-- Filters -->
<form method="get" class="mb-3">
    <div id="intFilterExtra" class="d-none d-md-block">
    <div class="row g-2">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, ID, location..."
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo selected($statusFilter, $k); ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="district" id="filterDistrict" class="form-select form-select-sm" onchange="updateMunicipalityFilter()">
                <option value="">All Districts</option>
                <?php foreach ($districts as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo selected($districtF, $d); ?>><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="municipality" id="filterMunicipality" class="form-select form-select-sm">
                <option value="">All Municipalities</option>
                <?php if (!empty($availableMunicipalities)): ?>
                    <?php foreach ($availableMunicipalities as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo selected($municipalityF, $m); ?>><?php echo htmlspecialchars($m); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="farm_type" class="form-select form-select-sm">
                <option value="">All Farm Types</option>
                <?php
                $farmTypes = ['Crop', 'Livestock', 'Poultry', 'Mixed'];
                foreach ($farmTypes as $ft):
                ?>
                    <option value="<?php echo $ft; ?>" <?php echo selected($farmTypeF, $ft); ?>><?php echo $ft; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="voice_interest" class="form-select form-select-sm">
                <option value="">Voice Interest</option>
                <option value="yes" <?php echo selected($voiceF, 'yes'); ?>>Yes</option>
                <option value="maybe" <?php echo selected($voiceF, 'maybe'); ?>>Maybe</option>
                <option value="no" <?php echo selected($voiceF, 'no'); ?>>No</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="interviewer_id" class="form-select form-select-sm">
                <option value="0">All Interviewers</option>
                <?php foreach ($interviewers as $iv): ?>
                    <option value="<?php echo $iv['id']; ?>" <?php echo selected($interviewerF, (int) $iv['id']); ?>><?php echo htmlspecialchars($iv['full_name']); ?></option>
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
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="col-6 d-md-none">
            <button type="button" class="btn btn-sm btn-outline-info w-100" onclick="document.getElementById('intFilterExtra').classList.toggle('d-none')"><i class="bi bi-funnel"></i> Filters</button>
        </div>
        <div class="col-6 col-md-6 d-flex gap-2">
            <?php if ($search !== '' || $statusFilter !== '' || $districtF !== '' || $municipalityF !== '' || $farmTypeF !== '' || $voiceF !== '' || $datePreset !== '' || $dateFrom !== '' || $dateTo !== '' || $interviewerF > 0 || $myContrib): ?>
                <a href="<?php echo BASE_URL; ?>/interviews.php" class="btn btn-sm btn-outline-danger">Clear Filters</a>
            <?php endif; ?>
            <span class="text-muted align-self-center small">
                <?php echo $totalInterviews; ?> result(s)
                <?php if ($totalPages > 1): ?>
                    &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
</form>

<?php if (count($interviews) > 0): ?>
    <?php if (!$showDeleted && (canDelete() || canApproveInterviews())): ?>
    <form method="post" id="bulkForm" onsubmit="return handleBulkSubmit()">
        <?php echo csrf_field(); ?>
        <div class="d-flex gap-2 mb-2">
            <select name="bulk_action" id="bulkAction" class="form-select form-select-sm" style="max-width:180px;">
                <option value="">Bulk action...</option>
                <?php if (canApproveInterviews()): ?>
                    <option value="approve">Approve selected</option>
                <?php endif; ?>
                <?php if (canDelete()): ?>
                    <option value="delete">Delete selected</option>
                <?php endif; ?>
            </select>
            <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
        </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <?php if (!$showDeleted && (canDelete() || canApproveInterviews())): ?><th style="width:36px;"><input type="checkbox" id="selectAll" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th><?php endif; ?>
                    <th style="width:50px;">#</th>
                    <th>Date</th>
                    <th>Farmer</th>
                    <th>Interviewer</th>
                    <th>District</th>
                    <th class="text-center">GPS</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Voice</th>
                    <th>Follow-Up</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($interviews as $index => $i): ?>
                    <?php $st = $i['interview_status'] ?? 'interviewed'; ?>
                    <?php $canBulk = canDeleteInterview($i) || (canApproveInterviews() && normalizeInterviewStatus($st) === 'submitted'); ?>
                    <tr>
                        <?php if (!$showDeleted && (canDelete() || canApproveInterviews())): ?>
                            <td><?php if ($canBulk): ?><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $i['id']; ?>"><?php endif; ?></td>
                        <?php endif; ?>
                        <td class="text-muted fw-medium"><?php echo $offset + $index + 1; ?></td>
                        <td><?php echo $i['interview_date']; ?></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $i['farmer_id']; ?>"
                               class="text-decoration-none fw-medium">
                                <?php echo htmlspecialchars($i['farmer_name']); ?>
                            </a>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($i['fid']); ?></small>
                        </td>
                        <td><small><?php echo htmlspecialchars($i['interviewer_name']); ?></small></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($i['farmer_district'] ?: '-'); ?></small></td>
                        <td class="text-center">
                            <?php if ($i['latitude'] && $i['longitude']): 
                                $gpsAcc = (float) ($i['gps_accuracy'] ?? 0);
                                if ($gpsAcc > 0 && $gpsAcc <= 10): ?>
                                    <span class="badge bg-success" title="GPS: <?php echo $i['latitude']; ?>, <?php echo $i['longitude']; ?> — Accuracy <?php echo $gpsAcc; ?>m"><i class="bi bi-geo-alt-fill"></i></span>
                                <?php elseif ($gpsAcc > 10 && $gpsAcc <= 50): ?>
                                    <span class="badge bg-warning text-dark" title="GPS: <?php echo $i['latitude']; ?>, <?php echo $i['longitude']; ?> — Accuracy <?php echo $gpsAcc; ?>m"><i class="bi bi-geo-alt-fill"></i></span>
                                <?php elseif ($gpsAcc > 50): ?>
                                    <span class="badge bg-secondary" title="GPS: <?php echo $i['latitude']; ?>, <?php echo $i['longitude']; ?> — Accuracy <?php echo $gpsAcc; ?>m"><i class="bi bi-geo-alt-fill"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-success" title="GPS: <?php echo $i['latitude']; ?>, <?php echo $i['longitude']; ?>"><i class="bi bi-geo-alt-fill"></i></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" style="opacity:0.3"><i class="bi bi-geo-alt"></i></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo $i['duration_minutes'] ? $i['duration_minutes'] . ' min' : '-'; ?></small></td>
                        <td><?php echo interviewStatusBadge($st, isDeleted($i)); ?></td>
                        <td>
                            <?php if ($i['voice_interest'] === 'yes'): ?>
                                <span class="badge bg-success">Yes</span>
                            <?php elseif ($i['voice_interest'] === 'maybe'): ?>
                                <span class="badge bg-warning text-dark">Maybe</span>
                            <?php elseif ($i['voice_interest'] === 'no'): ?>
                                <span class="badge bg-danger">No</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $i['follow_up_required'] ? '<span class="badge bg-info">📅 ' . ($i['follow_up_date'] ?: 'Scheduled') . '</span>' : '-'; ?></td>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $i['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if (canEditInterview($i)): ?>
                                <a href="<?php echo BASE_URL; ?>/interview-edit.php?id=<?php echo $i['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$showDeleted && (canDelete() || canApproveInterviews())): ?></form><?php endif; ?>
    <?php echo renderPagination($page, $totalPages, BASE_URL . '/interviews.php'); ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi bi-chat-dots" style="font-size: 3rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php echo $search ? 'No interviews match your filters.' : 'No interviews recorded yet.'; ?></h5>
            <p class="text-muted"><?php echo $search ? 'Try adjusting your filter criteria.' : 'Start by conducting your first farmer interview.'; ?></p>
            <?php if (!$search): ?>
                <a href="<?php echo BASE_URL; ?>/interview-add.php" class="btn btn-success">Conduct First Interview</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
function handleBulkSubmit() {
    const action = document.getElementById('bulkAction').value;
    if (!action) { alert('Please select a bulk action.'); return false; }
    const checked = document.querySelectorAll('.row-check:checked').length;
    if (checked === 0) { alert('Please select at least one interview.'); return false; }
    if (action === 'delete' && !confirm('Move ' + checked + ' selected interview(s) to recycle bin?')) return false;
    if (action === 'approve' && !confirm('Approve ' + checked + ' selected interview(s)?')) return false;
    return true;
}
</script>

<!-- ═══ Cascading Municipality Filter ═══ -->
<script>
const locationData = <?php echo getLocationDataJson(); ?>;

function updateMunicipalityFilter() {
    const district = document.getElementById('filterDistrict').value;
    const mun = document.getElementById('filterMunicipality');
    const currentVal = mun.value;
    
    // Clear existing options
    mun.innerHTML = '<option value="">All Municipalities</option>';
    
    if (district && locationData[district]) {
        // Add municipalities for the selected district
        Object.keys(locationData[district]).forEach(function(m) {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            if (m === currentVal) {
                opt.selected = true;
            }
            mun.appendChild(opt);
        });
    }
    // If no district selected, just show "All Municipalities"
}

// On page load, set the correct municipality filter for the pre-selected district
// (handled by PHP already, but this ensures consistency on DOM ready)
document.addEventListener('DOMContentLoaded', function() {
    const district = document.getElementById('filterDistrict').value;
    if (district && locationData[district]) {
        updateMunicipalityFilter();
    }
});

// Date preset buttons
document.querySelectorAll('.date-preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var preset = this.getAttribute('data-preset');
        var form = this.closest('form');
        var input = document.getElementById('datePresetInput');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'date_preset';
            input.id = 'datePresetInput';
            form.appendChild(input);
        }
        input.value = preset;
        if (preset === 'custom') {
            document.getElementById('customDateRange').style.display = '';
        } else {
            form.submit();
        }
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
