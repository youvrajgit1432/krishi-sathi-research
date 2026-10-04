<?php
/**
 * Krishi Sathi Research System - Replace Media API
 * DEV-CHANGE-PACKAGE-07: Replace a media file while keeping the database record.
 *
 * POST params:
 *   - media_id: int (required)
 *   - file: uploaded file (replacement)
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

if (isViewer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Viewers cannot replace media.']);
    exit;
}

$mediaId = (int) ($_POST['media_id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Media ID required.']);
    exit;
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM research_media WHERE id = ?");
$stmt->execute([$mediaId]);
$media = $stmt->fetch();

if (!$media) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Media not found.']);
    exit;
}

// ─── Approval lock check ────────────────────────────────────────
if ($media['interview_id']) {
    $lockStmt = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
    $lockStmt->execute([$media['interview_id']]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity && isRecordLocked($lockEntity)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cannot replace media in an approved/locked interview.']);
        exit;
    }
} elseif ($media['observation_id']) {
    $lockStmt = $pdo->prepare("SELECT * FROM research_observations WHERE id = ? AND interview_id IS NOT NULL");
    $lockStmt->execute([$media['observation_id']]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity && $lockEntity['interview_id']) {
        $ivStmt = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
        $ivStmt->execute([$lockEntity['interview_id']]);
        $iv = $ivStmt->fetch();
        if ($iv && isRecordLocked($iv)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Cannot replace media in an approved/locked observation.']);
            exit;
        }
    }
}

// Permission check
if (isContributor()) {
    $uploaderId = (int) ($media['uploaded_by'] ?? 0);
    if ($uploaderId !== currentUserId()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only replace your own media.']);
        exit;
    }
}

// Validate uploaded file
if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Upload error: ' . $_FILES['file']['error']]);
    exit;
}

// Validate MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
$mediaType = $media['media_type'];
$allowedMimes = $MEDIA_ALLOWED_MIMES[$mediaType] ?? [];

// For backward compatibility, also accept image mimes for photos
if ($mediaType === MEDIA_TYPE_IMAGE && empty($allowedMimes)) {
    $allowedMimes = $MEDIA_ALLOWED_MIMES[MEDIA_TYPE_IMAGE] ?? [];
}

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File type does not match original media type.']);
    exit;
}

// Store the new file
$originalName = $_FILES['file']['name'];
$result = storeUploadedFile($_FILES['file']['tmp_name'], $mediaType, $originalName);
if (!$result[0]) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $result[1]['error']]);
    exit;
}

$fileInfo = $result[1];
$newPath = $fileInfo['path'];
$newRelPath = $fileInfo['rel_path'];
$newSize = $fileInfo['size'];

// Delete the old file and thumbnail
deleteMediaFile($media['file_path']);

// Generate new thumbnail
$thumbnailPath = null;
if ($mediaType === MEDIA_TYPE_IMAGE) {
    if ($mime === 'image/jpeg') {
        correctExifOrientation($newPath);
    }
    $thumbnailPath = generateImageThumbnail($newPath, $fileInfo['year'], $fileInfo['month'], $fileInfo['filename']);
} elseif ($mediaType === MEDIA_TYPE_VIDEO) {
    $thumbnailPath = generateVideoThumbnail($newPath, $fileInfo['year'], $fileInfo['month'], $fileInfo['filename']);
}

// Update database record
$pdo->prepare("UPDATE research_media SET file_path = ?, stored_name = ?, original_name = ?, file_size = ?, mime_type = ?, thumbnail_path = ? WHERE id = ?")
    ->execute([$newRelPath, $fileInfo['filename'], $originalName, $newSize, $mime, $thumbnailPath, $mediaId]);

// Audit log
$entityType = '';
$entityId = 0;
if ($media['interview_id']) { $entityType = 'interview'; $entityId = $media['interview_id']; }
elseif ($media['observation_id']) { $entityType = 'observation'; $entityId = $media['observation_id']; }
elseif ($media['farmer_id']) { $entityType = 'farmer'; $entityId = $media['farmer_id']; }
elseif ($media['participant_id']) { $entityType = 'participant'; $entityId = $media['participant_id']; }
if ($entityType) {
    logAudit($pdo, 'media_replaced', $entityType, $entityId,
             'Replaced media #' . $mediaId . ' with ' . $originalName);
}

echo json_encode([
    'success' => true,
    'message' => 'Media replaced successfully.',
    'file_path' => $newRelPath,
    'file_size' => $newSize,
    'mime_type' => $mime,
    'original_name' => $originalName,
]);
