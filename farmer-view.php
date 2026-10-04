<?php
/**
 * Krishi Sathi Research System - View Farmer
 * Phase 1.10: Recycle bin support — shows warning if deleted.
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$farmer = $pdo->prepare("SELECT rf.*, deleter.full_name AS deleted_by_name, approver.full_name AS approved_by_name FROM research_farmers rf LEFT JOIN research_users deleter ON rf.deleted_by = deleter.id LEFT JOIN research_users approver ON rf.approved_by = approver.id WHERE rf.id = ?");
$farmer->execute([$id]);
$farmer = $farmer->fetch();

if (!$farmer) {
    setFlash('error', 'Farmer not found.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

// ROLE-ACCESS-01.3: Contributors cannot view approved farmers
if (!canViewFarmer($farmer)) {
    logUnauthorizedAccess($pdo, 'view_farmer', 'farmer', $id, 'Contributor attempted to view approved/restricted farmer');
    setFlash('error', 'Access denied. Approved research records are part of the protected dataset and are not accessible to Research Contributors.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

$isDeleted = $farmer['deleted_at'] !== null;

// Farm profile
$farmProfile = $pdo->prepare("SELECT * FROM research_farm_profiles WHERE farmer_id = ? ORDER BY created_at DESC LIMIT 1");
$farmProfile->execute([$id]);
$farmProfile = $farmProfile->fetch();

// Interviews for this farmer
$interviews = $pdo->prepare("
    SELECT ri.*, ru.full_name AS interviewer_name
    FROM research_interviews ri
    JOIN research_users ru ON ri.interviewer_id = ru.id
    WHERE ri.farmer_id = ? AND " . activeWhere('ri') . "
    ORDER BY ri.interview_date DESC
");
$interviews->execute([$id]);
$interviews = $interviews->fetchAll();

$pageTitle = 'Farmer: ' . $farmer['name'] . ' - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<?php if ($isDeleted): ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <strong><i class="bi bi-trash"></i> Deleted Farmer</strong>
        — Sent to recycle bin <?php echo date('M j, Y g:i a', strtotime($farmer['deleted_at'])); ?>
        <?php if (!empty($farmer['deleted_by_name'])): ?>
            by <?php echo htmlspecialchars($farmer['deleted_by_name']); ?>
        <?php endif; ?>
    </div>
    <?php if (canEdit()): ?>
        <form method="post" action="<?php echo BASE_URL; ?>/farmers.php" class="d-inline" onsubmit="return confirm('Restore this farmer?')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="single_action" value="restore">
            <input type="hidden" name="record_id" value="<?php echo $id; ?>">
            <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-arrow-counterclockwise"></i> Restore
            </button>
        </form>
    <?php endif; ?>
</div>
<?php elseif (isRecordLocked($farmer)): ?>
<div class="alert alert-success d-flex align-items-center gap-2 py-2">
    <i class="bi bi-shield-check fs-5"></i>
    <div>
        <strong>Approved Farmer</strong> &mdash;
        This farmer's profile has been reviewed and is part of the protected dataset.
        Edits are restricted. Contact a Research Lead if changes are needed.
    </div>
</div>
<?php endif; ?>

<?php if (isRecordLocked($farmer) && !$isDeleted): ?>
    <?php echo renderApprovalSummaryCard($farmer, 'farmer', $farmer['approved_by_name'] ?? ''); ?>
<?php endif; ?>

<?php
// Fetch observations for this farmer (used by timeline and observations section)
$observations = $pdo->prepare("
    SELECT o.*, ru.full_name AS observer_name,
           (SELECT COUNT(*) FROM research_photos WHERE observation_id = o.id) AS photo_count,
           ri.interview_status AS interview_status
    FROM research_observations o
    JOIN research_users ru ON o.observer_id = ru.id
    LEFT JOIN research_interviews ri ON o.interview_id = ri.id
    WHERE o.farmer_id = ? AND " . activeWhere('o') . "
    ORDER BY o.observation_date DESC
");
$observations->execute([$id]);
$observations = $observations->fetchAll();

$farmerConsent = getFarmerConsent($pdo, $id);
echo renderRecordTimeline($farmer, 'farmer', [
    'consent_status' => $farmerConsent['consent_status'] ?? '',
    'media_count' => 0,
    'observation_count' => count($observations),
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <?php if ($isDeleted): ?><span class="text-danger"><i class="bi bi-trash"></i></span> <?php endif; ?>
            <i class="bi bi-person"></i>
            <?php echo htmlspecialchars($farmer['name']); ?>
        </h4>
        <p class="text-muted mb-0">
            ID: <code><?php echo htmlspecialchars($farmer['farmer_id']); ?></code>
            &bull; Added <?php echo date('M j, Y', strtotime($farmer['created_at'])); ?>
            &bull; <?php echo count($interviews); ?> interview(s)
            &bull; <?php echo consentBadge(getFarmerConsent($pdo, $id)); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!isViewer() && canDelete() && !$isDeleted): ?>
            <form method="post" action="<?php echo BASE_URL; ?>/farmers.php" class="d-inline" onsubmit="return confirm('Move <?php echo str_replace("'", "\\'", $farmer['name']); ?> to recycle bin? Their interviews and data will be preserved.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="single_action" value="delete">
                <input type="hidden" name="record_id" value="<?php echo $id; ?>">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </form>
        <?php endif; ?>
        <?php if (!isViewer() && !$isDeleted): ?>
            <?php if (canEditFarmer($farmer)): ?>
            <a href="<?php echo BASE_URL; ?>/farmer-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/consent-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-outline-success">
                <i class="bi bi-shield-check"></i> Consent
            </a>
        <?php endif; ?>
        <?php if (!$isDeleted): ?>
            <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> New Interview
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Farmer Details -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Farmer Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Farmer ID</th>
                            <td><code><?php echo htmlspecialchars($farmer['farmer_id']); ?></code></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Phone</th>
                            <td><?php echo htmlspecialchars($farmer['phone'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">District</th>
                            <td><?php echo htmlspecialchars($farmer['district'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Municipality</th>
                            <td><?php echo htmlspecialchars($farmer['municipality'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Ward</th>
                            <td><?php echo htmlspecialchars($farmer['ward'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Age Group</th>
                            <td><?php echo htmlspecialchars($farmer['age_group'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Gender</th>
                            <td><?php echo htmlspecialchars($farmer['gender'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Education</th>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $farmer['education_level'] ?? '-'))); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Occupation</th>
                            <td><?php echo htmlspecialchars($farmer['primary_occupation'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Land Ownership</th>
                            <td><?php echo htmlspecialchars(ucfirst($farmer['land_ownership_type'] ?: '-')); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Irrigation</th>
                            <td><?php echo htmlspecialchars(ucfirst($farmer['irrigation_access'] ?: '-')); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Language</th>
                            <td><?php echo htmlspecialchars($farmer['preferred_language'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Coop Member</th>
                            <td><?php echo $farmer['cooperative_membership'] === 'yes' ? '✅ Yes' : ($farmer['cooperative_membership'] === 'no' ? '❌ No' : '-'); ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Tole</th>
                            <td><?php echo htmlspecialchars($farmer['tole'] ?: '-'); ?></td>
                        </tr>
                        <?php if ($farmer['latitude'] && $farmer['longitude']): ?>
                        <tr>
                            <th class="text-muted">GPS</th>
                            <td>
                                <code><?php echo $farmer['latitude']; ?>, <?php echo $farmer['longitude']; ?></code>
                                <?php if ($farmer['gps_altitude']): ?>
                                    <br><small class="text-muted">GPS Altitude: <?php echo $farmer['gps_altitude']; ?>m</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($farmer['altitude']): ?>
                        <tr>
                            <th class="text-muted">Altitude</th>
                            <td><?php echo htmlspecialchars($farmer['altitude']); ?>m <small class="text-muted">(manual)</small></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Farm Profile -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Farm Profile</div>
            <div class="card-body">
                <?php if ($farmProfile): ?>
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            <tr>
                                <th class="text-muted" style="width: 140px;">Farm Type</th>
                                <td><?php echo htmlspecialchars($farmProfile['farm_type'] ?: '-'); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Farm Size</th>
                                <td><?php echo htmlspecialchars($farmProfile['farm_size'] ?: '-'); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Land Unit</th>
                                <td><?php echo htmlspecialchars($farmProfile['land_unit'] ?: '-'); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Years Farming</th>
                                <td><?php echo htmlspecialchars($farmProfile['years_farming'] ?: '-'); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Commercial</th>
                                <td><?php echo $farmProfile['is_commercial'] ? '✅ Yes' : '❌ No'; ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else:
                    // Check if we can source farm data from the latest interview
                    $interviewFarmStmt = $pdo->prepare("
                        SELECT fp.farm_type, fp.farm_size, fp.years_farming, fp.is_commercial,
                               ri.interview_date, ri.id AS interview_id
                        FROM research_interviews ri
                        LEFT JOIN research_farm_profiles fp ON fp.farmer_id = ri.farmer_id
                        WHERE ri.farmer_id = ? AND ri.farm_size IS NOT NULL AND " . activeWhere('ri') . "
                        ORDER BY ri.interview_date DESC
                        LIMIT 1
                    ");
                    $interviewFarmStmt->execute([$id]);
                    $interviewFarm = $interviewFarmStmt->fetch();
                    
                    // Also check if interview has inline farm data (farm_size stored in interview)
                    if (!$interviewFarm || (!$interviewFarm['farm_type'] && !$interviewFarm['farm_size'])) {
                        $inlineFarmStmt = $pdo->prepare("
                            SELECT farm_type, farm_size, years_farming, is_commercial,
                                   interview_date, id AS interview_id
                            FROM research_interviews
                            WHERE farmer_id = ? AND farm_size IS NOT NULL AND farm_size != '' AND " . activeWhere() . "
                            ORDER BY interview_date DESC
                            LIMIT 1
                        ");
                        $inlineFarmStmt->execute([$id]);
                        $interviewFarm = $inlineFarmStmt->fetch();
                    }
                    
                    if ($interviewFarm): ?>
                        <div class="alert alert-info py-2 mb-2">
                            <small><i class="bi bi-info-circle"></i> From interview on <?php echo $interviewFarm['interview_date']; ?></small>
                        </div>
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 140px;">Farm Type</th>
                                    <td><?php echo htmlspecialchars($interviewFarm['farm_type'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Farm Size</th>
                                    <td><?php echo htmlspecialchars($interviewFarm['farm_size'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Years Farming</th>
                                    <td><?php echo htmlspecialchars($interviewFarm['years_farming'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Commercial</th>
                                    <td><?php echo $interviewFarm['is_commercial'] ? '✅ Yes' : '❌ No'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo (int) $interviewFarm['interview_id']; ?>" class="btn btn-sm btn-outline-primary mt-2">
                            <i class="bi bi-eye"></i> View Interview
                        </a>
                    <?php else: ?>
                        <p class="text-muted mb-0">No farm profile recorded yet.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Interviews -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-chat-dots"></i> Interviews</span>
        <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-sm btn-success">
            <i class="bi bi-plus-circle"></i> New Interview
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (count($interviews) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Interviewer</th>
                            <th>Location</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Voice Interest</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interviews as $i): ?>
                            <tr>
                                <td><?php echo $i['interview_date']; ?></td>
                                <td><?php echo htmlspecialchars($i['interviewer_name']); ?></td>
                                <td><?php echo htmlspecialchars($i['location'] ?: '-'); ?></td>
                                <td><?php echo $i['duration_minutes'] ? $i['duration_minutes'] . ' min' : '-'; ?></td>
                                <td><?php echo interviewStatusBadge($i['interview_status'] ?? 'draft', isDeleted($i)); ?></td>
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
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $i['id']; ?>"
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
                <p class="text-muted mb-2">No interviews conducted with this farmer yet.</p>
                <a href="<?php echo BASE_URL; ?>/interview-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Conduct First Interview
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Observations -->
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-binoculars"></i> Field Observations</span>
        <a href="<?php echo BASE_URL; ?>/observation-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-sm btn-success">
            <i class="bi bi-plus-circle"></i> New Observation
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (count($observations) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Observer</th>
                            <th>Farm Condition</th>
                            <th>Smartphone</th>
                            <th>Records</th>
                            <th class="text-center">Photos</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($observations as $ob): ?>
                            <tr>
                                <td><?php echo $ob['observation_date']; ?></td>
                                <td><?php echo htmlspecialchars($ob['observer_name']); ?></td>
                                <td><?php echo $ob['farm_condition'] ? ucfirst($ob['farm_condition']) : '-'; ?></td>
                                <td class="small"><?php echo $ob['observed_smartphone_usage'] ? '✓' : '-'; ?></td>
                                <td class="small"><?php echo $ob['observed_record_books'] ? '✓' : '-'; ?></td>
                                <td class="text-center">
                                    <?php if ($ob['photo_count'] > 0): ?>
                                        <span class="badge bg-info"><i class="bi bi-camera"></i> <?php echo $ob['photo_count']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/observation-view.php?id=<?php echo $ob['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                    <?php if (canEditObservation($ob)): ?>
                                        <a href="<?php echo BASE_URL; ?>/observation-edit.php?id=<?php echo $ob['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card-body text-center py-4">
                <p class="text-muted mb-2">No field observations recorded for this farmer yet.</p>
                <a href="<?php echo BASE_URL; ?>/observation-add.php?farmer_id=<?php echo $id; ?>" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Record Observation
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
