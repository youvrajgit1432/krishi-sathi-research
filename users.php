<?php
/**
 * Krishi Sathi Research System - Manage Users
 * Only accessible by Research Lead role.
 */
require_once __DIR__ . '/config.php';
requireLogin();

// Only lead researchers can manage users
if (!isLead()) {
    setFlash('error', 'Access denied. Research Lead role required.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'delete') {
    // Verify CSRF token
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }
    $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
    try {
        $deleted = 0;
        foreach ($ids as $deleteId) {
            $stmt = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
            $stmt->execute([$deleteId]);
            $user = $stmt->fetch();
            if ($user && canDeleteUserRecord($user)) {
                softDeleteRecord($pdo, 'research_users', $deleteId);
                $deleted++;
            }
        }
        setFlash($deleted > 0 ? 'success' : 'error', $deleted > 0 ? "$deleted user(s) moved to recycle bin." : 'No selected users could be deleted.');
    } catch (PDOException $e) {
        setFlash('error', 'Could not delete users: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['single_action'] ?? '') === 'delete') {
    // Verify CSRF token
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }
    $deleteId = (int) ($_POST['record_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM research_users WHERE id = ?");
    $stmt->execute([$deleteId]);
    $targetUser = $stmt->fetch();
    if (!$targetUser || !canDeleteUserRecord($targetUser)) {
        setFlash('error', 'You cannot delete this user.');
    } else {
        try {
            softDeleteRecord($pdo, 'research_users', $deleteId);
            setFlash('success', 'User moved to recycle bin. Their research history remains assigned to them.');
        } catch (PDOException $e) {
            setFlash('error', 'Could not delete user: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/users.php');
    exit;
}

$usersStmt = $pdo->prepare("
    SELECT u.*,
           creator.full_name AS created_by_name,
           (SELECT COUNT(*) FROM research_interviews WHERE interviewer_id = u.id AND " . activeWhere() . ") AS interview_count,
           (SELECT COUNT(*) FROM research_farmers WHERE created_by = u.id AND " . activeWhere() . ") AS farmer_count
    FROM research_users u
    LEFT JOIN research_users creator ON u.created_by = creator.id
    WHERE " . activeWhere('u') . "
    ORDER BY u.role ASC, u.full_name ASC
");
$usersStmt->execute();
$users = $usersStmt->fetchAll();

$pageTitle = 'Manage Users - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people-fill"></i> Research Team</h4>
    <a href="<?php echo BASE_URL; ?>/user-add.php" class="btn btn-success">
        <i class="bi bi-person-plus"></i> Add Researcher
    </a>
</div>

<form method="post" onsubmit="return confirm('Move selected users to recycle bin?');">
    <?php echo csrf_field(); ?>
<div class="d-flex gap-2 mb-2">
    <select name="bulk_action" class="form-select form-select-sm" style="max-width:180px;">
        <option value="">Bulk action...</option>
        <option value="delete">Delete selected</option>
    </select>
    <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
</div>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (count($users) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(cb=>cb.checked=this.checked)"></th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Force Change</th>
                            <th class="text-center">Interviews</th>
                            <th class="text-center">Farmers</th>
                            <th>Created</th>
                            <th>Last Login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php $userStatus = $u['status'] ?? 'active'; ?>
                            <tr class="<?php echo $userStatus === 'inactive' ? 'table-danger opacity-75' : ''; ?>">
                                <td>
                                    <?php if (canDeleteUserRecord($u)): ?>
                                        <input class="row-check" type="checkbox" name="ids[]" value="<?php echo (int) $u['id']; ?>">
                                    <?php endif; ?>
                                </td>
                                <td class="fw-medium">
                                    <?php echo htmlspecialchars($u['full_name']); ?>
                                    <?php if ((int) $u['id'] === currentUserId()): ?>
                                        <span class="badge bg-info ms-1">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                                <td>
                                    <?php
                                    $roleLabels = ['lead'=>'Research Lead','editor'=>'Research Editor','contributor'=>'Research Contributor','viewer'=>'Research Viewer'];
                                    $roleBadges = ['lead'=>'warning text-dark','editor'=>'info','contributor'=>'primary','viewer'=>'secondary'];
                                    $r = $u['role'];
                                    ?>
                                    <span class="badge bg-<?php echo $roleBadges[$r] ?? 'secondary'; ?>">
                                        <?php echo $roleLabels[$r] ?? ucfirst($r); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($userStatus === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($u['force_password_change'])): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation"></i> Required</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-check"></i> OK</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo (int) $u['interview_count']; ?></td>
                                <td class="text-center"><?php echo (int) $u['farmer_count']; ?></td>
                                <td class="text-muted small">
                                    <?php echo date('M j, Y', strtotime($u['created_at'])); ?>
                                    <?php if (!empty($u['created_by_name'])): ?>
                                        <br><small>by <?php echo htmlspecialchars($u['created_by_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?php echo $u['last_login'] ? date('M j, Y g:i a', strtotime($u['last_login'])) : 'Never'; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/user-edit.php?id=<?php echo $u['id']; ?>"
                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (canDeleteUserRecord($u)): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Move <?php echo str_replace("'", "\\'", $u['full_name']); ?> to recycle bin?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="single_action" value="delete">
                                            <input type="hidden" name="record_id" value="<?php echo (int) $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card-body text-center py-5">
                <i class="bi bi-people-fill" style="font-size: 3rem; color: #ccc;"></i>
                <h5 class="mt-3">No researchers found</h5>
                <p class="text-muted">Add your first team member.</p>
                <a href="<?php echo BASE_URL; ?>/user-add.php" class="btn btn-success">Add Researcher</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
