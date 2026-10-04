<?php
/**
 * Krishi Sathi Research System - Photo Upload API
 * Phase 1.7: Field Reliability & Data Protection
 *
 * Accepts multipart file upload, converts to WebP,
 * stores in uploads/research/photos/YYYY/MM/,
 * and records in the database.
 *
 * POST params:
 *   - file: the uploaded image file
 *   - interview_id: int (required)
 *   - consent: 'research' | 'internal' | 'none'
 *   - caption: optional text
 *
 * Returns JSON.
 */

require_once __DIR__ . '/../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
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

// ─── Validate permissions ──────────────────────────────────────
if (isViewer()) {
    http_response_code(403);
    echo json_encode(['error' => 'Viewers cannot upload photos.']);
    exit;
}

// ─── Validate params ───────────────────────────────────────────
$interviewId = (int) ($_POST['interview_id'] ?? 0);
$consent     = $_POST['consent'] ?? '';
$caption     = trim($_POST['caption'] ?? '');

if ($interviewId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Interview ID is required.']);
    exit;
}

$interviewStmt = getDB()->prepare("SELECT * FROM research_interviews WHERE id = ?");
$interviewStmt->execute([$interviewId]);
$interview = $interviewStmt->fetch();
if (!$interview || !(canEditInterview($interview) || isLead())) {
    http_response_code(403);
    echo json_encode(['error' => 'This interview is locked or unavailable for photo uploads.']);
    exit;
}

$validConsent = ['research', 'internal', 'none'];
if (!in_array($consent, $validConsent)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid consent level required: research, internal, or none.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed (code: ' . $errCode . ').']);
    exit;
}

// ─── Validate file type ────────────────────────────────────────
$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/tiff'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMime)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported file format. Allowed: JPEG, PNG, WebP, GIF, BMP, TIFF.']);
    exit;
}

// ─── Ensure upload directory exists ────────────────────────────
$baseDir = __DIR__ . '/../../uploads/research/photos';
$yearDir  = date('Y');
$monthDir = date('m');
$targetDir = $baseDir . '/' . $yearDir . '/' . $monthDir;

if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create upload directory.']);
        exit;
    }
}

// ─── Generate unique filename ──────────────────────────────────
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$base = time() . '_' . bin2hex(random_bytes(4));
$webpFilename = $base . '.webp';
$targetPath = $targetDir . '/' . $webpFilename;
$relativePath = 'uploads/research/photos/' . $yearDir . '/' . $monthDir . '/' . $webpFilename;

// ─── Convert to WebP (if GD is available) ─────────────────────
$hasGD = function_exists('imagecreatefromjpeg') && function_exists('imagewebp');
$converted = false;

if ($hasGD && $mime !== 'image/tiff') {
    $image = null;
    try {
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($_FILES['file']['tmp_name']);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($_FILES['file']['tmp_name']);
                if ($image) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($_FILES['file']['tmp_name']);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($_FILES['file']['tmp_name']);
                break;
            case 'image/bmp':
                $image = @imagecreatefrombmp($_FILES['file']['tmp_name']);
                break;
        }

        if ($image) {
            $converted = imagewebp($image, $targetPath, 80);
            imagedestroy($image);
        }
    } catch (Exception $e) {
        // Fall through to fallback copy
    }
}

if (!$converted) {
    // Fallback: copy original file with original extension
    $fallbackFilename = $base . '.' . $ext;
    $fallbackPath = $targetDir . '/' . $fallbackFilename;
    $relativePath = 'uploads/research/photos/' . $yearDir . '/' . $monthDir . '/' . $fallbackFilename;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fallbackPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file.']);
        exit;
    }
    $finalPath = $fallbackPath;
} else {
    $finalPath = $targetPath;
}

// ─── Verify file size is reasonable ────────────────────────────
$finalSize = filesize($finalPath);
if ($finalSize > 10 * 1024 * 1024 && $hasGD && $converted) {
    // Over 10MB — try to reduce quality further
    $img = @imagecreatefromwebp($finalPath);
    if ($img) {
        imagewebp($img, $finalPath, 50);
        $finalSize = filesize($finalPath);
        imagedestroy($img);
    }
}

// ─── Store in database ─────────────────────────────────────────
$pdo = getDB();

// Ensure interview_id column exists (migration may not have run on this DB)
try {
    $stmt = $pdo->prepare("INSERT INTO research_photos (interview_id, observation_id, filename, original_name, caption, consent, uploaded_by)
                           VALUES (?, NULL, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $interviewId,
        $relativePath,
        $_FILES['file']['name'],
        $caption ?: null,
        $consent,
        currentUserId()
    ]);
    $photoId = $pdo->lastInsertId();
} catch (PDOException $e) {
    // Fallback: try without consent/uploaded_by columns (pre-migration)
    try {
        $stmt = $pdo->prepare("INSERT INTO research_photos (interview_id, observation_id, filename, original_name, caption)
                               VALUES (?, NULL, ?, ?, ?)");
        $stmt->execute([
            $interviewId,
            $relativePath,
            $_FILES['file']['name'],
            $caption ?: null
        ]);
        $photoId = $pdo->lastInsertId();
    } catch (PDOException $e2) {
        // Clean up uploaded file on DB failure
        if (file_exists($finalPath)) unlink($finalPath);
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e2->getMessage()]);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'photo_id' => $photoId,
    'filename' => $relativePath,
    'consent'  => $consent,
    'size'     => $finalSize
]);
