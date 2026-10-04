<?php
/**
 * Krishi Sathi Research System - Media Upload Configuration
 * MEDIA-UPLOAD-01: Centralized media config, MIME types, size limits, paths.
 */

// ─── Media Type Constants ────────────────────────────────────────
define('MEDIA_TYPE_IMAGE', 'image');
define('MEDIA_TYPE_AUDIO', 'audio');
define('MEDIA_TYPE_VIDEO', 'video');

// ─── Allowed MIME Types ──────────────────────────────────────────
$MEDIA_ALLOWED_MIMES = [
    MEDIA_TYPE_IMAGE => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/tiff',
        'image/heic',
        'image/heif',
    ],
    MEDIA_TYPE_AUDIO => [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/wave',
        'audio/ogg',
        'audio/aac',
        'audio/flac',
        'audio/x-m4a',
        'audio/mp4',
        'audio/x-wav',
    ],
    MEDIA_TYPE_VIDEO => [
        'video/mp4',
        'video/mpeg',
        'video/ogg',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'video/3gpp',
        'video/3gpp2',
    ],
];

// ─── Extension-to-MIME map for additional validation ────────────
$MEDIA_EXT_MAP = [
    // Images
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'bmp'  => 'image/bmp',
    'tiff' => 'image/tiff',
    'tif'  => 'image/tiff',
    'heic' => 'image/heic',
    'heif' => 'image/heif',
    // Audio
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'm4a'  => 'audio/x-m4a',
    'aac'  => 'audio/aac',
    'ogg'  => 'audio/ogg',
    'flac' => 'audio/flac',
    // Video
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'm4v'  => 'video/x-m4v',
    'avi'  => 'video/x-msvideo',
    'webm' => 'video/webm',
    'mkv'  => 'video/x-matroska',
    '3gp'  => 'video/3gpp',
];

// ─── Size Limits (can be overridden by server limits) ───────────
$MEDIA_MAX_SIZES = [
    MEDIA_TYPE_IMAGE => 20 * 1024 * 1024,   // 20 MB
    MEDIA_TYPE_AUDIO => 100 * 1024 * 1024,  // 100 MB
    MEDIA_TYPE_VIDEO => 500 * 1024 * 1024,  // 500 MB
];

// ─── Directory Structure ──────────────────────────────────────────
// All uploads go inside survey/uploads/{type}/YYYY/MM/
define('MEDIA_UPLOADS_DIR', __DIR__ . '/uploads');
define('MEDIA_IMAGES_DIR', MEDIA_UPLOADS_DIR . '/images');
define('MEDIA_VIDEOS_DIR', MEDIA_UPLOADS_DIR . '/videos');
define('MEDIA_AUDIO_DIR', MEDIA_UPLOADS_DIR . '/audio');
define('MEDIA_THUMBS_DIR', MEDIA_UPLOADS_DIR . '/thumbnails');
define('MEDIA_TEMP_DIR', MEDIA_UPLOADS_DIR . '/temp');

$MEDIA_TYPE_DIRS = [
    MEDIA_TYPE_IMAGE => MEDIA_IMAGES_DIR,
    MEDIA_TYPE_AUDIO => MEDIA_AUDIO_DIR,
    MEDIA_TYPE_VIDEO => MEDIA_VIDEOS_DIR,
];

// ─── Image Processing ────────────────────────────────────────────
define('MEDIA_WEBP_QUALITY', 80);
define('MEDIA_WEBP_QUALITY_LOW', 50);
define('MEDIA_WEBP_LARGE_THRESHOLD', 10 * 1024 * 1024); // 10 MB
define('MEDIA_IMAGE_MAX_DIMENSION', 4096); // Max width/height
define('MEDIA_THUMB_WIDTH', 300);
define('MEDIA_THUMB_HEIGHT', 200);

// ─── Functions ──────────────────────────────────────────────────

/**
 * Detect media type from MIME.
 */
function detectMediaType(string $mime): ?string {
    global $MEDIA_ALLOWED_MIMES;
    foreach ($MEDIA_ALLOWED_MIMES as $type => $mimes) {
        if (in_array($mime, $mimes, true)) {
            return $type;
        }
    }
    return null;
}

/**
 * Validate file extension against allowed types.
 */
function isAllowedExtension(string $ext): bool {
    global $MEDIA_EXT_MAP;
    return isset($MEDIA_EXT_MAP[strtolower($ext)]);
}

/**
 * Get max upload size in bytes for a media type, honoring server limits.
 */
