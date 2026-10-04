<?php
/**
 * Krishi Sathi Research System - Media Gallery Component
 * DEV-CHANGE-PACKAGE-06: Enhanced with captions, download, metadata.
 *
 * Usage:
 *   $mediaItems = buildMediaGallery($pdo, $interviewId, $observationId, $participantId);
 *   include __DIR__ . '/inc/media-gallery.php';
 *
 * Expected $mediaItems: array of normalized media items with keys:
 *   id, type (image|audio|video), url, thumbnail_url, name, size, mime,
 *   caption, consent, duration, created_at, source (photos|media), source_id
 */

if (empty($mediaItems)) {
    $mediaItems = [];
}

$totalImages = 0;
$totalAudio = 0;
$totalVideo = 0;
foreach ($mediaItems as $m) {
    if ($m['type'] === 'image') $totalImages++;
    elseif ($m['type'] === 'audio') $totalAudio++;
    elseif ($m['type'] === 'video') $totalVideo++;
}
$totalAll = count($mediaItems);
?>

<?php if ($totalAll > 0): ?>
<div class="row g-2">
    <?php foreach ($mediaItems as $m): ?>
        <?php
        $isImage = $m['type'] === 'image';
        $isAudio = $m['type'] === 'audio';
        $isVideo = $m['type'] === 'video';
        $consentLabel = $m['consent'] ?? 'research';
        $consentBadges = ['research' => 'success', 'internal' => 'warning text-dark', 'none' => 'secondary'];
        $consentTexts = ['research' => 'Research Use', 'internal' => 'Internal Only', 'none' => 'No Permission'];
        $badgeClass = $consentBadges[$consentLabel] ?? 'secondary';
        $badgeText = $consentTexts[$consentLabel] ?? 'Research Use';
        $mediaUrl = $m['url'];
        $thumbUrl = $m['thumbnail_url'] ?? '';
        $mediaName = $m['name'];
        $caption = $m['caption'] ?? '';
        $mediaSize = $m['size'] ? round($m['size'] / 1024 / 1024, 1) . ' MB' : '';
        $mediaSizeBytes = $m['size'] ?? 0;
        $mediaDate = $m['created_at'] ? date('M j, Y', strtotime($m['created_at'])) : '';
        $mediaTypeLabel = $isImage ? 'Image' : ($isVideo ? 'Video' : 'Audio');
        $downloadName = $mediaName ?: 'download';
        ?>
        <?php if ($isImage): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="media-item">
                <a href="<?php echo $mediaUrl; ?>" class="media-thumb-link" onclick="openMediaFullscreen('<?php echo str_replace("'", "\\'", $mediaUrl); ?>', '<?php echo str_replace("'", "\\'", htmlspecialchars($caption)); ?>'); return false;">
                    <div class="media-thumb" style="background:#f0f0f0;border-radius:6px;overflow:hidden;position:relative;">
                        <img src="<?php echo $thumbUrl ?: $mediaUrl; ?>"
                             class="w-100"
                             style="height:150px;object-fit:cover;display:block;"
                             alt="<?php echo htmlspecialchars($mediaName); ?>"
                             loading="lazy"
                             onerror="this.style.display='none';var n=this.nextElementSibling;if(n&&n.classList.contains('media-fallback'))n.style.display='flex'">
                        <div class="media-fallback d-flex align-items-center justify-content-center" style="display:none;height:150px;background:#f8f9fa;color:#adb5bd;font-size:0.75rem;">
                            <i class="bi bi-image me-1"></i>No preview
                        </div>
                        <div class="media-type-badge" style="position:absolute;top:4px;left:4px;">
                            <span class="badge bg-dark bg-opacity-50" style="font-size:0.6rem;"><i class="bi bi-camera"></i></span>
                        </div>
                    </div>
                </a>
                <div class="mt-1 px-1">
                    <?php if ($caption): ?>
                        <div style="font-size:0.7rem;color:#495057;line-height:1.3;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;"><?php echo htmlspecialchars($caption); ?></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center gap-1 mt-1">
                        <span class="badge bg-<?php echo $badgeClass; ?>" style="font-size:0.6rem;"><?php echo $badgeText; ?></span>
                        <div class="d-flex gap-1">
                            <a href="<?php echo $mediaUrl; ?>" download="<?php echo htmlspecialchars($downloadName); ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.6rem;line-height:1.2;" title="Download"><i class="bi bi-download"></i></a>
                            <?php if (($canDeleteMedia ?? false) || ($isLead ?? false)): ?>
                                <button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:0.6rem;line-height:1.2;"
                                        onclick="deleteMediaItem(<?php echo (int)$m['source_id']; ?>, '<?php echo $m['source']; ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size:0.6rem;color:#6c757d;">
                        <?php if ($mediaSize): ?><?php echo $mediaSize; ?> &middot; <?php endif; ?>
                        <?php echo $mediaDate; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($isVideo): ?>
        <div class="col-md-6">
            <div class="media-item border rounded p-2">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-file-earmark-play fs-4"></i>
                    <div class="flex-grow-1 min-width-0">
                        <strong class="small" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($mediaName); ?></strong>
                        <span class="text-muted" style="font-size:0.65rem;">
                            Video
                            <?php if ($mediaSize): ?> &middot; <?php echo $mediaSize; ?><?php endif; ?>
                            <?php if ($m['duration']): ?> &middot; <?php echo htmlspecialchars($m['duration']); ?><?php endif; ?>
                            <?php if ($mediaDate): ?> &middot; <?php echo $mediaDate; ?><?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="<?php echo $mediaUrl; ?>" download="<?php echo htmlspecialchars($downloadName); ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.65rem;" title="Download"><i class="bi bi-download"></i></a>
                        <?php if (($canDeleteMedia ?? false) || ($isLead ?? false)): ?>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:0.65rem;"
                                    onclick="deleteMediaItem(<?php echo (int)$m['source_id']; ?>, '<?php echo $m['source']; ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($caption): ?>
                    <div style="font-size:0.75rem;color:#495057;line-height:1.3;margin-bottom:4px;"><?php echo htmlspecialchars($caption); ?></div>
                <?php endif; ?>
                <?php if ($thumbUrl): ?>
                <div class="position-relative mb-1" style="border-radius:4px;overflow:hidden;background:#000;">
                    <video controls class="w-100" style="max-height:200px;display:block;" preload="metadata"
                           poster="<?php echo $thumbUrl; ?>">
                        <source src="<?php echo $mediaUrl; ?>" type="<?php echo htmlspecialchars($m['mime']); ?>">
                    </video>
                </div>
                <?php else: ?>
                <video controls class="w-100" style="max-height:200px;" preload="metadata">
                    <source src="<?php echo $mediaUrl; ?>" type="<?php echo htmlspecialchars($m['mime']); ?>">
                </video>
                <?php endif; ?>
                <div class="mt-1 d-flex justify-content-between align-items-center">
                    <span class="badge bg-<?php echo $badgeClass; ?>" style="font-size:0.6rem;"><?php echo $badgeText; ?></span>
                </div>
            </div>
        </div>

        <?php elseif ($isAudio): ?>
        <div class="col-md-6">
            <div class="media-item border rounded p-2">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-file-earmark-music fs-4"></i>
                    <div class="flex-grow-1 min-width-0">
                        <strong class="small" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($mediaName); ?></strong>
                        <span class="text-muted" style="font-size:0.65rem;">
                            Audio
                            <?php if ($mediaSize): ?> &middot; <?php echo $mediaSize; ?><?php endif; ?>
                            <?php if ($m['duration']): ?> &middot; <?php echo htmlspecialchars($m['duration']); ?><?php endif; ?>
                            <?php if ($mediaDate): ?> &middot; <?php echo $mediaDate; ?><?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="<?php echo $mediaUrl; ?>" download="<?php echo htmlspecialchars($downloadName); ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.65rem;" title="Download"><i class="bi bi-download"></i></a>
                        <?php if (($canDeleteMedia ?? false) || ($isLead ?? false)): ?>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:0.65rem;"
                                    onclick="deleteMediaItem(<?php echo (int)$m['source_id']; ?>, '<?php echo $m['source']; ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($caption): ?>
                    <div style="font-size:0.75rem;color:#495057;line-height:1.3;margin-bottom:4px;"><?php echo htmlspecialchars($caption); ?></div>
                <?php endif; ?>
                <audio controls class="w-100" style="height:36px;" preload="metadata">
                    <source src="<?php echo $mediaUrl; ?>" type="<?php echo htmlspecialchars($m['mime']); ?>">
                </audio>
                <div class="mt-1 d-flex justify-content-between align-items-center">
                    <span class="badge bg-<?php echo $badgeClass; ?>" style="font-size:0.6rem;"><?php echo $badgeText; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-muted small mb-3">No media attached.</p>
