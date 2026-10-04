<?php
/**
 * Krishi Sathi Research System - Consent Form Upload API
 * Phase 2C: Evidence & Ethics Infrastructure
 *
 * Accepts multipart file upload for consent form documents.
 * POST params:
 *   - file: PDF or image file
 *   - consent_id: int (the consent record ID)
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

if (isViewer()) {
    http_response_code(403);
    echo json_encode(['error' => 'Viewers cannot upload files.']);
    exit;
}

$consentId = (int) ($_POST['consent_id'] ?? 0);
if ($consentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Consent ID is required.']);
    exit;
}

// Verify consent record exists
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id FROM research_consent_records WHERE id = ?");
$stmt->execute([$consentId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Consent record not found.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed (code: ' . $errCode . ').']);
    exit;
}

// Validate file size
if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File exceeds the 10 MB size limit.']);
    exit;
}

// Validate file type
$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMime)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported format. Allowed: PDF, JPEG, PNG, GIF, WebP.']);
    exit;
}

// Ensure upload directory
$baseDir = __DIR__ . '/../../uploads/research/consent';
if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create upload directory.']);
        exit;
    }
}

// Generate filename
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$base = time() . '_' . bin2hex(random_bytes(4));

if ($mime === 'application/pdf') {
    $filename = $base . '.pdf';
    $targetPath = $baseDir . '/' . $filename;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file.']);
        exit;
    }
} else {
    // Convert images to WebP
    $filename = $base . '.webp';
    $targetPath = $baseDir . '/' . $filename;
    $image = null;
    switch ($mime) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($_FILES['file']['tmp_name']); break;
        case 'image/png': $image = @imagecreatefrompng($_FILES['file']['tmp_name']); if ($image) { imagepalettetotruecolor($image); imagesavealpha($image, true); } break;
        case 'image/gif': $image = @imagecreatefromgif($_FILES['file']['tmp_name']); break;
        case 'image/webp': $image = @imagecreatefromwebp($_FILES['file']['tmp_name']); break;
    }
    if ($image) {
        $converted = imagewebp($image, $targetPath, 80);
        imagedestroy($image);
        if (!$converted) {
            http_response_code(500);
            echo json_encode(['error' => 'Image conversion failed.']);
            exit;
        }
    } else {
        // Fallback: copy as original
        $filename = $base . '.' . $ext;
        $targetPath = $baseDir . '/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file.']);
            exit;
        }
    }
}

$relativePath = 'uploads/research/consent/' . $filename;

// Update the consent record
$update = $pdo->prepare("UPDATE research_consent_records SET consent_form_filename = ?, consent_form_original_name = ? WHERE id = ?");
$update->execute([$relativePath, $_FILES['file']['name'], $consentId]);

logAudit($pdo, 'consent_form_uploaded', 'consent', $consentId);

echo json_encode([
    'success' => true,
    'consent_id' => $consentId,
    'filename' => $relativePath,
    'original_name' => $_FILES['file']['name'],
]);
