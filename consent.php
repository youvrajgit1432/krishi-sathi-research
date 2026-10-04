<?php
/**
 * Krishi Sathi Research System - Consent Records List
 * Phase 2C: Evidence & Ethics Infrastructure
 *
 * Lists all consent records with filter by type, status, and search.
 * Links to farmer-view, participant-view, and consent-view.
 */
require_once __DIR__ . '/config.php';
requireLogin();

// ROLE-ACCESS-01.3: Only Research Lead and Editor can view consent records
if (!canViewConsent()) {
    logUnauthorizedAccess($pdo, 'view_consent_list', 'consent', null, 'Contributor attempted to view consent records');
    setFlash('error', 'Access denied. Consent records contain confidential research data and are not accessible to Research Contributors.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

if (!canRecordConsent()) {
    setFlash('error', 'Viewers cannot view consent records.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// Build dynamic WHERE clause (single pattern for both count and data queries)
$whereConditions = [];
$params = [];

if ($search !== '') {
    $whereConditions[] = "(rf.name LIKE ? OR p.name LIKE ? OR rf.farmer_id LIKE ? OR p.participant_id LIKE ? OR cr.irb_reference LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($typeFilter !== '') {
    $whereConditions[] = "cr.consent_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $whereConditions[] = "cr.consent_status = ?";
    $params[] = $statusFilter;
}

$whereClause = count($whereConditions) > 0 ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

// Count total for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*)
    FROM research_consent_records cr
    LEFT JOIN research_farmers rf ON cr.farmer_id = rf.id
    LEFT JOIN research_participants p ON cr.participant_id = p.id
    JOIN research_users ru ON cr.researcher_id = ru.id" . $whereClause);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

// Pagination
[$page, $totalPages, $offset] = paginationData($totalRecords);

// Fetch records
$sql = "SELECT cr.*, 
               rf.name AS farmer_name, rf.farmer_id AS farmer_code,
               p.name AS participant_name, p.participant_id AS participant_code,
               ru.full_name AS researcher_name
        FROM research_consent_records cr
        LEFT JOIN research_farmers rf ON cr.farmer_id = rf.id
        LEFT JOIN research_participants p ON cr.participant_id = p.id
        JOIN research_users ru ON cr.researcher_id = ru.id"
        . $whereClause
        . " ORDER BY cr.created_at DESC"
        . " LIMIT " . PER_PAGE . " OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$pageTitle = 'Consent Records - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-shield-check text-success"></i> Consent Records</h4>
        <p class="text-muted mb-0">Ethics compliance — track informed consent for all participants</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canExport()): ?>
        <a href="<?php echo BASE_URL; ?>/export-consent.php<?php
            $qs = [];
            if ($search !== '') $qs['search'] = $search;
            if ($typeFilter !== '') $qs['type'] = $typeFilter;
            if ($statusFilter !== '') $qs['status'] = $statusFilter;
            echo $qs ? '?' . http_build_query($qs) : '';
        ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/consent-add.php" class="btn btn-success btn-sm">
            <i class="bi bi-plus-circle"></i> Record Consent
        </a>
    </div>
</div>

<!-- Filters -->
<form method="get" class="mb-3">
    <div class="card shadow-sm border-0">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search by name, ID, or IRB reference..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-2">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="farmer" <?php echo $typeFilter === 'farmer' ? 'selected' : ''; ?>>Farmer</option>
                        <option value="stakeholder" <?php echo $typeFilter === 'stakeholder' ? 'selected' : ''; ?>>Stakeholder</option>
                        <option value="photo" <?php echo $typeFilter === 'photo' ? 'selected' : ''; ?>>Photo</option>
                        <option value="interview" <?php echo $typeFilter === 'interview' ? 'selected' : ''; ?>>Interview</option>
                        <option value="audio" <?php echo $typeFilter === 'audio' ? 'selected' : ''; ?>>Audio</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="granted" <?php echo $statusFilter === 'granted' ? 'selected' : ''; ?>>Granted</option>
                        <option value="withdrawn" <?php echo $statusFilter === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                </div>
                <?php if ($search !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
                <div class="col-auto">
                    <a href="<?php echo BASE_URL; ?>/consent.php" class="btn btn-sm btn-outline-danger">Clear</a>
                </div>
                <?php endif; ?>
                <div class="col-auto ms-auto">
                    <span class="small text-muted"><?php echo $totalRecords; ?> record(s)</span>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Records Table -->
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (count($records) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Participant</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>IRB Ref</th>
                        <th>Researcher</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($r['consent_date'])); ?></td>
                            <td>
                                <?php if ($r['farmer_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $r['farmer_id']; ?>">
                                        <?php echo htmlspecialchars($r['farmer_name'] ?? 'Farmer #' . $r['farmer_id']); ?>
                                    </a>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($r['farmer_code'] ?? ''); ?></small>
                                <?php elseif ($r['participant_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $r['participant_id']; ?>">
                                        <?php echo htmlspecialchars($r['participant_name'] ?? 'Stakeholder #' . $r['participant_id']); ?>
                                    </a>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($r['participant_code'] ?? ''); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-info"><?php echo ucfirst($r['consent_type']); ?></span></td>
                            <td><small><?php
                                $methodLabels = ['verbal' => 'Verbal', 'written_digital' => 'Digital', 'written_physical' => 'Physical', 'implied' => 'Implied'];
                                echo $methodLabels[$r['consent_method']] ?? ucfirst($r['consent_method'] ?? '-');
                            ?></small></td>
                            <td><?php echo consentBadge($r); ?></td>
                            <td><small><?php echo htmlspecialchars($r['irb_reference'] ?: '-'); ?></small></td>
                            <td><small><?php echo htmlspecialchars($r['researcher_name']); ?></small></td>
                            <td class="text-end">
                                <a href="<?php echo BASE_URL; ?>/consent-view.php?id=<?php echo $r['id']; ?>"
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="card-body text-center py-4">
                <p class="text-muted mb-2"><i class="bi bi-shield-check fs-3 d-block mb-2"></i> No consent records found.</p>
                <?php if ($search !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
                    <a href="<?php echo BASE_URL; ?>/consent.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>/consent-add.php" class="btn btn-success">Record First Consent</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <?php echo renderPagination($page, $totalPages, BASE_URL . '/consent.php'); ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