function getMediaMaxSize(string $mediaType): int {
    global $MEDIA_MAX_SIZES;
    $configured = $MEDIA_MAX_SIZES[$mediaType] ?? 50 * 1024 * 1024;

    $postMax = return_bytes(ini_get('post_max_size'));
    $uploadMax = return_bytes(ini_get('upload_max_filesize'));

    $serverLimit = min($postMax, $uploadMax);
    if ($serverLimit > 0) {
        return min($configured, $serverLimit);
    }
    return $configured;
}

/**
 * Convert php.ini size string (e.g., '8M', '1G') to bytes.
 */
if (!function_exists('return_bytes')) {
    function return_bytes(string $val): int {
        $val = trim($val);
        if ($val === '' || $val === '-1') return 0;
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }
}

/**
 * Format bytes to human-readable string.
 */
function formatBytes(int $bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Generate a unique, safe filename preserving extension.
 */
function generateMediaFilename(string $originalName): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isAllowedExtension($ext)) {
        $ext = 'bin';
    }
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * Ensure target directory for a given year/month exists.
 * Returns the target directory path or null on failure.
 */
function ensureMediaDir(string $baseDir, string $year, string $month): ?string {
    $target = $baseDir . '/' . $year . '/' . $month;
    if (!is_dir($target)) {
        if (!mkdir($target, 0755, true)) {
            return null;
        }
    }
    return $target;
}

/**
 * Determine if GD supports WebP conversion.
 */
function hasWebpSupport(): bool {
    return function_exists('imagecreatefromjpeg')
        && function_exists('imagewebp')
        && (imagetypes() & IMG_WEBP);
}

/**
 * Correct EXIF orientation for JPEG images using GD.
 */
function correctExifOrientation(string $filePath): void {
    if (!function_exists('exif_read_data')) return;
    try {
        $exif = @exif_read_data($filePath);
        if ($exif === false || !isset($exif['Orientation'])) return;
        $orientation = (int) $exif['Orientation'];
        if ($orientation === 1) return;

        $img = @imagecreatefromjpeg($filePath);
        if ($img === false) return;

        switch ($orientation) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 3: $img = imagerotate($img, 180, 0); break;
            case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
            case 5: $img = imagerotate($img, 270, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 6: $img = imagerotate($img, 270, 0); break;
            case 7: $img = imagerotate($img, 90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 8: $img = imagerotate($img, 90, 0); break;
        }
        if ($img !== false) {
            @imagejpeg($img, $filePath, 90);
            imagedestroy($img);
        }
    } catch (Exception $e) {
        // Silently fail — EXIF correction is best-effort
    }
}

/**
 * Generate a thumbnail for an image file.
 * Returns the thumbnail relative path, or null on failure.
 */
function generateImageThumbnail(string $sourcePath, string $year, string $month, string $filename): ?string {
    $thumbDir = ensureMediaDir(MEDIA_THUMBS_DIR, $year, $month);
    if (!$thumbDir) return null;

    $info = @getimagesize($sourcePath);
    if ($info === false) return null;

    list($origW, $origH) = $info;
    $mime = $info['mime'];

    $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbPath = $thumbDir . '/' . $thumbFilename;
    $thumbRelPath = 'thumbnails/' . $year . '/' . $month . '/' . $thumbFilename;

    $src = null;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $src = @imagecreatefrompng($sourcePath); break;
        case 'image/webp': $src = @imagecreatefromwebp($sourcePath); break;
        case 'image/gif':  $src = @imagecreatefromgif($sourcePath); break;
        case 'image/bmp':  $src = @imagecreatefrombmp($sourcePath); break;
    }
    if (!$src) return null;

    $thumbW = MEDIA_THUMB_WIDTH;
    $thumbH = MEDIA_THUMB_HEIGHT;
    $ratio = min($thumbW / $origW, $thumbH / $origH);
    $newW = (int) round($origW * $ratio);
    $newH = (int) round($origH * $ratio);

    $thumb = imagecreatetruecolor($newW, $newH);
    if (!$thumb) { imagedestroy($src); return null; }

    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    $saved = @imagejpeg($thumb, $thumbPath, 75);
    imagedestroy($src);
    imagedestroy($thumb);

    return $saved ? $thumbRelPath : null;
}

/**
 * Check if FFmpeg is available on the system.
 */
function hasFFmpeg(): bool {
    $output = null;
    $ret = null;
    exec('ffmpeg -version 2>&1', $output, $ret);
    return $ret === 0;
}

/**
 * Generate a video thumbnail using FFmpeg (best-effort).
 */
