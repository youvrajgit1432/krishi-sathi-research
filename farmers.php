<?php
/**
 * Krishi Sathi Research System - Farmers List
 * Phase 1.10: Recycle bin support — soft delete & restore.
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'delete') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/farmers.php');
        exit;
    }
    if (!canDelete()) {
        setFlash('error', 'You do not have permission to delete farmers.');
    } else {
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        try {
            foreach ($ids as $deleteId) {
                softDeleteRecord($pdo, 'research_farmers', $deleteId);
                logAudit($pdo, 'farmer_deleted', 'farmer', $deleteId, 'Deleted (soft)');
            }
            setFlash('success', count($ids) . ' farmer(s) moved to recycle bin.');
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

// ─── Handle Single Delete (POST) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'delete') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/farmers.php');
        exit;
    }
    $deleteId = (int) ($_POST['record_id'] ?? 0);
    if (!canDelete()) {
        setFlash('error', 'You do not have permission to delete farmers.');
    } else {
        try {
            softDeleteRecord($pdo, 'research_farmers', $deleteId);
            logAudit($pdo, 'farmer_deleted', 'farmer', $deleteId, 'Deleted (soft)');
            setFlash('success', 'Farmer moved to recycle bin.');
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

// ─── Handle Restore (POST) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'restore') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/farmers.php');
        exit;
    }
    $restoreId = (int) ($_POST['record_id'] ?? 0);
    if (!canManageRecycleBin()) {
        setFlash('error', 'Research Lead role required to restore farmers.');
    } else {
        try {
            restoreRecord($pdo, 'research_farmers', $restoreId);
            logAudit($pdo, 'farmer_restored', 'farmer', $restoreId, 'Restored from recycle bin');
            setFlash('success', 'Farmer restored from recycle bin.');
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/farmers.php' . (isset($_POST['trash']) && $_POST['trash'] === '1' ? '?trash=1' : ''));
    exit;
}

// ─── Determine View ────────────────────────────────────────────
$showTrash = isset($_GET['trash']) && $_GET['trash'] === '1';
if ($showTrash && !canManageRecycleBin()) {
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

// Filters
$search     = trim($_GET['search'] ?? '');
$districtF  = trim($_GET['district'] ?? '');
$genderF    = trim($_GET['gender'] ?? '');
[$datePreset, $dateFrom, $dateTo] = resolveDateFilter();

// ROLE-ACCESS-01.3: Contributors can only see their own non-approved farmers
if ($showTrash) {
    $sql = "SELECT rf.*, deleter.full_name AS deleted_by_name,
                   (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = rf.id) AS interview_count
            FROM research_farmers rf
            LEFT JOIN research_users deleter ON rf.deleted_by = deleter.id
            WHERE " . deletedWhere('rf');
} else {
    $sql = "SELECT rf.*,
                   (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = rf.id) AS interview_count
            FROM research_farmers rf
            WHERE " . activeWhere('rf');
    // Contributors can see non-approved farmers or farmers with no interviews
    if (isContributor()) {
        $sql .= " AND ((rf.approval_status IS NULL OR rf.approval_status != 'approved')"
            . " OR (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = rf.id) = 0)";
    }
}
$params = [];

if ($search !== '') {
    $sql .= " AND (rf.name LIKE ? OR rf.phone LIKE ? OR rf.farmer_id LIKE ? OR rf.district LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like, $like];
}
if ($districtF !== '') {
    $sql .= " AND rf.district = ?";
    $params[] = $districtF;
}
if ($genderF !== '') {
    $sql .= " AND rf.gender = ?";
    $params[] = $genderF;
}
if ($dateFrom !== '') {
    $sql .= " AND rf.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= " AND rf.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$sql .= " GROUP BY rf.id";

// Count total for pagination
$countWhere = $showTrash ? deletedWhere('rf') : activeWhere('rf');
if (!$showTrash && isContributor()) {
    $countWhere .= " AND ((rf.approval_status IS NULL OR rf.approval_status != 'approved')"
        . " OR (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = rf.id) = 0)";
}
$countSql = "SELECT COUNT(*) FROM research_farmers rf" . 
    ($showTrash ? " LEFT JOIN research_users deleter ON rf.deleted_by = deleter.id WHERE " . $countWhere : " WHERE " . $countWhere);
$countParams = [];
if ($search !== '') {
    $countSql .= " AND (rf.name LIKE ? OR rf.phone LIKE ? OR rf.farmer_id LIKE ? OR rf.district LIKE ?)";
    $like = "%$search%";
    $countParams = [$like, $like, $like, $like];
}
if ($districtF !== '') {
    $countSql .= " AND rf.district = ?";
    $countParams[] = $districtF;
}
if ($genderF !== '') {
    $countSql .= " AND rf.gender = ?";
    $countParams[] = $genderF;
}
if ($dateFrom !== '') {
    $countSql .= " AND rf.created_at >= ?";
    $countParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $countSql .= " AND rf.created_at <= ?";
    $countParams[] = $dateTo . ' 23:59:59';
}
$countSql .= " GROUP BY rf.id";
// Wrap in subquery for accurate count with GROUP BY
$countSql = "SELECT COUNT(*) FROM ($countSql) AS cnt_sub";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$totalFarmers = (int) $countStmt->fetchColumn();

// Get unique districts for filter
$districtsStmt = $pdo->prepare("SELECT DISTINCT district FROM research_farmers WHERE district IS NOT NULL AND district != '' AND " . activeWhere() . " ORDER BY district");
$districtsStmt->execute();
$districts = $districtsStmt->fetchAll(PDO::FETCH_COLUMN);

$hasActiveFilters = $districtF !== '' || $genderF !== '' || $dateFrom !== '' || $dateTo !== '';


// Pagination
[$page, $totalPages, $offset] = paginationData($totalFarmers);

$sql .= " ORDER BY rf.created_at DESC LIMIT " . PER_PAGE . " OFFSET " . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$farmers = $stmt->fetchAll();

$pageTitle = $showTrash ? 'Recycle Bin - Krishi Sathi Research' : 'Farmers - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">
        <?php if ($showTrash): ?>
            <i class="bi bi-trash"></i> Recycle Bin
        <?php else: ?>
            <i class="bi bi-people"></i> Farmers
        <?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if (canManageRecycleBin()): ?>
            <a href="<?php echo BASE_URL; ?>/recycle-bin.php?type=farmers"
               class="btn btn-sm <?php echo $showTrash ? 'btn-outline-secondary' : 'btn-outline-warning'; ?>">
                <i class="bi bi-trash"></i>
                Recycle Bin
            </a>
        <?php endif; ?>
        <?php if (!$showTrash): ?>
            <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="btn btn-success">
                <i class="bi bi-person-plus"></i> Add Farmer
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<form method="get" class="mb-3">
    <?php if ($showTrash): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
    <div class="row g-2">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name, phone, ID, or district..."
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-2">
            <select name="district" class="form-select form-select-sm">
                <option value="">All Districts</option>
                <?php foreach ($districts as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $districtF === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="gender" class="form-select form-select-sm">
                <option value="">All Genders</option>
                <option value="Male" <?php echo $genderF === 'Male' ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo $genderF === 'Female' ? 'selected' : ''; ?>>Female</option>
                <option value="Other" <?php echo $genderF === 'Other' ? 'selected' : ''; ?>>Other</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <?php if ($search !== '' || $hasActiveFilters): ?>
                <a href="<?php echo BASE_URL; ?>/farmers.php<?php echo $showTrash ? '?trash=1' : ''; ?>" class="btn btn-sm btn-outline-danger">Clear</a>
            <?php endif; ?>
            <?php if (!$showTrash && canExport()): ?>
                <a href="<?php echo BASE_URL; ?>/export-farmers.php?<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>" class="btn btn-sm btn-outline-success" title="Export CSV">
                    <i class="bi bi-download"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <input type="hidden" name="date_preset" id="datePresetInput" value="<?php echo htmlspecialchars($datePreset); ?>">
    <div class="row g-2 mt-1">
        <div class="col-12 col-md-auto">
            <?php echo renderDatePresets($datePreset); ?>
        </div>
    </div>
    <div class="row g-2 mt-1" id="customDateRange" style="<?php echo $datePreset === 'custom' ? '' : 'display:none;'; ?>">
        <div class="col-md-2">
            <input type="date" name="date_from" class="form-control form-control-sm" placeholder="From date"
                   value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_to" class="form-control form-control-sm" placeholder="To date"
                   value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="col-md-6">
            <small class="text-muted">
                <?php echo $totalFarmers; ?> farmer(s)
                <?php if ($totalPages > 1): ?>
                    &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
</form>

<?php if (count($farmers) > 0): ?>
    <?php if (!$showTrash && canDelete()): ?>
    <form method="post" onsubmit="return confirm('Move selected farmers to recycle bin?');">
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
                    <?php if (!$showTrash && canDelete()): ?>
                        <th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th>
                    <?php endif; ?>
                    <th style="width:50px;">#</th>
                    <th>Farmer ID</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>District</th>
                    <th>Municipality</th>
                    <th class="text-center">GPS</th>
                    <th class="text-center">Interviews</th>
                    <?php if ($showTrash): ?>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                    <?php endif; ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($farmers as $index => $f): ?>
                    <tr class="<?php echo $showTrash ? 'table-danger opacity-75' : ''; ?>">
                        <?php if (!$showTrash && canDelete()): ?>
                            <td><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $f['id']; ?>"></td>
                        <?php endif; ?>
                        <td class="text-muted fw-medium"><?php echo $offset + $index + 1; ?></td>
                        <td><code><?php echo htmlspecialchars($f['farmer_id']); ?></code></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $f['id']; ?>" class="text-decoration-none fw-medium">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($f['phone'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($f['district'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($f['municipality'] ?: '-'); ?></td>
                        <td class="text-center">
                            <?php if ($f['latitude'] && $f['longitude']): ?>
                                <span class="badge bg-success" title="GPS: <?php echo $f['latitude']; ?>, <?php echo $f['longitude']; ?>">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" style="opacity:0.3"><i class="bi bi-geo-alt"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary rounded-pill"><?php echo $f['interview_count']; ?></span>
                        </td>
                        <?php if ($showTrash): ?>
                            <td class="small"><?php echo htmlspecialchars($f['deleted_by_name'] ?: 'Unknown'); ?></td>
                            <td class="small"><?php echo date('M j, Y g:i a', strtotime($f['deleted_at'])); ?></td>
                        <?php endif; ?>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $f['id']; ?>"
                               class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (!$showTrash && !isViewer()): ?>
                                <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $f['id']; ?>"
                                   class="btn btn-sm btn-outline-success" title="Add Interview">
                                    <i class="bi bi-plus-lg"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($showTrash): ?>
                                <?php if (canManageRecycleBin()): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Restore <?php echo str_replace("'", "\\'", $f['name']); ?>?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="single_action" value="restore">
                                        <input type="hidden" name="record_id" value="<?php echo (int) $f['id']; ?>">
                                        <input type="hidden" name="trash" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (canEditFarmer($f)): ?>
                                    <a href="<?php echo BASE_URL; ?>/farmer-edit.php?id=<?php echo $f['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (canDelete()): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Move <?php echo str_replace("'", "\\'", $f['name']); ?> to recycle bin? Their interviews and data will be preserved.')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="single_action" value="delete">
                                        <input type="hidden" name="record_id" value="<?php echo (int) $f['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$showTrash && canDelete()): ?></form><?php endif; ?>
    <?php if ($totalFarmers > 0): ?>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <small class="text-muted">
                <?php echo $totalFarmers; ?> farmer(s)
                <?php if ($totalPages > 1): ?>
                    &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                <?php endif; ?>
            </small>
            <?php echo renderPagination($page, $totalPages, BASE_URL . '/farmers.php' . ($showTrash ? '?trash=1' : '')); ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi <?php echo $showTrash ? 'bi-trash' : 'bi-people'; ?>" style="font-size: 3rem; color: #ccc;"></i>
            <h5 class="mt-3">
                <?php if ($showTrash): ?>
                    Recycle bin is empty.
                <?php else: ?>
                    <?php echo $search ? 'No farmers match your search.' : 'No farmers registered yet.'; ?>
                <?php endif; ?>
            </h5>
            <p class="text-muted">
                <?php if ($showTrash): ?>
                    Deleted farmers will appear here.
                <?php elseif ($search): ?>
                    Try a different search term.
                <?php else: ?>
                    Start by adding your first farmer.
                <?php endif; ?>
            </p>
            <?php if (!$search && !$showTrash): ?>
                <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="btn btn-success">Add First Farmer</a>
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
