<?php
/**
 * Krishi Sathi Research System - Media Deletion API
 * DEV-CHANGE-PACKAGE-07: Soft delete for all media types.
 *
 * POST params:
 *   - media_id: int (required)
 *   - permanent: int (optional, 1 = permanent delete, lead only)
 *
 * Returns JSON.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-media.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$mediaId = (int) ($_POST['media_id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Media ID required.']);
    exit;
}

$isPermanent = !empty($_POST['permanent']);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM research_media WHERE id = ?");
$stmt->execute([$mediaId]);
$media = $stmt->fetch();

if (!$media) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Media not found.']);
    exit;
}

// ─── Permanent delete (lead only) ─────────────────────────────
if ($isPermanent) {
    if (!isLead()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only Research Lead can permanently delete media.']);
        exit;
    }
    // Determine entity type for audit
    $auditType = ''; $auditId = 0;
    if ($media['interview_id']) { $auditType = 'interview'; $auditId = $media['interview_id']; }
    elseif ($media['observation_id']) { $auditType = 'observation'; $auditId = $media['observation_id']; }
    elseif ($media['farmer_id']) { $auditType = 'farmer'; $auditId = $media['farmer_id']; }
    elseif ($media['participant_id']) { $auditType = 'participant'; $auditId = $media['participant_id']; }
    // Delete file and thumbnail from disk
    deleteMediaFile($media['file_path']);
    // Delete database record
    $pdo->prepare("DELETE FROM research_media WHERE id = ?")->execute([$mediaId]);
    logAudit($pdo, 'media_deleted', $auditType, $auditId,
             'Permanent delete of media #' . $mediaId . ' (' . ($media['original_name'] ?? '') . ')');
    echo json_encode(['success' => true, 'message' => 'Media permanently deleted.']);
    exit;
}

// ─── Approval lock check ────────────────────────────────────────
if ($media['interview_id'] || $media['observation_id']) {
    $lockCheckTable = $media['interview_id'] ? 'research_interviews' : 'research_observations';
    $lockCheckId = $media['interview_id'] ?: $media['observation_id'];
    $lockStmt = $pdo->prepare("SELECT * FROM {$lockCheckTable} WHERE id = ?");
    $lockStmt->execute([$lockCheckId]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity && isRecordLocked($lockEntity)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cannot delete media from an approved/locked record.']);
        exit;
    }
}

// ─── Soft delete ──────────────────────────────────────────────
// Contributors can soft-delete; leads can always soft-delete
if (isViewer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Viewers cannot delete media.']);
    exit;
}
// Contributors can delete media from any non-locked record

// Soft delete: mark as deleted but keep files and record
$pdo->prepare("UPDATE research_media SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
    ->execute([currentUserId(), $mediaId]);

$auditType = ''; $auditId = 0;
if ($media['interview_id']) { $auditType = 'interview'; $auditId = $media['interview_id']; }
elseif ($media['observation_id']) { $auditType = 'observation'; $auditId = $media['observation_id']; }
elseif ($media['farmer_id']) { $auditType = 'farmer'; $auditId = $media['farmer_id']; }
elseif ($media['participant_id']) { $auditType = 'participant'; $auditId = $media['participant_id']; }
logAudit($pdo, 'media_deleted', $auditType, $auditId,
         'Soft delete of media #' . $mediaId . ' (' . ($media['original_name'] ?? '') . ')');

echo json_encode(['success' => true, 'message' => 'Media moved to trash.']);