function generateVideoThumbnail(string $sourcePath, string $year, string $month, string $filename): ?string {
    if (!hasFFmpeg()) {
        error_log('FFmpeg not available for video thumbnail: ' . $filename);
        return null;
    }
    $thumbDir = ensureMediaDir(MEDIA_THUMBS_DIR, $year, $month);
    if (!$thumbDir) return null;

    $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbPath = $thumbDir . '/' . $thumbFilename;
    $thumbRelPath = 'thumbnails/' . $year . '/' . $month . '/' . $thumbFilename;

    $cmd = sprintf(
        'ffmpeg -y -i %s -ss 00:00:02 -vframes 1 -vf scale=%d:%d -q:v 2 %s 2>&1',
        escapeshellarg($sourcePath),
        MEDIA_THUMB_WIDTH,
        MEDIA_THUMB_HEIGHT,
        escapeshellarg($thumbPath)
    );
    $output = null;
    $ret = null;
    exec($cmd, $output, $ret);

    if ($ret !== 0 || !file_exists($thumbPath)) {
        error_log('FFmpeg thumbnail generation failed for: ' . $filename);
        return null;
    }
    return $thumbRelPath;
}

/**
 * Attempt to optimize a JPEG image (recompress).
 */
function optimizeImage(string $filePath, int $quality = 80): void {
    $info = @getimagesize($filePath);
    if (!$info) return;
    $mime = $info['mime'];

    $img = null;
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($filePath); break;
        case 'image/png':
            $img = @imagecreatefrompng($filePath);
            if ($img) {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }
            break;
        default: return;
    }
    if (!$img) return;

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w > MEDIA_IMAGE_MAX_DIMENSION || $h > MEDIA_IMAGE_MAX_DIMENSION) {
        $ratio = min(MEDIA_IMAGE_MAX_DIMENSION / $w, MEDIA_IMAGE_MAX_DIMENSION / $h);
        $newW = (int) round($w * $ratio);
        $newH = (int) round($h * $ratio);
        $resized = imagecreatetruecolor($newW, $newH);
        if ($resized) {
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }
    }

    if ($mime === 'image/jpeg') {
        @imagejpeg($img, $filePath, $quality);
    } elseif ($mime === 'image/png') {
        @imagepng($img, $filePath, 6);
    }
    imagedestroy($img);
}

/**
 * Get media type subdirectory for storage.
 */
function getMediaTypeDir(string $mediaType): ?string {
    global $MEDIA_TYPE_DIRS;
    return $MEDIA_TYPE_DIRS[$mediaType] ?? null;
}

/**
 * Store a temporary uploaded file to its final destination.
 * Returns [success, data] where data is ['path' => ..., 'rel_path' => ..., 'size' => ...]
 */
function storeUploadedFile(string $tmpPath, string $mediaType, string $originalName): array {
    $baseDir = getMediaTypeDir($mediaType);
    if (!$baseDir) {
        return [false, ['error' => 'Invalid media type.']];
    }

    $year = date('Y');
    $month = date('m');
    $targetDir = ensureMediaDir($baseDir, $year, $month);
    if (!$targetDir) {
        return [false, ['error' => 'Failed to create upload directory.']];
    }

    $filename = generateMediaFilename($originalName);
    $targetPath = $targetDir . '/' . $filename;
    $relPath = $mediaType . 's/' . $year . '/' . $month . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return [false, ['error' => 'Failed to move uploaded file.']];
    }

    @chmod($targetPath, 0644);
    $fileSize = filesize($targetPath);

    return [true, [
        'path'      => $targetPath,
        'rel_path'  => $relPath,
        'filename'  => $filename,
        'size'      => $fileSize,
        'year'      => $year,
        'month'     => $month,
    ]];
}

/**
 * Normalize media items from research_photos and research_media into a
 * unified array for display in the media gallery.
 *
 * @param PDO   $pdo
 * @param array $opts  Keys: interview_id, observation_id, participant_id, farmer_id
 * @return array  Normalized items with keys: id, type, url, thumbnail_url, name,
 *                size, mime, caption, consent, duration, created_at, source, source_id
 */
