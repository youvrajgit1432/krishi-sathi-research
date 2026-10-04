<?php
/**
 * Krishi Sathi Research System - Lead-only recycle bin.
 * Phase 6: Added participant tabs, pagination + search.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!canManageRecycleBin()) {
    setFlash('error', 'Research Lead role required for recycle bin management.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();
$types = [
    'farmers'              => ['table' => 'research_farmers', 'label' => 'Farmers', 'name' => 'name', 'audit_type' => 'farmer'],
    'interviews'           => ['table' => 'research_interviews', 'label' => 'Interviews', 'name' => 'id', 'audit_type' => 'interview'],
    'observations'         => ['table' => 'research_observations', 'label' => 'Observations', 'name' => 'id', 'audit_type' => 'observation'],
    'participants'         => ['table' => 'research_participants', 'label' => 'Stakeholders', 'name' => 'name', 'audit_type' => 'participant'],
    'participant_interviews' => ['table' => 'research_participant_interviews', 'label' => 'Stakeholder Intv.', 'name' => 'id', 'audit_type' => 'participant_interview'],
    'users'                => ['table' => 'research_users', 'label' => 'Users', 'name' => 'full_name', 'audit_type' => 'user'],
];
$type = $_GET['type'] ?? 'farmers';
if (!isset($types[$type])) $type = 'farmers';
$meta = $types[$type];

// ─── Search ──────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ─── Count total deleted records (for pagination) ────────────────
$countSql = '';
$countParams = [];
if ($type === 'farmers') {
    $countSql = "SELECT COUNT(*) FROM research_farmers rf WHERE " . deletedWhere('rf');
} elseif ($type === 'interviews') {
    $countSql = "SELECT COUNT(*) FROM research_interviews ri JOIN research_farmers rf ON ri.farmer_id = rf.id WHERE " . deletedWhere('ri');
} elseif ($type === 'observations') {
    $countSql = "SELECT COUNT(*) FROM research_observations o LEFT JOIN research_farmers rf ON o.farmer_id = rf.id LEFT JOIN research_participants rp ON o.participant_id = rp.id WHERE " . deletedWhere('o');
} elseif ($type === 'participants') {
    $countSql = "SELECT COUNT(*) FROM research_participants p WHERE " . deletedWhere('p');
} elseif ($type === 'participant_interviews') {
    $countSql = "SELECT COUNT(*) FROM research_participant_interviews pi JOIN research_participants p ON pi.participant_id = p.id WHERE " . deletedWhere('pi');
} else {
    $countSql = "SELECT COUNT(*) FROM research_users ru WHERE " . deletedWhere('ru');
}
if ($search !== '') {
    $like = "%$search%";
    if ($type === 'farmers') {
        $countSql .= " AND (rf.name LIKE ? OR rf.farmer_id LIKE ?)";
        $countParams = [$like, $like];
    } elseif ($type === 'interviews') {
        $countSql .= " AND (rf.name LIKE ? OR CONCAT('Interview #', ri.id) LIKE ?)";
        $countParams = [$like, $like];
    } elseif ($type === 'observations') {
        $countSql .= " AND rf.name LIKE ?";
        $countParams = [$like];
    } elseif ($type === 'participants') {
        $countSql .= " AND (p.name LIKE ? OR p.participant_id LIKE ?)";
        $countParams = [$like, $like];
    } elseif ($type === 'participant_interviews') {
        $countSql .= " AND (p.name LIKE ? OR CONCAT('PI #', pi.id) LIKE ?)";
        $countParams = [$like, $like];
    } else {
        $countSql .= " AND (ru.full_name LIKE ? OR ru.username LIKE ?)";
        $countParams = [$like, $like];
    }
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$totalRecords = (int) $countStmt->fetchColumn();

// ─── Pagination ──────────────────────────────────────────────────
[$page, $totalPages, $offset] = paginationData($totalRecords);

function selectedTab(string $current, string $type): string {
    return $current === $type ? 'active' : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/recycle-bin.php?type=' . urlencode($type) . ($search !== '' ? '&search=' . urlencode($search) : ''));
        exit;
    }

    $action = $_POST['bulk_action'] ?? '';
    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    if (empty($ids)) {
        setFlash('error', 'Select at least one record.');
    } elseif (!in_array($action, ['restore', 'permanent_delete'], true)) {
        setFlash('error', 'Choose a valid bulk action.');
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $auditType = $meta['audit_type'] ?? $type;
            if ($action === 'restore') {
                $stmt = $pdo->prepare("UPDATE {$meta['table']} SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                foreach ($ids as $rid) {
                    logAudit($pdo, $auditType . '_restored', $auditType, $rid, 'Restored from recycle bin');
                }
                setFlash('success', count($ids) . ' record(s) restored.');
            } else {
                if ($type === 'users') {
                    $leadCheck = $pdo->prepare("SELECT COUNT(*) FROM research_users WHERE id IN ($placeholders) AND role = 'lead'");
                    $leadCheck->execute($ids);
                    if ((int) $leadCheck->fetchColumn() > 0) {
                        throw new RuntimeException('Research Lead users cannot be permanently deleted.');
                    }
                }
                $stmt = $pdo->prepare("DELETE FROM {$meta['table']} WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                foreach ($ids as $rid) {
                    logAudit($pdo, $auditType . '_deleted', $auditType, $rid, 'Permanent delete from recycle bin');
                }
                setFlash('success', count($ids) . ' record(s) permanently deleted.');
            }
        } catch (Throwable $e) {
            setFlash('error', 'Recycle bin action failed: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/recycle-bin.php?type=' . urlencode($type) . ($search !== '' ? '&search=' . urlencode($search) : ''));
    exit;
}

// ─── Build Query ─────────────────────────────────────────────────
$sql = '';
$params = [];
if ($type === 'farmers') {
    $sql = "SELECT rf.id, rf.name AS title, rf.farmer_id AS subtitle, rf.deleted_at, u.full_name AS deleted_by_name
            FROM research_farmers rf LEFT JOIN research_users u ON rf.deleted_by = u.id
            WHERE " . deletedWhere('rf');
} elseif ($type === 'interviews') {
    $sql = "SELECT ri.id, CONCAT('Interview #', ri.id, ' - ', rf.name) AS title, ri.interview_status AS subtitle,
                   ri.deleted_at, u.full_name AS deleted_by_name
            FROM research_interviews ri
            JOIN research_farmers rf ON ri.farmer_id = rf.id
            LEFT JOIN research_users u ON ri.deleted_by = u.id
            WHERE " . deletedWhere('ri');
} elseif ($type === 'observations') {
    $sql = "SELECT o.id, CONCAT('Observation #', o.id, ' - ', COALESCE(rf.name, rp.name)) AS title, o.observation_date AS subtitle,
                   o.deleted_at, u.full_name AS deleted_by_name
            FROM research_observations o
            LEFT JOIN research_farmers rf ON o.farmer_id = rf.id
            LEFT JOIN research_participants rp ON o.participant_id = rp.id
            LEFT JOIN research_users u ON o.deleted_by = u.id
            WHERE " . deletedWhere('o');
} elseif ($type === 'participants') {
    $sql = "SELECT p.id, p.name AS title, p.participant_id AS subtitle, p.deleted_at, u.full_name AS deleted_by_name
            FROM research_participants p LEFT JOIN research_users u ON p.deleted_by = u.id
            WHERE " . deletedWhere('p');
} elseif ($type === 'participant_interviews') {
    $sql = "SELECT pi.id, CONCAT('PI #', pi.id, ' - ', p.name) AS title, pi.interview_status AS subtitle,
                   pi.deleted_at, u.full_name AS deleted_by_name
            FROM research_participant_interviews pi
            JOIN research_participants p ON pi.participant_id = p.id
            LEFT JOIN research_users u ON pi.deleted_by = u.id
            WHERE " . deletedWhere('pi');
} else {
    $sql = "SELECT ru.id, ru.full_name AS title, ru.username AS subtitle, ru.deleted_at, u.full_name AS deleted_by_name
            FROM research_users ru LEFT JOIN research_users u ON ru.deleted_by = u.id
            WHERE " . deletedWhere('ru');
}

if ($search !== '') {
    $like = "%$search%";
    if ($type === 'farmers') {
        $sql .= " AND (rf.name LIKE ? OR rf.farmer_id LIKE ?)";
        $params = [$like, $like];
    } elseif ($type === 'interviews') {
        $sql .= " AND (rf.name LIKE ? OR CONCAT('Interview #', ri.id) LIKE ?)";
        $params = [$like, $like];
    } elseif ($type === 'observations') {
        $sql .= " AND rf.name LIKE ?";
        $params = [$like];
    } elseif ($type === 'participants') {
        $sql .= " AND (p.name LIKE ? OR p.participant_id LIKE ?)";
        $params = [$like, $like];
    } elseif ($type === 'participant_interviews') {
        $sql .= " AND (p.name LIKE ? OR CONCAT('PI #', pi.id) LIKE ?)";
        $params = [$like, $like];
    } else {
        $sql .= " AND (ru.full_name LIKE ? OR ru.username LIKE ?)";
        $params = [$like, $like];
    }
}

$sql .= " ORDER BY deleted_at DESC LIMIT " . PER_PAGE . " OFFSET " . $offset;
$recordsStmt = $pdo->prepare($sql);
$recordsStmt->execute($params);
$records = $recordsStmt->fetchAll();

$pageTitle = 'Recycle Bin - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-trash"></i> Recycle Bin</h4>
    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
</div>

<ul class="nav nav-pills mb-3">
    <?php foreach ($types as $key => $m): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo selectedTab($type, $key); ?>" href="<?php echo BASE_URL; ?>/recycle-bin.php?type=<?php echo $key; ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
                <?php echo htmlspecialchars($m['label']); ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<!-- Search -->
<form method="get" class="mb-2">
    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
    <div class="input-group input-group-sm" style="max-width:400px;">
        <input type="text" name="search" class="form-control" placeholder="Search by name or ID..." value="<?php echo htmlspecialchars($search); ?>">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        <?php if ($search !== ''): ?>
            <a href="<?php echo BASE_URL; ?>/recycle-bin.php?type=<?php echo urlencode($type); ?>" class="btn btn-outline-danger"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
    </div>
</form>

<?php if (count($records) > 0): ?>
<form method="post" onsubmit="return confirm('Apply this recycle bin action to selected records?');">
    <?php echo csrf_field(); ?>
    <div class="d-flex gap-2 mb-2">
        <select name="bulk_action" class="form-select form-select-sm" style="max-width:220px;" required>
            <option value="">Bulk action...</option>
            <option value="restore">Restore selected</option>
            <option value="permanent_delete">Permanently delete selected</option>
        </select>
        <button class="btn btn-sm btn-primary" type="submit">Apply</button>
        <span class="small text-muted align-self-center ms-2"><?php echo $totalRecords; ?> record(s) &mdash; Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th>
                    <th>Record</th>
                    <th>Details</th>
                    <th>Deleted By</th>
                    <th>Deleted At</th>
                    <th class="text-end">Single Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"></td>
                    <td class="fw-medium"><?php echo htmlspecialchars($r['title']); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars($r['subtitle'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($r['deleted_by_name'] ?: 'Unknown'); ?></td>
                    <td><?php echo $r['deleted_at'] ? date('M j, Y g:i a', strtotime($r['deleted_at'])) : '-'; ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-success" name="bulk_action" value="restore" onclick="this.form.querySelectorAll('.row-check').forEach(cb=>cb.checked=false); this.closest('tr').querySelector('.row-check').checked=true;">Restore</button>
                        <button class="btn btn-sm btn-outline-danger" name="bulk_action" value="permanent_delete" onclick="this.form.querySelectorAll('.row-check').forEach(cb=>cb.checked=false); this.closest('tr').querySelector('.row-check').checked=true;">Permanent Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-center">
        <?php echo renderPagination($page, $totalPages, BASE_URL . '/recycle-bin.php?type=' . urlencode($type) . ($search !== '' ? '&search=' . urlencode($search) : '')); ?>
    </div>
<?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5 text-muted">
            <?php if ($search !== ''): ?>
                No deleted <?php echo strtolower($meta['label']); ?> matching "<strong><?php echo htmlspecialchars($search); ?></strong>".
            <?php else: ?>
                No deleted <?php echo strtolower($meta['label']); ?>.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
