<?php
/**
 * Krishi Sathi Research System - Media Trash
 * DEV-CHANGE-PACKAGE-07: View, restore, and permanently delete soft-deleted media.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-media.php';
requireLogin();

$pdo = getDB();
$errors = [];
$success = '';

// ─── Restore handler ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/media-trash.php');
        exit;
    }
    $source = $_POST['source'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId > 0) {
        // Permission check: contributors can only restore their own media
        if (isContributor()) {
            $ownerId = 0;
            if ($source === 'media') {
                $ownerStmt = $pdo->prepare("SELECT uploaded_by FROM research_media WHERE id = ?");
                $ownerStmt->execute([$itemId]);
                $ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);
            } elseif ($source === 'photos') {
                $ownerStmt = $pdo->prepare("SELECT uploaded_by FROM research_photos WHERE id = ?");
                $ownerStmt->execute([$itemId]);
                $ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);
            }
            if ($ownerId !== currentUserId()) {
                setFlash('error', 'You can only restore your own media.');
                header('Location: ' . BASE_URL . '/media-trash.php');
                exit;
            }
        }
        if ($source === 'media') {
            $pdo->prepare("UPDATE research_media SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$itemId]);
        } elseif ($source === 'photos') {
            $pdo->prepare("UPDATE research_photos SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$itemId]);
        }
        setFlash('success', 'Media restored successfully.');
    }
    header('Location: ' . BASE_URL . '/media-trash.php');
    exit;
}

// ─── Permanent delete handler (lead only) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'permanent_delete') {
    if (!isLead()) {
        setFlash('error', 'Only Research Lead can permanently delete media.');
        header('Location: ' . BASE_URL . '/media-trash.php');
        exit;
    }
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/media-trash.php');
        exit;
    }
    $source = $_POST['source'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId > 0) {
        if ($source === 'media') {
            $stmt = $pdo->prepare("SELECT * FROM research_media WHERE id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if ($item) {
                deleteMediaFile($item['file_path']);
                $pdo->prepare("DELETE FROM research_media WHERE id = ?")->execute([$itemId]);
            }
        } elseif ($source === 'photos') {
            $stmt = $pdo->prepare("SELECT * FROM research_photos WHERE id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if ($item) {
                $photoPath = $item['filename'] ?? '';
                $fp1 = __DIR__ . '/uploads/' . $photoPath;
                if (file_exists($fp1)) { @unlink($fp1); }
                $fp2 = __DIR__ . '/../../' . $photoPath;
                if (file_exists($fp2)) { @unlink($fp2); }
                $pdo->prepare("DELETE FROM research_photos WHERE id = ?")->execute([$itemId]);
            }
        }
        setFlash('success', 'Media permanently deleted.');
    }
    header('Location: ' . BASE_URL . '/media-trash.php');
    exit;
}

// ─── Fetch deleted media ──────────────────────────────────────
$deletedItems = [];

// Research media (deleted)
$mediaSql = "SELECT * FROM research_media WHERE is_deleted = 1 OR deleted_at IS NOT NULL ORDER BY deleted_at DESC";
$mediaStmt = $pdo->query($mediaSql);
while ($m = $mediaStmt->fetch()) {
    // Contributors can only see their own deleted media
    if (isContributor() && (int) ($m['uploaded_by'] ?? 0) !== currentUserId()) continue;
    $filePath = $m['file_path'] ?? '';
    $url = BASE_URL . '/uploads/' . $filePath;
    $thumbUrl = $m['thumbnail_path'] ? BASE_URL . '/uploads/' . $m['thumbnail_path'] : '';
    $deletedItems[] = [
        'id' => $m['id'],
        'source' => 'media',
        'type' => $m['media_type'],
        'url' => $url,
        'thumbnail_url' => $thumbUrl,
        'name' => $m['original_name'] ?? $filePath,
        'caption' => $m['caption'] ?? '',
        'consent' => $m['consent'] ?? 'research',
        'file_size' => $m['file_size'] ?? 0,
        'mime_type' => $m['mime_type'] ?? '',
        'created_at' => $m['created_at'] ?? '',
        'deleted_at' => $m['deleted_at'] ?? '',
        'uploaded_by' => $m['uploaded_by'] ?? null,
    ];
}

// Research photos (deleted)
$photoSql = "SELECT * FROM research_photos WHERE is_deleted = 1 OR deleted_at IS NOT NULL ORDER BY deleted_at DESC";
$photoStmt = $pdo->query($photoSql);
while ($p = $photoStmt->fetch()) {
    $filename = $p['filename'] ?? '';
    if (strpos($filename, '/') !== false) {
        $url = IS_LOCALHOST ? '/phool-delivery/' . $filename : '/' . $filename;
    } else {
        $url = BASE_URL . '/uploads/' . $filename;
    }
    // Contributors can only see their own deleted photos
    if (isContributor() && (int) ($p['uploaded_by'] ?? 0) !== currentUserId()) continue;
    $deletedItems[] = [
        'id' => $p['id'],
        'source' => 'photos',
        'type' => 'image',
        'url' => $url,
        'thumbnail_url' => $url,
        'name' => $p['original_name'] ?? $filename,
        'caption' => $p['caption'] ?? '',
        'consent' => $p['consent'] ?? 'research',
        'file_size' => 0,
        'mime_type' => 'image/jpeg',
        'created_at' => $p['created_at'] ?? '',
        'deleted_at' => $p['deleted_at'] ?? '',
        'uploaded_by' => $p['uploaded_by'] ?? null,
    ];
}

// Sort by deleted_at DESC
usort($deletedItems, function ($a, $b) {
    return strcmp($b['deleted_at'] ?? '', $a['deleted_at'] ?? '');
});

include __DIR__ . '/header.php';
?>

<div class="container-fluid px-3 py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-trash"></i> Media Trash</h4>
        <a href="<?php echo BASE_URL; ?>/recycle-bin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Recycle Bin</a>
    </div>

    <?php if (empty($deletedItems)): ?>
        <div class="alert alert-info">No deleted media found.</div>
    <?php else: ?>
        <p class="text-muted small"><?php echo count($deletedItems); ?> item(s) in trash.</p>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Preview</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Caption</th>
                        <th>Consent</th>
                        <th>Deleted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deletedItems as $item): ?>
                    <tr>
                        <td style="width:80px">
                            <?php if ($item['type'] === 'image'): ?>
                                <img src="<?php echo htmlspecialchars($item['thumbnail_url'] ?: $item['url']); ?>" style="width:60px;height:40px;object-fit:cover" alt="" class="rounded border">
                            <?php elseif ($item['type'] === MEDIA_TYPE_VIDEO): ?>
                                <video src="<?php echo htmlspecialchars($item['url']); ?>" style="width:60px;height:40px;object-fit:cover" class="rounded border" muted></video>
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center bg-light rounded border" style="width:60px;height:40px">
                                    <i class="bi bi-file-earmark-play"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo htmlspecialchars($item['name']); ?></small></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['type']); ?></span></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars(mb_substr($item['caption'], 0, 40)); ?></small></td>
                        <td><span class="badge bg-info"><?php echo htmlspecialchars($item['consent']); ?></span></td>
                        <td><small class="text-danger"><?php echo htmlspecialchars($item['deleted_at']); ?></small></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <form method="post" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="source" value="<?php echo $item['source']; ?>">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Restore this media?')"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                </form>
                                <?php if (isLead()): ?>
                                <form method="post" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="permanent_delete">
                                    <input type="hidden" name="source" value="<?php echo $item['source']; ?>">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete this media? This cannot be undone.')"><i class="bi bi-trash3"></i> Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