function buildMediaGallery(PDO $pdo, array $opts): array {
    $items = [];

    $interviewId   = (int) ($opts['interview_id'] ?? 0);
    $observationId = (int) ($opts['observation_id'] ?? 0);
    $participantId = (int) ($opts['participant_id'] ?? 0);
    $farmerId      = (int) ($opts['farmer_id'] ?? 0);

    // 1. Fetch from research_photos
    $photoWhere = [];
    $photoParams = [];
    if ($interviewId > 0) {
        $photoWhere[] = 'interview_id = ?';
        $photoParams[] = $interviewId;
    }
    if ($observationId > 0) {
        $photoWhere[] = 'observation_id = ?';
        $photoParams[] = $observationId;
    }
    if (!empty($photoWhere)) {
        $photoSql = "SELECT *, 'photo' AS source_type FROM research_photos WHERE (COALESCE(is_deleted, 0) = 0 AND deleted_at IS NULL) AND (" . implode(' OR ', $photoWhere) . ") ORDER BY created_at DESC";
        $photoStmt = $pdo->prepare($photoSql);
        $photoStmt->execute($photoParams);
        $photos = $photoStmt->fetchAll();
        foreach ($photos as $p) {
            $filename = $p['filename'] ?? '';
            // Determine URL: two conventions
            if (strpos($filename, '/') !== false) {
                // Full relative path like "uploads/research/photos/2026/07/file.webp"
                // Files are at project-root level, so use BASE_URL's parent
                $url = IS_LOCALHOST ? '/phool-delivery/' . $filename : '/' . $filename;
            } else {
                // Bare filename stored in survey/uploads/
                $url = BASE_URL . '/uploads/' . $filename;
            }
            $items[] = [
                'id'             => 'photo_' . $p['id'],
                'type'           => 'image',
                'url'            => $url,
                'thumbnail_url'  => $url,
                'name'           => $p['original_name'] ?? $filename,
                'size'           => null,
                'mime'           => null,
                'caption'        => $p['caption'] ?? '',
                'consent'        => $p['consent'] ?? 'research',
                'duration'       => null,
                'created_at'     => $p['created_at'] ?? '',
                'source'         => 'photos',
                'source_id'      => (int) $p['id'],
            ];
        }
    }

    // 2. Fetch from research_media
    $mediaWhere = [];
    $mediaParams = [];
    if ($interviewId > 0) {
        $mediaWhere[] = 'interview_id = ?';
        $mediaParams[] = $interviewId;
    }
    if ($observationId > 0) {
        $mediaWhere[] = 'observation_id = ?';
        $mediaParams[] = $observationId;
    }
    if ($participantId > 0) {
        $mediaWhere[] = 'participant_id = ?';
        $mediaParams[] = $participantId;
    }
    if ($farmerId > 0) {
        $mediaWhere[] = 'farmer_id = ?';
        $mediaParams[] = $farmerId;
    }
    if (!empty($mediaWhere)) {
        $mediaSql = "SELECT * FROM research_media WHERE (COALESCE(is_deleted, 0) = 0 AND deleted_at IS NULL) AND (" . implode(' OR ', $mediaWhere) . ") ORDER BY created_at DESC";
        $mediaStmt = $pdo->prepare($mediaSql);
        $mediaStmt->execute($mediaParams);
        $mediaRows = $mediaStmt->fetchAll();
        foreach ($mediaRows as $m) {
            $filePath = $m['file_path'] ?? '';
            $url = BASE_URL . '/uploads/' . $filePath;
            $thumbUrl = '';
            if ($m['thumbnail_path']) {
                $thumbUrl = BASE_URL . '/uploads/' . $m['thumbnail_path'];
            }
            $items[] = [
                'id'             => 'media_' . $m['id'],
                'type'           => $m['media_type'] ?? 'image',
                'url'            => $url,
                'thumbnail_url'  => $thumbUrl,
                'name'           => $m['original_name'] ?? $filePath,
                'size'           => (int) ($m['file_size'] ?? 0),
                'mime'           => $m['mime_type'] ?? '',
                'caption'        => $m['caption'] ?? '',
                'consent'        => $m['consent'] ?? 'research',
                'duration'       => $m['duration'] ?? null,
                'created_at'     => $m['created_at'] ?? '',
                'source'         => 'media',
                'source_id'      => (int) $m['id'],
            ];
        }
    }

    // 3. Sort by created_at DESC
    usort($items, function($a, $b) {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    return $items;
}

/**
 * Delete a media file (and its thumbnail) from disk by relative path.
 */
function deleteMediaFile(string $relPath): void {
    $fullPath = MEDIA_UPLOADS_DIR . '/' . $relPath;
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
    // Delete thumbnail if it exists
    $thumbRelPath = str_replace(['images/', 'videos/', 'audio/'], 'thumbnails/', $relPath);
    $thumbPath = MEDIA_UPLOADS_DIR . '/' . preg_replace('/\.[^.]+$/', '.jpg', $thumbRelPath);
    if (file_exists($thumbPath)) {
        @unlink($thumbPath);
    }
}
