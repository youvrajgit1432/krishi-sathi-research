<?php
/**
 * Krishi Sathi Research System - Edit Observation (Phase 2)
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot edit observations.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$obs = $pdo->prepare("SELECT * FROM research_observations WHERE id = ?");
$obs->execute([$id]);
$obs = $obs->fetch();

if (!$obs) {
    setFlash('error', 'Observation not found.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

// Pre-fetch linked interview status for lock checking
if ($obs['interview_id']) {
    $ivStmt = $pdo->prepare("SELECT interview_status FROM research_interviews WHERE id = ?");
    $ivStmt->execute([$obs['interview_id']]);
    $ivStatus = $ivStmt->fetchColumn();
    if ($ivStatus && normalizeInterviewStatus($ivStatus) === 'approved') {
        $obs['interview_status'] = 'approved';
    }
}

if (!canEditObservation($obs)) {
    if (isRecordLocked($obs)) {
        setFlash('error', 'This observation is linked to an approved interview and is read-only. Contact a Research Lead if changes are required.');
    } else {
        setFlash('error', 'Access denied. You can only edit your own observations.');
    }
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

$farmersStmt = $pdo->prepare("SELECT id, farmer_id, name, district FROM research_farmers WHERE " . activeWhere() . " OR id = ? ORDER BY name ASC");
$farmersStmt->execute([(int) ($obs['farmer_id'] ?: 0)]);
$farmers = $farmersStmt->fetchAll();

$participantsStmt = $pdo->prepare("SELECT id, participant_id, name, district FROM research_participants WHERE " . activeWhere() . " OR id = ? ORDER BY name ASC");
$participantsStmt->execute([(int) ($obs['participant_id'] ?: 0)]);
$participants = $participantsStmt->fetchAll();

$interviews = [];
if ($obs['farmer_id']) {
    $ivStmt = $pdo->prepare("SELECT id, interview_date FROM research_interviews WHERE farmer_id = ? AND " . activeWhere() . " ORDER BY interview_date DESC");
    $ivStmt->execute([$obs['farmer_id']]);
    $interviews = $ivStmt->fetchAll();
}

// Existing photos
$photos = $pdo->prepare("SELECT * FROM research_photos WHERE observation_id = ? AND (is_deleted IS NULL OR is_deleted = 0) ORDER BY created_at");
$photos->execute([$id]);
$photos = $photos->fetchAll();

// Existing media (Package C)
$mediaItems = $pdo->prepare("SELECT * FROM research_media WHERE observation_id = ? AND (is_deleted IS NULL OR is_deleted = 0) ORDER BY created_at");
$mediaItems->execute([$id]);
$mediaItems = $mediaItems->fetchAll();

$errors = [];
$uploadDir = __DIR__ . '/uploads/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Early oversized-post detection (must be BEFORE any POST data access)
    checkOversizedPost(BASE_URL . '/observation-edit.php?id=' . $id);

    $farmer_id         = (int) ($_POST['farmer_id'] ?? 0);
    $participant_id    = (int) ($_POST['participant_id'] ?? 0);
    $interview_id      = (int) ($_POST['interview_id'] ?? 0);
    $observation_date  = $_POST['observation_date'] ?? '';

    $smartphone = $_POST['observed_smartphone'] ?? [];
    $smartphoneOther = trim($_POST['observed_smartphone_other'] ?? '');
    if ($smartphoneOther !== '') $smartphone[] = 'Other: ' . $smartphoneOther;
    $smartphoneStr = implode(', ', $smartphone);

    $records = $_POST['observed_records'] ?? [];
    $recordsOther = trim($_POST['observed_records_other'] ?? '');
    if ($recordsOther !== '') $records[] = 'Other: ' . $recordsOther;
    $recordsStr = implode(', ', $records);

    $tech = $_POST['observed_tech'] ?? [];
    $techOther = trim($_POST['observed_tech_other'] ?? '');
    if ($techOther !== '') $tech[] = 'Other: ' . $techOther;
    $techStr = implode(', ', $tech);

    $farmCondition = $_POST['farm_condition'] ?? '';
    $farmConditionDetails = $_POST['farm_condition_details'] ?? [];
    $fcDetailOther = trim($_POST['farm_condition_detail_other'] ?? '');
    if ($fcDetailOther !== '') $farmConditionDetails[] = 'Other: ' . $fcDetailOther;
    $cropHealth = trim($_POST['crop_health_assessment'] ?? $obs['crop_health_assessment'] ?? '');
    $pestDisease = trim($_POST['pest_disease_presence'] ?? $obs['pest_disease_presence'] ?? '');
    $waterSource = trim($_POST['water_source'] ?? $obs['water_source'] ?? '');

    $researcherNotes = trim($_POST['researcher_notes'] ?? '');
    $generalNotes    = trim($_POST['general_notes'] ?? '');

    if ($farmer_id > 0) {
        $checkFarmer = $pdo->prepare("SELECT COUNT(*) FROM research_farmers WHERE id = ? AND (" . activeWhere() . " OR id = ?)");
        $checkFarmer->execute([$farmer_id, (int) $obs['farmer_id']]);
        if ((int) $checkFarmer->fetchColumn() === 0) $errors[] = 'Selected farmer is deleted or unavailable.';
    } elseif ($participant_id > 0) {
        $checkPart = $pdo->prepare("SELECT COUNT(*) FROM research_participants WHERE id = ? AND (" . activeWhere() . " OR id = ?)");
        $checkPart->execute([$participant_id, (int) $obs['participant_id']]);
        if ((int) $checkPart->fetchColumn() === 0) $errors[] = 'Selected stakeholder is deleted or unavailable.';
    } else {
        $errors[] = 'Please select a farmer or stakeholder.';
    }
    if ($observation_date === '') $errors[] = 'Observation date is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE research_observations SET
                farmer_id=?, participant_id=?, interview_id=?, observation_date=?,
                observed_smartphone_usage=?, observed_record_books=?, observed_technology=?,
                farm_condition=?, farm_condition_details=?, researcher_notes=?, general_notes=?,
                crop_health_assessment=?, pest_disease_presence=?, water_source=?
                WHERE id=?");
            $stmt->execute([
                $farmer_id ?: null, $participant_id ?: null,
                $participant_id ? null : ($interview_id ?: null),
                $observation_date,
                $smartphoneStr, $recordsStr, $techStr,
                $farmCondition,
                is_array($farmConditionDetails) ? implode(', ', $farmConditionDetails) : $farmConditionDetails,
                $researcherNotes, $generalNotes,
                $cropHealth ?: null, $pestDisease ?: null, $waterSource ?: null,
                $id
            ]);

            // Handle photo uploads
            if (!empty($_FILES['photos']['name'][0])) {
                $photoCaption = trim($_POST['photo_caption'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO research_photos (observation_id, filename, original_name, caption) VALUES (?, ?, ?, ?)");
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $total = count($_FILES['photos']['name']);
                for ($i = 0; $i < $total; $i++) {
                    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';
                    if (!is_uploaded_file($tmp)) continue;
                    $mime = $finfo->file($tmp);
                    if (!isset($allowedMimes[$mime])) continue;
                    $ext = $allowedMimes[$mime];
                    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($tmp, $uploadDir . $filename)) continue;
                    $stmt->execute([$id, $filename, $_FILES['photos']['name'][$i], $photoCaption ?: null]);
                }
            }

            // Handle audio/video uploads (Package C)
if (!empty($_FILES['media']['name'][0])) {
    require_once __DIR__ . '/config-media.php';
    $mediaCaption = trim($_POST['media_caption'] ?? '');
    $allowedMediaMimes = array_merge(
        $MEDIA_ALLOWED_MIMES[MEDIA_TYPE_AUDIO],
        $MEDIA_ALLOWED_MIMES[MEDIA_TYPE_VIDEO]
    );
    $maxFileSize = max(
        getMediaMaxSize(MEDIA_TYPE_AUDIO),
        getMediaMaxSize(MEDIA_TYPE_VIDEO)
    );
    $mediaYearDir = date('Y');
    $mediaMonthDir = date('m');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $total = count($_FILES['media']['name']);
    for ($i = 0; $i < $total; $i++) {
        if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES['media']['tmp_name'][$i] ?? '';
        if (!is_uploaded_file($tmp)) continue;
        $size = $_FILES['media']['size'][$i] ?? 0;
        if ($size <= 0 || $size > $maxFileSize) continue;
        $mime = $finfo->file($tmp);
        if (!in_array($mime, $allowedMediaMimes)) continue;
        $mediaType = strpos($mime, 'audio/') === 0 ? MEDIA_TYPE_AUDIO : MEDIA_TYPE_VIDEO;
        $ext = strtolower(pathinfo($_FILES['media']['name'][$i], PATHINFO_EXTENSION));
        $typeDir = getMediaTypeDir($mediaType);
        if (!$typeDir) continue;
        $targetDir = ensureMediaDir($typeDir, $mediaYearDir, $mediaMonthDir);
        if (!$targetDir) continue;
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $targetDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) continue;
        @chmod($dest, 0644);
        $relativePath = $mediaType . 's/' . $mediaYearDir . '/' . $mediaMonthDir . '/' . $filename;
        $mediaStmt = $pdo->prepare("INSERT INTO research_media (observation_id, media_type, file_path, original_name, stored_name, file_size, mime_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $mediaStmt->execute([
            $id, $mediaType, $relativePath, $_FILES['media']['name'][$i],
            $filename, filesize($dest), $mime, $mediaCaption ?: null, currentUserId()
        ]);
    }
}

            $pdo->commit();
            logAudit($pdo, 'observation_updated', 'observation', $id, 'Updated');
            setFlash('success', 'Observation updated.');
            header('Location: ' . BASE_URL . '/observation-view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
// Handle observation delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_observation') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
        exit;
    }
    if (!canDelete()) {
        setFlash('error', 'Only Research Lead or Editor can delete observations.');
        header('Location: ' . BASE_URL . '/observations.php');
        exit;
    }
    // Delete photos from disk
    $oldPhotos = $pdo->prepare("SELECT * FROM research_photos WHERE observation_id = ?");
    $oldPhotos->execute([$id]);
    foreach ($oldPhotos as $p) { @unlink($uploadDir . $p['filename']); }

    // Delete media files from disk (MEDIA-UPLOAD-01)
    $oldMedia = $pdo->prepare("SELECT * FROM research_media WHERE observation_id = ?");
    $oldMedia->execute([$id]);
    foreach ($oldMedia as $m) {
        $mediaPath = $m['file_path'] ?? '';
        // New convention: path relative to survey/uploads/
        $fullPath = __DIR__ . '/uploads/' . $mediaPath;
        if (file_exists($fullPath)) { @unlink($fullPath); continue; }
        // Legacy convention: full path from project root
        $legacyPath = __DIR__ . '/../../' . $mediaPath;
        if (file_exists($legacyPath)) { @unlink($legacyPath); }
    }

    softDeleteRecord($pdo, 'research_observations', $id);
    logAudit($pdo, 'observation_deleted', 'observation', $id, 'Deleted (soft)');
    setFlash('success', 'Observation deleted.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

// Handle photo delete (POST) — soft delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_photo') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
        exit;
    }
    $photoId = (int) ($_POST['photo_id'] ?? 0);
    $photo = $pdo->prepare("SELECT * FROM research_photos WHERE id = ? AND observation_id = ?");
    $photo->execute([$photoId, $id]);
    $photo = $photo->fetch();
    if ($photo) {
        // Contributors can only delete their own photos
        if (isContributor() && (int) ($photo['uploaded_by'] ?? 0) !== currentUserId()) {
            setFlash('error', 'You can only delete your own photos.');
            header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
            exit;
        }
        // Soft delete — keep file, mark as deleted
        $pdo->prepare("UPDATE research_photos SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([currentUserId(), $photoId]);
        setFlash('success', 'Photo moved to trash.');
    }
    header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
    exit;
}

// Handle media delete (POST) — soft delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_media') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
        exit;
    }
    $mediaId = (int) ($_POST['media_id'] ?? 0);
    $media = $pdo->prepare("SELECT * FROM research_media WHERE id = ? AND observation_id = ?");
    $media->execute([$mediaId, $id]);
    $media = $media->fetch();
    if ($media) {
        // Contributors can delete media from any non-locked record
        // Soft delete — keep file, mark as deleted
        $pdo->prepare("UPDATE research_media SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([currentUserId(), $mediaId]);
        setFlash('success', 'Media moved to trash.');
    }
    header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
    exit;
}

// Handle consent update (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_consent') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
        exit;
    }
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $source = $_POST['source'] ?? '';
    $newConsent = $_POST['consent'] ?? 'research';
    $validConsent = ['research', 'internal', 'none'];
    if (!in_array($newConsent, $validConsent, true)) $newConsent = 'research';
    if ($itemId > 0) {
        if ($source === 'photos') {
            $pdo->prepare("UPDATE research_photos SET consent = ? WHERE id = ? AND observation_id = ?")->execute([$newConsent, $itemId, $id]);
        } elseif ($source === 'media') {
            $pdo->prepare("UPDATE research_media SET consent = ? WHERE id = ? AND observation_id = ?")->execute([$newConsent, $itemId, $id]);
        }
        setFlash('success', 'Consent updated.');
    }
    header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
    exit;
}

// Handle caption update (POST) — Package B
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_caption') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
        exit;
    }
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $source = $_POST['source'] ?? '';
    $newCaption = trim($_POST['caption'] ?? '');
    if ($itemId > 0) {
        if ($source === 'photos') {
            $pdo->prepare("UPDATE research_photos SET caption = ? WHERE id = ? AND observation_id = ?")->execute([$newCaption ?: null, $itemId, $id]);
        } elseif ($source === 'media') {
            $pdo->prepare("UPDATE research_media SET caption = ? WHERE id = ? AND observation_id = ?")->execute([$newCaption ?: null, $itemId, $id]);
        }
        setFlash('success', 'Caption updated.');
    }
    header('Location: ' . BASE_URL . '/observation-edit.php?id=' . $id);
    exit;
}

// Parse saved values
$savedSmartphone = $obs['observed_smartphone_usage'] ? array_map('trim', explode(',', $obs['observed_smartphone_usage'])) : [];
$savedRecords = $obs['observed_record_books'] ? array_map('trim', explode(',', $obs['observed_record_books'])) : [];
$savedTech = $obs['observed_technology'] ? array_map('trim', explode(',', $obs['observed_technology'])) : [];
$savedFCD = $obs['farm_condition_details'] ? array_map('trim', explode(',', $obs['farm_condition_details'])) : [];

$formSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST');
$selSmartphone = $formSubmitted ? ($_POST['observed_smartphone'] ?? []) : $savedSmartphone;
$selRecords    = $formSubmitted ? ($_POST['observed_records'] ?? []) : $savedRecords;
$selTech       = $formSubmitted ? ($_POST['observed_tech'] ?? []) : $savedTech;$selFCD = $formSubmitted ? ($_POST['farm_condition_details'] ?? []) : $savedFCD;

// ─── Option Lists (loaded from centralized config) ─────────────
$observationOptions = require __DIR__ . '/config-observation-options.php';
$smartphoneOptions   = $observationOptions['smartphone_options'];
$recordOptions       = $observationOptions['record_options'];
$techOptions         = $observationOptions['tech_options'];
$farmConditionOpts   = $observationOptions['farm_condition_options'];
$farmConditionDetails = $observationOptions['farm_condition_details'];

$pageTitle = 'Edit Observation - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-pencil"></i> Edit Observation #<?php echo $id; ?></h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Observation Info</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small">Subject</label>
                                    <div class="btn-group btn-group-sm mb-1 w-100" role="group">
                                        <input type="radio" class="btn-check" name="entity_type" id="editEntityFarmer" value="farmer" autocomplete="off"
                                               onchange="editToggleEntityType()" <?php echo $obs['farmer_id'] ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary" for="editEntityFarmer"><i class="bi bi-person"></i> Farmer</label>
                                        <input type="radio" class="btn-check" name="entity_type" id="editEntityParticipant" value="participant" autocomplete="off"
                                               onchange="editToggleEntityType()" <?php echo $obs['participant_id'] ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-secondary" for="editEntityParticipant"><i class="bi bi-person-badge"></i> Stakeholder</label>
                                    </div>
                                    <select name="farmer_id" id="editFarmerSelect" class="form-select form-select-sm"
                                            <?php echo $obs['farmer_id'] ? 'required' : 'style="display:none"'; ?>>
                                        <option value="">-- Select Farmer --</option>
                                        <?php foreach ($farmers as $f): ?>
                                            <option value="<?php echo $f['id']; ?>" <?php echo (int) ($_POST['farmer_id'] ?? $obs['farmer_id']) === (int) $f['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['name'] . ' (' . $f['farmer_id'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="participant_id" id="editParticipantSelect" class="form-select form-select-sm"
                                            <?php echo $obs['participant_id'] ? 'required' : 'style="display:none"'; ?>>
                                        <option value="">-- Select Stakeholder --</option>
                                        <?php foreach ($participants as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo (int) ($_POST['participant_id'] ?? $obs['participant_id']) === (int) $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name'] . ' (' . $p['participant_id'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Date</label>
                                    <input type="date" name="observation_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['observation_date'] ?? $obs['observation_date']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Linked Interview</label>
                                    <select name="interview_id" id="editInterviewSelect" class="form-select form-select-sm">
                                        <option value="">-- None --</option>
                                        <?php foreach ($interviews as $iv): ?>
                                            <option value="<?php echo $iv['id']; ?>" <?php echo (int) ($_POST['interview_id'] ?? $obs['interview_id']) === (int) $iv['id'] ? 'selected' : ''; ?>>ID <?php echo $iv['id']; ?> (<?php echo $iv['interview_date']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Smartphone Usage -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Smartphone Usage</div>
                        <div class="card-body py-2">
                            <div class="row"><?php foreach ($smartphoneOptions as $opt): ?><?php if ($opt === 'Other'): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_smartphone[]" value="Other" id="eos_other" onchange="document.getElementById('esmartphoneOtherInput').style.display=this.checked?'block':'none'" <?php echo in_array('Other', $selSmartphone) ? 'checked' : ''; ?>><label class="form-check-label small" for="eos_other">Other</label></div><div id="esmartphoneOtherInput" style="display:<?php echo in_array('Other', $selSmartphone) ? 'block' : 'none'; ?>"><input type="text" name="observed_smartphone_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars($_POST['observed_smartphone_other'] ?? ''); ?>"></div></div><?php else: ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_smartphone[]" value="<?php echo $opt; ?>" id="eos_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selSmartphone) ? 'checked' : ''; ?>><label class="form-check-label small" for="eos_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div><?php endif; ?><?php endforeach; ?></div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Record Books</div>
                        <div class="card-body py-2">
                            <div class="row"><?php foreach ($recordOptions as $opt): ?><?php if ($opt === 'Other'): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_records[]" value="Other" id="eor_other" onchange="document.getElementById('erecordsOtherInput').style.display=this.checked?'block':'none'" <?php echo in_array('Other', $selRecords) ? 'checked' : ''; ?>><label class="form-check-label small" for="eor_other">Other</label></div><div id="erecordsOtherInput" style="display:<?php echo in_array('Other', $selRecords) ? 'block' : 'none'; ?>"><input type="text" name="observed_records_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars($_POST['observed_records_other'] ?? ''); ?>"></div></div><?php else: ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_records[]" value="<?php echo $opt; ?>" id="eor_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selRecords) ? 'checked' : ''; ?>><label class="form-check-label small" for="eor_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div><?php endif; ?><?php endforeach; ?></div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Technology</div>
                        <div class="card-body py-2">
                            <div class="row"><?php foreach ($techOptions as $opt): ?><?php if ($opt === 'Other'): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_tech[]" value="Other" id="eot_other" onchange="document.getElementById('etechOtherInput').style.display=this.checked?'block':'none'" <?php echo in_array('Other', $selTech) ? 'checked' : ''; ?>><label class="form-check-label small" for="eot_other">Other</label></div><div id="etechOtherInput" style="display:<?php echo in_array('Other', $selTech) ? 'block' : 'none'; ?>"><input type="text" name="observed_tech_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars($_POST['observed_tech_other'] ?? ''); ?>"></div></div><?php else: ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_tech[]" value="<?php echo $opt; ?>" id="eot_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selTech) ? 'checked' : ''; ?>><label class="form-check-label small" for="eot_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div><?php endif; ?><?php endforeach; ?></div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Farm Condition &amp; Crop Health</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small">Crop Health</label>
                                    <select name="crop_health_assessment" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $chVal = $_POST['crop_health_assessment'] ?? $obs['crop_health_assessment'] ?? ''; ?>
                                        <option value="excellent" <?php echo $chVal === 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                        <option value="good" <?php echo $chVal === 'good' ? 'selected' : ''; ?>>Good</option>
                                        <option value="fair" <?php echo $chVal === 'fair' ? 'selected' : ''; ?>>Fair</option>
                                        <option value="poor" <?php echo $chVal === 'poor' ? 'selected' : ''; ?>>Poor</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Pest/Disease</label>
                                    <select name="pest_disease_presence" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $pdVal = $_POST['pest_disease_presence'] ?? $obs['pest_disease_presence'] ?? ''; ?>
                                        <option value="yes" <?php echo $pdVal === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo $pdVal === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Water Source</label>
                                    <select name="water_source" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $wsVal = $_POST['water_source'] ?? $obs['water_source'] ?? ''; ?>
                                        <option value="rainfed" <?php echo $wsVal === 'rainfed' ? 'selected' : ''; ?>>Rainfed</option>
                                        <option value="irrigation" <?php echo $wsVal === 'irrigation' ? 'selected' : ''; ?>>Irrigation</option>
                                        <option value="both" <?php echo $wsVal === 'both' ? 'selected' : ''; ?>>Both</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex gap-3">
                                        <?php $fcVal = $_POST['farm_condition'] ?? $obs['farm_condition']; ?>
                                        <?php foreach ($farmConditionOpts as $co): ?>
                                            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="farm_condition" value="<?php echo $co; ?>" id="efc_<?php echo $co; ?>" <?php echo $fcVal === $co ? 'checked' : ''; ?>><label class="form-check-label small" for="efc_<?php echo $co; ?>"><?php echo $co; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="row"><?php foreach ($farmConditionDetails as $fd): ?><?php if ($fd === 'Other'): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="farm_condition_details[]" value="Other" id="efcd_other" onchange="document.getElementById('efcdOtherInput').style.display=this.checked?'block':'none'" <?php echo in_array('Other', $selFCD) ? 'checked' : ''; ?>><label class="form-check-label small" for="efcd_other">Other</label></div><div id="efcdOtherInput" style="display:<?php echo in_array('Other', $selFCD) ? 'block' : 'none'; ?>"><input type="text" name="farm_condition_detail_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars($_POST['farm_condition_detail_other'] ?? ''); ?>"></div></div><?php else: ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="farm_condition_details[]" value="<?php echo $fd; ?>" id="efcd_<?php echo slugify($fd); ?>" <?php echo in_array($fd, $selFCD) ? 'checked' : ''; ?>><label class="form-check-label small" for="efcd_<?php echo slugify($fd); ?>"><?php echo $fd; ?></label></div></div><?php endif; ?><?php endforeach; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Notes <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label small">Researcher Notes</label><textarea name="researcher_notes" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['researcher_notes'] ?? $obs['researcher_notes']); ?></textarea></div>
                                <div class="col-md-6"><label class="form-label small">General Notes</label><textarea name="general_notes" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['general_notes'] ?? $obs['general_notes']); ?></textarea></div>
                            </div>
                        </div>
                    </div>

                </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Add Photos</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <input type="file" name="photos[]" class="form-control form-control-sm" multiple accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="photo_caption" class="form-control form-control-sm" placeholder="Caption (applies to all)">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-mic"></i> Add Audio / Video</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <input type="file" name="media[]" class="form-control form-control-sm" multiple accept="audio/*,video/*">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="media_caption" class="form-control form-control-sm" placeholder="Caption (applies to all)">
                                </div>
                            </div>
                            <div class="form-text">Supports MP3, WAV, OGG, MP4, WebM. Max 100MB each.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg"></i> Update</button>
                        <a href="<?php echo BASE_URL; ?>/observation-view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Current Photos with caption edit + delete (standalone forms, NOT nested in main form) -->
<?php if (count($photos) > 0): ?>
<div class="card bg-light mb-3 border-0">
    <div class="card-header bg-transparent fw-semibold py-2">Current Photos</div>
    <div class="card-body py-2">
        <div class="row g-2">
            <?php foreach ($photos as $p): ?>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <div class="position-relative" style="width:100%;">
                            <img src="<?php echo BASE_URL; ?>/uploads/<?php echo htmlspecialchars($p['filename']); ?>" class="img-thumbnail" style="height:100px;width:100%;object-fit:cover;">
                            <div class="d-flex gap-1" style="position:absolute;top:0;right:0;margin:0;">
                            <form method="post" class="d-inline" onsubmit="return confirm('Move this photo to trash?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_photo">
                                <input type="hidden" name="photo_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger py-0 px-1" style="font-size:0.7rem;">&times;</button>
                            </form>
                        </div>
                        </div>
                        <form method="post" class="mt-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_caption">
                            <input type="hidden" name="source" value="photos">
                            <input type="hidden" name="item_id" value="<?php echo $p['id']; ?>">
                            <div class="input-group input-group-sm mb-1">
                                <input type="text" name="caption" class="form-control form-control-sm" value="<?php echo htmlspecialchars($p['caption'] ?? ''); ?>" placeholder="Caption">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </div>
                        </form>
                        <form method="post" class="mt-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_consent">
                            <input type="hidden" name="source" value="photos">
                            <input type="hidden" name="item_id" value="<?php echo $p['id']; ?>">
                            <div class="input-group input-group-sm">
                                <select name="consent" class="form-select form-select-sm">
                                    <option value="research" <?php echo ($p['consent'] ?? 'research') === 'research' ? 'selected' : ''; ?>>Research</option>
                                    <option value="internal" <?php echo ($p['consent'] ?? '') === 'internal' ? 'selected' : ''; ?>>Internal</option>
                                    <option value="none" <?php echo ($p['consent'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-info">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (count($mediaItems) > 0): ?>
<div class="card bg-light mb-3 border-0">
    <div class="card-header bg-transparent fw-semibold py-2">Current Audio / Video</div>
    <div class="card-body py-2">
        <div class="row g-2">
            <?php foreach ($mediaItems as $m): ?>
                <div class="col-md-4">
                    <div class="border rounded p-2 position-relative">
                        <div class="d-flex gap-1" style="position:absolute;top:4px;right:4px;margin:0;z-index:1;">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.7rem;" title="Replace file"
                                    onclick="replaceMediaItem(<?php echo (int)$m['id']; ?>)">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Move this media to trash?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_media">
                                <input type="hidden" name="media_id" value="<?php echo $m['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger py-0 px-1">&times;</button>
                            </form>
                        </div>
                        <?php if ($m['media_type'] === 'audio'): ?>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-file-earmark-music fs-4"></i>
                                <small class="text-truncate"><?php echo htmlspecialchars($m['original_name']); ?></small>
                            </div>
                            <audio controls class="w-100" style="height:32px;">
                                <source src="<?php echo BASE_URL; ?>/uploads/<?php echo htmlspecialchars($m['file_path'] ?? $m['filename']); ?>" type="<?php echo htmlspecialchars($m['mime_type']); ?>">
                            </audio>
                        <?php elseif ($m['media_type'] === 'video'): ?>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-file-earmark-play fs-4"></i>
                                <small class="text-truncate"><?php echo htmlspecialchars($m['original_name']); ?></small>
                            </div>
                            <video controls class="w-100" style="max-height:120px;">
                                <source src="<?php echo BASE_URL; ?>/uploads/<?php echo htmlspecialchars($m['file_path'] ?? $m['filename']); ?>" type="<?php echo htmlspecialchars($m['mime_type']); ?>">
                            </video>
                        <?php endif; ?>
                        <form method="post" class="mt-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_caption">
                            <input type="hidden" name="source" value="media">
                            <input type="hidden" name="item_id" value="<?php echo $m['id']; ?>">
                            <div class="input-group input-group-sm mb-1">
                                <input type="text" name="caption" class="form-control form-control-sm" value="<?php echo htmlspecialchars($m['caption'] ?? ''); ?>" placeholder="Caption">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </div>
                        </form>
                        <form method="post" class="mt-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_consent">
                            <input type="hidden" name="source" value="media">
                            <input type="hidden" name="item_id" value="<?php echo $m['id']; ?>">
                            <div class="input-group input-group-sm">
                                <select name="consent" class="form-select form-select-sm">
                                    <option value="research" <?php echo ($m['consent'] ?? 'research') === 'research' ? 'selected' : ''; ?>>Research</option>
                                    <option value="internal" <?php echo ($m['consent'] ?? '') === 'internal' ? 'selected' : ''; ?>>Internal</option>
                                    <option value="none" <?php echo ($m['consent'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-info">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function editToggleEntityType() {
    var isFarmer = document.getElementById('editEntityFarmer').checked;
    document.getElementById('editFarmerSelect').style.display = isFarmer ? '' : 'none';
    document.getElementById('editFarmerSelect').required = isFarmer;
    document.getElementById('editParticipantSelect').style.display = isFarmer ? 'none' : '';
    document.getElementById('editParticipantSelect').required = !isFarmer;
    if (!isFarmer) {
        document.getElementById('editInterviewSelect').innerHTML = '<option value="">-- None --</option>';
    }
}

function replaceMediaItem(itemId) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp,image/gif,image/bmp,audio/mpeg,audio/wav,audio/ogg,audio/aac,audio/x-m4a,video/mp4,video/quicktime,video/webm,video/x-msvideo';
    input.onchange = function() {
        if (!input.files.length) return;
        var file = input.files[0];
        if (!confirm('Replace this media file with ' + file.name + '? The old file will be permanently deleted.')) return;
        var formData = new FormData();
        formData.append('file', file);
        formData.append('media_id', itemId);
        fetch('<?php echo BASE_URL; ?>/api/replace-media.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { location.reload(); }
                else { alert('Replace failed: ' + (data.error || 'Unknown error')); }
            })
            .catch(function() { alert('Replace failed. Check connection.'); });
    };
    input.click();
}
</script>

<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
<?php
function slugify($text) { return preg_replace('/[^a-zA-Z0-9]/', '_', $text); }
include __DIR__ . '/footer.php'; ?>
