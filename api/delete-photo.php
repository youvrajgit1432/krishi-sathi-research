<?php
/**
 * Krishi Sathi Research System - Photo Deletion API
 * DEV-CHANGE-PACKAGE-07: Soft delete for photos.
 *
 * POST params:
 *   - photo_id: int (required)
 *   - permanent: int (optional, 1 = permanent delete, lead only)
 *
 * Returns JSON.
 */

require_once __DIR__ . '/../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$photoId = (int) ($_POST['photo_id'] ?? 0);
if ($photoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Photo ID required.']);
    exit;
}

$isPermanent = !empty($_POST['permanent']);

$pdo = getDB();

// Fetch photo record
$stmt = $pdo->prepare("SELECT * FROM research_photos WHERE id = ?");
$stmt->execute([$photoId]);
$photo = $stmt->fetch();

if (!$photo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Photo not found.']);
    exit;
}

// ─── Permanent delete (lead only) ─────────────────────────────
if ($isPermanent) {
    if (!isLead()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only Research Lead can permanently delete photos.']);
        exit;
    }
    // Delete file from disk (handle two possible filename conventions)
    $photoPath = $photo['filename'] ?? '';
    $fullPath1 = __DIR__ . '/../uploads/' . $photoPath;
    if (file_exists($fullPath1)) { @unlink($fullPath1); }
    $fullPath2 = __DIR__ . '/../../' . $photoPath;
    if (file_exists($fullPath2)) { @unlink($fullPath2); }
    // Delete database record
    $pdo->prepare("DELETE FROM research_photos WHERE id = ?")->execute([$photoId]);
    logAudit($pdo, 'photo_deleted', 'observation', $photo['observation_id'] ?: 0,
             'Permanent delete of photo #' . $photoId);
    echo json_encode(['success' => true, 'message' => 'Photo permanently deleted.']);
    exit;
}

// ─── Approval lock check ────────────────────────────────────────
if ($photo['interview_id']) {
    $lockStmt = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
    $lockStmt->execute([$photo['interview_id']]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity && isRecordLocked($lockEntity)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cannot delete photos from an approved/locked interview.']);
        exit;
    }
}

// ─── Soft delete ──────────────────────────────────────────────
if (isViewer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Viewers cannot delete photos.']);
    exit;
}
// Contributors can delete photos from any non-locked record

// Soft delete: mark as deleted but keep files and record
$pdo->prepare("UPDATE research_photos SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
    ->execute([currentUserId(), $photoId]);

logAudit($pdo, 'photo_deleted', 'observation', $photo['observation_id'] ?: 0,
         'Soft delete of photo #' . $photoId);

echo json_encode(['success' => true, 'message' => 'Photo moved to trash.']);