<?php endif; ?>

<!-- Fullscreen Image Modal -->
<div class="modal fade" id="mediaFullscreenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-header border-0 pb-0 justify-content-between">
                <div class="text-white small" id="fullscreenCaption"></div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-0">
                <img id="fullscreenImage" src="" class="img-fluid" style="max-height:85vh;margin:0 auto;" alt="Fullscreen media">
            </div>
        </div>
    </div>
</div>

<script>
function openMediaFullscreen(url, caption) {
    var modal = document.getElementById('mediaFullscreenModal');
    var img = document.getElementById('fullscreenImage');
    var cap = document.getElementById('fullscreenCaption');
    img.src = url;
    cap.textContent = caption || '';
    var bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

function deleteMediaItem(id, source) {
    if (!confirm('Move this media to trash?')) return;
    var apiUrl = source === 'photos'
        ? '<?php echo BASE_URL; ?>/api/delete-photo.php'
        : '<?php echo BASE_URL; ?>/api/delete-media.php';
    var formData = new FormData();
    if (source === 'photos') {
        formData.append('photo_id', id);
    } else {
        formData.append('media_id', id);
    }
    fetch(apiUrl, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { location.reload(); }
            else { alert('Delete failed: ' + (data.error || 'Unknown error')); }
        })
        .catch(function() { alert('Delete failed. Check connection.'); });
}
</script>
