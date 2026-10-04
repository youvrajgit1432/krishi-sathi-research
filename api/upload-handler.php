<?php
/**
 * Krishi Sathi Research System - Media Upload Handler
 * MEDIA-UPLOAD-01: Comprehensive upload endpoint for photos, audio, video.
 *
 * POST params:
 *   - file: uploaded file
 *   - media_type: 'image' | 'audio' | 'video' (auto-detected if omitted)
 *   - interview_id: int (optional)
 *   - observation_id: int (optional)
 *   - farmer_id: int (optional)
 *   - participant_id: int (optional)
 *   - caption: text (optional)
 *   - consent: 'research' | 'internal' | 'none' (default: 'research')
 *   - duration: string (optional, for audio/video)
 *
 * Returns JSON.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-media.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (isViewer()) {
    http_response_code(403);
    echo json_encode(['error' => 'Viewers cannot upload media.']);
    exit;
}

// ─── Oversized POST detection (before any $_POST / $_FILES access) ──
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    $postMax = return_bytes(ini_get('post_max_size'));
    $uploadMax = return_bytes(ini_get('upload_max_filesize'));
    $limit = max($postMax, $uploadMax);
    $sizeHuman = round($contentLength / 1024 / 1024, 1) . ' MB';
    $limitHuman = $limit > 0 ? round($limit / 1024 / 1024, 1) . ' MB' : 'unknown';
    http_response_code(413);
    echo json_encode([
        'error' => 'The uploaded file (' . $sizeHuman . ') exceeds the server upload limit (' . $limitHuman . ').',
        'code'  => 'upload_too_large',
    ]);
    exit;
}

// ─── Validate entity binding (at least one required) ────────────
$interviewId   = (int) ($_POST['interview_id'] ?? 0);
$observationId = (int) ($_POST['observation_id'] ?? 0);
$farmerId      = (int) ($_POST['farmer_id'] ?? 0);
$participantId = (int) ($_POST['participant_id'] ?? 0);

$entityCount = ($interviewId > 0 ? 1 : 0)
    + ($observationId > 0 ? 1 : 0)
    + ($farmerId > 0 ? 1 : 0)
    + ($participantId > 0 ? 1 : 0);

if ($entityCount === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one entity ID required: interview_id, observation_id, farmer_id, or participant_id.']);
    exit;
}

// ─── Approval lock check ────────────────────────────────────────
if ($interviewId > 0) {
    $lockStmt = getDB()->prepare("SELECT * FROM research_interviews WHERE id = ?");
    $lockStmt->execute([$interviewId]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity && isRecordLocked($lockEntity) && !isLead()) {
        http_response_code(403);
        echo json_encode(['error' => 'Cannot upload media to an approved/locked interview.']);
        exit;
    }
}
if ($observationId > 0) {
    $lockStmt = getDB()->prepare("SELECT * FROM research_observations WHERE id = ?");
    $lockStmt->execute([$observationId]);
    $lockEntity = $lockStmt->fetch();
    if ($lockEntity) {
        // Check if observation's linked interview is approved
        if ($lockEntity['interview_id']) {
            $ivStmt = getDB()->prepare("SELECT * FROM research_interviews WHERE id = ?");
            $ivStmt->execute([$lockEntity['interview_id']]);
            $iv = $ivStmt->fetch();
            if ($iv && isRecordLocked($iv) && !isLead()) {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot upload media to an observation linked to an approved interview.']);
                exit;
            }
        }
    }
}

// ─── Validate file upload ───────────────────────────────────────
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
    $errMsg = 'File upload failed';
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        $errMsg = 'File exceeds maximum upload size.';
    } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
        $errMsg = 'File was only partially uploaded.';
    } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
        $errMsg = 'No file was selected for upload.';
    }
    http_response_code(400);
    echo json_encode(['error' => $errMsg . ' (code: ' . $errCode . ').']);
    exit;
}

// ─── MIME type detection (always use finfo, NEVER trust extension) ──
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

$originalName = $_FILES['file']['name'];

// ─── Detect media type (auto or explicit) ───────────────────────
$mediaType = $_POST['media_type'] ?? '';
if ($mediaType === '' || !in_array($mediaType, [MEDIA_TYPE_IMAGE, MEDIA_TYPE_AUDIO, MEDIA_TYPE_VIDEO], true)) {
    $mediaType = detectMediaType($mime);
}
if ($mediaType === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported file format. Allowed: JPEG, PNG, WebP, GIF, BMP, TIFF, HEIC (images); MP3, WAV, M4A, AAC, OGG, FLAC (audio); MP4, MOV, WebM, AVI, MKV (video).']);
    exit;
}

// ─── MIME/extension consistency guard ───────────────────────────
$execMimes = ['text/x-php', 'application/x-php', 'application/x-sh', 'text/x-shellscript',
              'application/x-msdownload', 'application/vnd.microsoft.portable-executable'];
if (in_array($mime, $execMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Executable files are not allowed.']);
    exit;
}

// ─── File size validation ───────────────────────────────────────
$maxSize = getMediaMaxSize($mediaType);
if ($_FILES['file']['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum for ' . $mediaType . ': ' . formatBytes($maxSize) . '.']);
    exit;
}

// ─── Consent validation ─────────────────────────────────────────
$validConsent = ['research', 'internal', 'none'];
$consent = $_POST['consent'] ?? 'research';
if (!in_array($consent, $validConsent, true)) {
    $consent = 'research';
}

$caption = trim($_POST['caption'] ?? '');
$duration = trim($_POST['duration'] ?? '');

// ─── Store the file ─────────────────────────────────────────────
$result = storeUploadedFile($_FILES['file']['tmp_name'], $mediaType, $originalName);
if (!$result[0]) {
    http_response_code(500);
    echo json_encode(['error' => $result[1]['error']]);
    exit;
}

$fileInfo = $result[1];
$targetPath = $fileInfo['path'];
$relPath = $fileInfo['rel_path'];
$fileSize = $fileInfo['size'];

// ─── Image processing: EXIF correction, optimization, thumbnail ─
$thumbnailPath = null;
if ($mediaType === MEDIA_TYPE_IMAGE) {
    if ($mime === 'image/jpeg') {
        correctExifOrientation($targetPath);
    }
    $shouldOptimize = $fileSize > MEDIA_WEBP_LARGE_THRESHOLD || $mime === 'image/png';
    if ($shouldOptimize) {
        optimizeImage($targetPath, MEDIA_WEBP_QUALITY);
        $fileSize = filesize($targetPath);
    }
    $thumbnailPath = generateImageThumbnail($targetPath, $fileInfo['year'], $fileInfo['month'], $fileInfo['filename']);
} elseif ($mediaType === MEDIA_TYPE_VIDEO) {
    $thumbnailPath = generateVideoThumbnail($targetPath, $fileInfo['year'], $fileInfo['month'], $fileInfo['filename']);
}

// ─── Store in database ─────────────────────────────────────────
$pdo = getDB();
try {
    $stmt = $pdo->prepare("
        INSERT INTO research_media
            (interview_id, observation_id, farmer_id, participant_id,
             media_type, file_path, original_name, stored_name,
             file_size, mime_type, duration, caption, consent,
             thumbnail_path, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $interviewId > 0 ? $interviewId : null,
        $observationId > 0 ? $observationId : null,
        $farmerId > 0 ? $farmerId : null,
        $participantId > 0 ? $participantId : null,
        $mediaType,
        $relPath,
        $originalName,
        $fileInfo['filename'],
        $fileSize,
        $mime,
        $duration ?: null,
        $caption ?: null,
        $consent,
        $thumbnailPath,
        currentUserId(),
    ]);
    $mediaId = $pdo->lastInsertId();

    // ─── Audit log ────────────────────────────────────────────────
    $entityType = '';
    $entityId = 0;
    if ($interviewId > 0) { $entityType = 'interview'; $entityId = $interviewId; }
    elseif ($observationId > 0) { $entityType = 'observation'; $entityId = $observationId; }
    elseif ($farmerId > 0) { $entityType = 'farmer'; $entityId = $farmerId; }
    elseif ($participantId > 0) { $entityType = 'participant'; $entityId = $participantId; }
    if ($entityType) {
        logAudit($pdo, 'media_uploaded', $entityType, $entityId,
                 'Uploaded ' . $mediaType . ': ' . $originalName);
    }
} catch (PDOException $e) {
    deleteMediaFile($relPath);
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ─── Success response ──────────────────────────────────────────
$response = [
    'success'        => true,
    'media_id'       => (int) $mediaId,
    'media_type'     => $mediaType,
    'file_path'      => $relPath,
    'file_size'      => $fileSize,
    'mime_type'      => $mime,
    'original_name'  => $originalName,
    'caption'        => $caption ?: null,
];

if ($thumbnailPath) {
    $response['thumbnail_path'] = $thumbnailPath;
}

echo json_encode($response);
