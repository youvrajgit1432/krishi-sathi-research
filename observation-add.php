<?php
/**
 * Krishi Sathi Research System - Add Observation (Phase 2)
 * Field observations with checkbox-first design and optional photo upload.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot create observations.');
    header('Location: ' . BASE_URL . '/observations.php');
    exit;
}

$pdo = getDB();
$errors = [];

$farmerWhere = activeWhere();
if (isContributor()) {
    $farmerWhere .= " AND (approval_status IS NULL OR approval_status != 'approved')";
}
$farmersStmt = $pdo->prepare("SELECT id, farmer_id, name, district FROM research_farmers WHERE {$farmerWhere} ORDER BY created_at DESC");
$farmersStmt->execute();
$farmers = $farmersStmt->fetchAll();

$participantWhere = activeWhere();
if (isContributor()) {
    $participantWhere .= " AND (approval_status IS NULL OR approval_status != 'approved')";
}
$participantsStmt = $pdo->prepare("SELECT id, participant_id, name, district FROM research_participants WHERE {$participantWhere} ORDER BY created_at DESC");
$participantsStmt->execute();
$participants = $participantsStmt->fetchAll();

$selectedFarmerId = (int) ($_GET['farmer_id'] ?? 0);
$selectedParticipantId = (int) ($_GET['participant_id'] ?? 0);
$selectedInterviewId = (int) ($_GET['interview_id'] ?? 0);
$entityType = $selectedFarmerId ? 'farmer' : ($selectedParticipantId ? 'participant' : 'farmer');

// Upload dir
$uploadDir = __DIR__ . '/uploads/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Early oversized-post detection (must be BEFORE CSRF check)
    checkOversizedPost(BASE_URL . '/observation-add.php', ['farmer_id', 'interview_id']);

    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }
    $farmer_id         = (int) ($_POST['farmer_id'] ?? 0);
    $participant_id    = (int) ($_POST['participant_id'] ?? 0);
    $interview_id      = (int) ($_POST['interview_id'] ?? 0);
    $observation_date  = $_POST['observation_date'] ?? date('Y-m-d');

    // Observed Smartphone Usage (checkboxes)
    $smartphone = $_POST['observed_smartphone'] ?? [];
    $smartphoneOther = trim($_POST['observed_smartphone_other'] ?? '');
    if ($smartphoneOther !== '') $smartphone[] = 'Other: ' . $smartphoneOther;
    $smartphoneStr = implode(', ', $smartphone);

    // Observed Record Books (checkboxes)
    $records = $_POST['observed_records'] ?? [];
    $recordsOther = trim($_POST['observed_records_other'] ?? '');
    if ($recordsOther !== '') $records[] = 'Other: ' . $recordsOther;
    $recordsStr = implode(', ', $records);

    // Observed Technology (checkboxes)
    $tech = $_POST['observed_tech'] ?? [];
    $techOther = trim($_POST['observed_tech_other'] ?? '');
    if ($techOther !== '') $tech[] = 'Other: ' . $techOther;
    $techStr = implode(', ', $tech);

    // Farm condition (radio)
    $farmCondition = $_POST['farm_condition'] ?? '';
    $cropHealth = trim($_POST['crop_health_assessment'] ?? '');
    $pestDisease = trim($_POST['pest_disease_presence'] ?? '');
    $waterSource = trim($_POST['water_source'] ?? '');
    $farmConditionDetails = $_POST['farm_condition_details'] ?? [];

    // Researcher notes & General notes (free text, optional)
    $researcherNotes = trim($_POST['researcher_notes'] ?? '');
    $generalNotes    = trim($_POST['general_notes'] ?? '');

    if ($farmer_id > 0) {
        $checkFarmer = $pdo->prepare("SELECT COUNT(*) FROM research_farmers WHERE id = ? AND " . activeWhere());
        $checkFarmer->execute([$farmer_id]);
        if ((int) $checkFarmer->fetchColumn() === 0) $errors[] = 'Selected farmer is deleted or unavailable.';
    } elseif ($participant_id > 0) {
        $checkPart = $pdo->prepare("SELECT COUNT(*) FROM research_participants WHERE id = ? AND " . activeWhere());
        $checkPart->execute([$participant_id]);
        if ((int) $checkPart->fetchColumn() === 0) $errors[] = 'Selected stakeholder is deleted or unavailable.';
    } else {
        $errors[] = 'Please select a farmer or stakeholder.';
    }
    if ($observation_date === '') $errors[] = 'Observation date is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO research_observations
                (farmer_id, participant_id, interview_id, observer_id, observation_date,
                 observed_smartphone_usage, observed_record_books, observed_technology,
                 farm_condition, farm_condition_details, researcher_notes, general_notes,
                 crop_health_assessment, pest_disease_presence, water_source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $farmer_id ?: null, $participant_id ?: null, $interview_id ?: null, currentUserId(), $observation_date,
                $smartphoneStr, $recordsStr, $techStr,
                $farmCondition,
                is_array($farmConditionDetails) ? implode(', ', $farmConditionDetails) : $farmConditionDetails,
                $researcherNotes, $generalNotes,
                $cropHealth ?: null, $pestDisease ?: null, $waterSource ?: null
            ]);
            $observationId = $pdo->lastInsertId();

            // Handle photo uploads (hardened)
            if (!empty($_FILES['photos']['name'][0])) {
                $photoCaption = trim($_POST['photo_caption'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO research_photos (observation_id, filename, original_name, caption) VALUES (?, ?, ?, ?)");
                $total = count($_FILES['photos']['name']);
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $maxSize = 5 * 1024 * 1024; // 5 MB
                $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                for ($i = 0; $i < $total; $i++) {
                    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';
                    if (!is_uploaded_file($tmp)) continue;
                    $size = $_FILES['photos']['size'][$i] ?? 0;
                    if ($size <= 0 || $size > $maxSize) continue;
                    $mime = $finfo->file($tmp);
                    if (!isset($allowedMimes[$mime])) continue;
                    $imgInfo = @getimagesize($tmp);
                    if ($imgInfo === false) continue;
                    $ext = $allowedMimes[$mime];
                    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $dest = $uploadDir . $filename;
                    if (!move_uploaded_file($tmp, $dest)) continue;
                    @chmod($dest, 0644);
                    $origName = $_FILES['photos']['name'][$i] ?? null;
                    $stmt->execute([$observationId, $filename, $origName, $photoCaption ?: null]);
                }
            }

            // Handle audio/video uploads (MEDIA-UPLOAD-01)
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
                    $stmt = $pdo->prepare("INSERT INTO research_media (observation_id, media_type, file_path, original_name, stored_name, file_size, mime_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $observationId,
                        $mediaType,
                        $relativePath,
                        $_FILES['media']['name'][$i],
                        $filename,
                        filesize($dest),
                        $mime,
                        $mediaCaption ?: null,
                        currentUserId()
                    ]);
                }
            }

            $pdo->commit();
            $entityLabel = $farmer_id ? 'Farmer #' . $farmer_id : 'Stakeholder #' . $participant_id;
            logAudit($pdo, 'observation_created', 'observation', $observationId,
                     'Created observation for ' . $entityLabel .
                     ($interview_id ? ', linked to Interview #' . $interview_id : ''));
            setFlash('success', 'Observation recorded successfully for ' . $entityLabel . '!');
            $redirectId = $farmer_id ? 'farmer_id=' . $farmer_id : 'participant_id=' . $participant_id;
            header('Location: ' . BASE_URL . '/consent-add.php?' . $redirectId . '&interview_id=' . $interview_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    if (!empty($errors)) saveOld($_POST);
}

// ─── Option Lists (loaded from centralized config) ─────────────
$observationOptions = require __DIR__ . '/config-observation-options.php';
$smartphoneOptions   = $observationOptions['smartphone_options'];
$recordOptions       = $observationOptions['record_options'];
$techOptions         = $observationOptions['tech_options'];
$farmConditionOpts   = $observationOptions['farm_condition_options'];
$farmConditionDetails = $observationOptions['farm_condition_details'];

// ─── Preload all interviews for client-side filtering ───────────
$allInterviewsStmt = $pdo->prepare("SELECT id, farmer_id, interview_date FROM research_interviews WHERE " . activeWhere() . " ORDER BY farmer_id, interview_date DESC");
$allInterviewsStmt->execute();
$allInterviews = $allInterviewsStmt->fetchAll();

$pageTitle = 'New Observation - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-plus-circle"></i> New Field Observation</h5>
                <p class="text-muted small mb-3">Record field observations — tap options, minimal typing.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <!-- Basic Info -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-info-circle"></i> Observation Info</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small">Subject <span class="text-danger">*</span></label>
                                    <div class="btn-group btn-group-sm mb-1 w-100" role="group">
                                        <input type="radio" class="btn-check" name="entity_type" id="entityFarmer" value="farmer" autocomplete="off"
                                               onchange="toggleEntityType()" <?php echo $entityType === 'farmer' ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary" for="entityFarmer"><i class="bi bi-person"></i> Farmer</label>
                                        <input type="radio" class="btn-check" name="entity_type" id="entityParticipant" value="participant" autocomplete="off"
                                               onchange="toggleEntityType()" <?php echo $entityType === 'participant' ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-secondary" for="entityParticipant"><i class="bi bi-person-badge"></i> Stakeholder</label>
                                    </div>
                                    <select name="farmer_id" id="obsFarmerSelect" class="form-select form-select-sm" onchange="filterObservationInterviews()"
                                            <?php echo $entityType === 'farmer' ? 'required' : 'style="display:none"'; ?>>
                                        <option value="">-- Select Farmer --</option>
                                        <?php foreach ($farmers as $f): ?>
                                            <option value="<?php echo $f['id']; ?>"
                                                <?php echo ((int) (old('farmer_id') ?: $selectedFarmerId)) === (int) $f['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($f['name'] . ' (' . $f['farmer_id'] . ')' . ($f['district'] ? ' - ' . $f['district'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="participant_id" id="obsParticipantSelect" class="form-select form-select-sm"
                                            <?php echo $entityType === 'participant' ? 'required' : 'style="display:none"'; ?>>
                                        <option value="">-- Select Stakeholder --</option>
                                        <?php foreach ($participants as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"
                                                <?php echo ((int) (old('participant_id') ?: $selectedParticipantId)) === (int) $p['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['name'] . ' (' . $p['participant_id'] . ')' . ($p['district'] ? ' - ' . $p['district'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><a href="<?php echo BASE_URL; ?>/farmer-add.php" target="_blank"><i class="bi bi-person-plus"></i> Add farmer</a> &middot; <a href="<?php echo BASE_URL; ?>/participant-add.php" target="_blank"><i class="bi bi-person-badge"></i> Add stakeholder</a></div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="observation_date" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars(old('observation_date') ?: date('Y-m-d')); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Link to Interview (optional)</label>
                                    <select name="interview_id" id="obsInterviewSelect" class="form-select form-select-sm">
                                        <option value="">-- None --</option>
                                        <?php if ($selectedFarmerId > 0): ?>
                                            <?php
                                            $filteredInterviews = array_filter($allInterviews, function($iv) use ($selectedFarmerId) {
                                                return (int) $iv['farmer_id'] === $selectedFarmerId;
                                            });
                                            ?>
                                            <?php foreach ($filteredInterviews as $iv): ?>
                                                <option value="<?php echo $iv['id']; ?>"
                                                    <?php echo ((int) (old('interview_id') ?: $selectedInterviewId)) === (int) $iv['id'] ? 'selected' : ''; ?>>
                                                    <?php echo $iv['interview_date']; ?> (ID: <?php echo $iv['id']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Observed Smartphone Usage -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-phone"></i> Smartphone Usage</div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php $selSmart = old('observed_smartphone') ? (array) old('observed_smartphone') : []; ?>
                                <?php foreach ($smartphoneOptions as $opt): ?>
                                    <?php if ($opt === 'Other'): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="observed_smartphone[]" value="Other" id="os_other"
                                                   onchange="document.getElementById('smartphoneOtherInput').style.display=this.checked?'block':'none'"
                                                   <?php echo in_array('Other', $selSmart) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="os_other">Other</label>
                                        </div>
                                        <div id="smartphoneOtherInput" style="display:<?php echo in_array('Other', $selSmart) ? 'block' : 'none'; ?>">
                                            <input type="text" name="observed_smartphone_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('observed_smartphone_other')); ?>">
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_smartphone[]" value="<?php echo $opt; ?>" id="os_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selSmart) ? 'checked' : ''; ?>><label class="form-check-label small" for="os_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Observed Record Books -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-journal-text"></i> Record Books</div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php $selRec = old('observed_records') ? (array) old('observed_records') : []; ?>
                                <?php foreach ($recordOptions as $opt): ?>
                                    <?php if ($opt === 'Other'): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="observed_records[]" value="Other" id="or_other"
                                                   onchange="document.getElementById('recordsOtherInput').style.display=this.checked?'block':'none'"
                                                   <?php echo in_array('Other', $selRec) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="or_other">Other</label>
                                        </div>
                                        <div id="recordsOtherInput" style="display:<?php echo in_array('Other', $selRec) ? 'block' : 'none'; ?>">
                                            <input type="text" name="observed_records_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('observed_records_other')); ?>">
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_records[]" value="<?php echo $opt; ?>" id="or_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selRec) ? 'checked' : ''; ?>><label class="form-check-label small" for="or_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Observed Technology -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-router"></i> Technology Observed</div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php $selTech = old('observed_tech') ? (array) old('observed_tech') : []; ?>
                                <?php foreach ($techOptions as $opt): ?>
                                    <?php if ($opt === 'Other'): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="observed_tech[]" value="Other" id="ot_other"
                                                   onchange="document.getElementById('techOtherInput').style.display=this.checked?'block':'none'"
                                                   <?php echo in_array('Other', $selTech) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="ot_other">Other</label>
                                        </div>
                                        <div id="techOtherInput" style="display:<?php echo in_array('Other', $selTech) ? 'block' : 'none'; ?>">
                                            <input type="text" name="observed_tech_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('observed_tech_other')); ?>">
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="observed_tech[]" value="<?php echo $opt; ?>" id="ot_<?php echo slugify($opt); ?>" <?php echo in_array($opt, $selTech) ? 'checked' : ''; ?>><label class="form-check-label small" for="ot_<?php echo slugify($opt); ?>"><?php echo $opt; ?></label></div></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Farm Condition & Crop Health -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-tree"></i> Farm Condition &amp; Crop Health</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small fw-medium">Crop Health</label>
                                    <select name="crop_health_assessment" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $ch = old('crop_health_assessment'); ?>
                                        <option value="excellent" <?php echo $ch === 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                        <option value="good" <?php echo $ch === 'good' ? 'selected' : ''; ?>>Good</option>
                                        <option value="fair" <?php echo $ch === 'fair' ? 'selected' : ''; ?>>Fair</option>
                                        <option value="poor" <?php echo $ch === 'poor' ? 'selected' : ''; ?>>Poor</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-medium">Pest/Disease</label>
                                    <select name="pest_disease_presence" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $pd = old('pest_disease_presence'); ?>
                                        <option value="yes" <?php echo $pd === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo $pd === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-medium">Water Source</label>
                                    <select name="water_source" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $ws = old('water_source'); ?>
                                        <option value="rainfed" <?php echo $ws === 'rainfed' ? 'selected' : ''; ?>>Rainfed</option>
                                        <option value="irrigation" <?php echo $ws === 'irrigation' ? 'selected' : ''; ?>>Irrigation</option>
                                        <option value="both" <?php echo $ws === 'both' ? 'selected' : ''; ?>>Both</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-medium">Overall condition</label>
                                    <div class="d-flex gap-3">
                                        <?php $fc = old('farm_condition'); ?>
                                        <?php foreach ($farmConditionOpts as $co): ?>
                                            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="farm_condition" value="<?php echo $co; ?>" id="fc_<?php echo $co; ?>" <?php echo $fc === $co ? 'checked' : ''; ?>><label class="form-check-label small" for="fc_<?php echo $co; ?>"><?php echo $co; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Details</label>
                                    <div class="row">
                                        <?php $selFCD = old('farm_condition_details') ? (array) old('farm_condition_details') : []; ?>
                                        <?php foreach ($farmConditionDetails as $fd): ?>
                                            <?php if ($fd === 'Other'): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="farm_condition_details[]" value="Other" id="fcd_other"
                                                           onchange="document.getElementById('fcdOtherInput').style.display=this.checked?'block':'none'"
                                                           <?php echo in_array('Other', $selFCD) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="fcd_other">Other</label>
                                                </div>
                                                <div id="fcdOtherInput" style="display:<?php echo in_array('Other', $selFCD) ? 'block' : 'none'; ?>">
                                                    <input type="text" name="farm_condition_detail_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('farm_condition_detail_other')); ?>">
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="farm_condition_details[]" value="<?php echo $fd; ?>" id="fcd_<?php echo slugify($fd); ?>" <?php echo in_array($fd, $selFCD) ? 'checked' : ''; ?>><label class="form-check-label small" for="fcd_<?php echo slugify($fd); ?>"><?php echo $fd; ?></label></div></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Researcher Notes -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-pencil-square"></i> Notes <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Researcher Notes</label>
                                    <textarea name="researcher_notes" class="form-control form-control-sm" rows="2" placeholder="Key observations..."><?php echo htmlspecialchars(old('researcher_notes')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">General Notes</label>
                                    <textarea name="general_notes" class="form-control form-control-sm" rows="2" placeholder="Additional notes..."><?php echo htmlspecialchars(old('general_notes')); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Photo Upload -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-camera"></i> Photos <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <input type="file" name="photos[]" class="form-control form-control-sm" multiple accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="photo_caption" class="form-control form-control-sm" placeholder="Caption (applies to all)">
                                </div>
                            </div>
                            <div class="form-text">Supports JPG, PNG, GIF, WebP. Select multiple files.</div>
                        </div>
                    </div>

                    <!-- Audio/Video Upload -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-mic"></i> Audio / Video <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <input type="file" name="media[]" class="form-control form-control-sm" multiple accept="audio/*,video/*">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="media_caption" class="form-control form-control-sm" placeholder="Caption (applies to all)">
                                </div>
                            </div>
                            <div class="form-text">Supports MP3, WAV, OGG, AAC, FLAC, MP4, WebM, AVI. Max 100MB each.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg"></i> Save Observation</button>
                        <a href="<?php echo BASE_URL; ?>/observations.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Client-Side Interview Filtering ═══ -->
<script>
// Preloaded interviews data: array of {id, farmer_id, interview_date}
const allInterviews = <?php echo json_encode($allInterviews, JSON_UNESCAPED_UNICODE); ?>;

function toggleEntityType() {
    const isFarmer = document.getElementById('entityFarmer').checked;
    document.getElementById('obsFarmerSelect').style.display = isFarmer ? '' : 'none';
    document.getElementById('obsFarmerSelect').required = isFarmer;
    document.getElementById('obsParticipantSelect').style.display = isFarmer ? 'none' : '';
    document.getElementById('obsParticipantSelect').required = !isFarmer;
    if (!isFarmer) {
        // Clear interview selection when switching to participant
        document.getElementById('obsInterviewSelect').innerHTML = '<option value="">-- None --</option>';
    } else {
        filterObservationInterviews();
    }
}

function filterObservationInterviews() {
    const farmerId = parseInt(document.getElementById('obsFarmerSelect').value);
    const select = document.getElementById('obsInterviewSelect');
    
    select.innerHTML = '<option value="">-- None --</option>';
    
    if (!farmerId) return;
    
    const filtered = allInterviews.filter(function(iv) {
        return parseInt(iv.farmer_id) === farmerId;
    });
    
    filtered.forEach(function(iv) {
        const opt = document.createElement('option');
        opt.value = iv.id;
        opt.textContent = iv.interview_date + ' (ID: ' + iv.id + ')';
        select.appendChild(opt);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('entityFarmer').checked) {
        filterObservationInterviews();
    }
});
</script>

<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
<?php
function slugify($text) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $text);
}
include __DIR__ . '/footer.php'; ?>
