<?php
/**
 * Krishi Sathi Research System - Edit Interview (Refined)
 * Matches the new field interview assistant form structure.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot edit interviews.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$interview = $pdo->prepare("SELECT * FROM research_interviews WHERE id = ?");
$interview->execute([$id]);
$interview = $interview->fetch();

if (!$interview) {
    setFlash('error', 'Interview not found.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

if (!canEditInterview($interview)) {
    if (isRecordLocked($interview) && !isLead()) {
        setFlash('error', 'This interview has been approved and locked. It is now part of the protected research dataset and cannot be edited. A Research Lead may unlock it if corrections are required.');
    } else {
        setFlash('error', 'This interview is locked by the approval workflow.');
    }
    header('Location: ' . BASE_URL . '/interview-view.php?id=' . $id);
    exit;
}

// Fetch problems rankings
$problemsStmt = $pdo->prepare("SELECT * FROM research_problem_rankings WHERE interview_id = ? ORDER BY problem_number");
$problemsStmt->execute([$id]);
$problems = $problemsStmt->fetchAll();
$rankMap = [];
foreach ($problems as $p) {
    $rankMap[$p['problem_number']] = $p['problem_description'];
}

$farmersStmt = $pdo->prepare("SELECT id, farmer_id, name, district FROM research_farmers WHERE " . activeWhere() . " OR id = ? ORDER BY name ASC");
$farmersStmt->execute([(int) $interview['farmer_id']]);
$farmers = $farmersStmt->fetchAll();

$farmProfile = $pdo->prepare("SELECT * FROM research_farm_profiles WHERE farmer_id = ?");
$farmProfile->execute([$interview['farmer_id']]);
$farmProfile = $farmProfile->fetch();

$errors = [];

// ─── Delete Photo Handler (soft delete) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_photo') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
        exit;
    }
    $photoId = (int) ($_POST['photo_id'] ?? 0);
    $photo = $pdo->prepare("SELECT * FROM research_photos WHERE id = ? AND interview_id = ?");
    $photo->execute([$photoId, $id]);
    $photo = $photo->fetch();
    if ($photo) {
        // Contributors can only delete their own photos
        if (isContributor() && (int) ($photo['uploaded_by'] ?? 0) !== currentUserId()) {
            setFlash('error', 'You can only delete your own photos.');
            header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
            exit;
        }
        $pdo->prepare("UPDATE research_photos SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([currentUserId(), $photoId]);
        setFlash('success', 'Photo moved to trash.');
    }
    header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
    exit;
}

// ─── Delete Media Handler (soft delete) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_media') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
        exit;
    }
    $mediaId = (int) ($_POST['media_id'] ?? 0);
    $media = $pdo->prepare("SELECT * FROM research_media WHERE id = ? AND interview_id = ?");
    $media->execute([$mediaId, $id]);
    $media = $media->fetch();
    if ($media) {
        // Contributors can only delete their own media
        if (isContributor() && (int) ($media['uploaded_by'] ?? 0) !== currentUserId()) {
            setFlash('error', 'You can only delete your own media.');
            header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
            exit;
        }
        $pdo->prepare("UPDATE research_media SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([currentUserId(), $mediaId]);
        setFlash('success', 'Media moved to trash.');
    }
    header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
    exit;
}

// ─── Caption Update Handler ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_caption') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
        exit;
    }
    $source = $_POST['source'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');
    if ($itemId > 0) {
        if ($source === 'photos') {
            $pdo->prepare("UPDATE research_photos SET caption = ? WHERE id = ? AND interview_id = ?")->execute([$caption ?: null, $itemId, $id]);
        } elseif ($source === 'media') {
            $pdo->prepare("UPDATE research_media SET caption = ? WHERE id = ? AND interview_id = ?")->execute([$caption ?: null, $itemId, $id]);
        }
    }
    setFlash('success', 'Caption updated.');
    header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
    exit;
}

// ─── Consent Update Handler ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_consent') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
        exit;
    }
    $source = $_POST['source'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $newConsent = $_POST['consent'] ?? 'research';
    $validConsent = ['research', 'internal', 'none'];
    if (!in_array($newConsent, $validConsent, true)) $newConsent = 'research';
    if ($itemId > 0) {
        if ($source === 'photos') {
            $pdo->prepare("UPDATE research_photos SET consent = ? WHERE id = ? AND interview_id = ?")->execute([$newConsent, $itemId, $id]);
        } elseif ($source === 'media') {
            $pdo->prepare("UPDATE research_media SET consent = ? WHERE id = ? AND interview_id = ?")->execute([$newConsent, $itemId, $id]);
        }
    }
    setFlash('success', 'Consent updated.');
    header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/interview-edit.php?id=' . $id);
        exit;
    }

    $farmer_id       = (int) ($_POST['farmer_id'] ?? 0);
    $interview_date  = $_POST['interview_date'] ?? '';
    $locDistrict     = trim($_POST['loc_district'] ?? '');
    $locMunicipality = trim($_POST['loc_municipality'] ?? '');
    $locWard         = trim($_POST['loc_ward'] ?? '');
    $locTole         = trim($_POST['loc_tole'] ?? '');
    $locPlain        = trim($_POST['location'] ?? '');
    $latitude        = $_POST['latitude'] ?? null;
    $longitude       = $_POST['longitude'] ?? null;
    $altitude        = trim($_POST['altitude'] ?? '');
    $gps_altitude    = $_POST['gps_altitude'] ?? null;
    $gps_accuracy    = $_POST['gps_accuracy'] ?? null;
    $location_source = trim($_POST['location_source'] ?? '');
    if ($altitude === '') $altitude = null;
    if ($locDistrict) {
        $location = $locDistrict;
        if ($locMunicipality) $location .= ', ' . $locMunicipality;
        if ($locWard) $location .= ', Ward ' . $locWard;
        if ($locTole) $location .= ', ' . $locTole;
    } else {
        $location = $locPlain;
    }
    $duration        = (int) ($_POST['duration_minutes'] ?? 0);
    $interview_round  = $_POST['interview_round'] ?? $interview['interview_round'] ?? null;
    if ($interview_round === '' || $interview_round === '0') $interview_round = null;
    $interview_mode   = trim($_POST['interview_mode'] ?? $interview['interview_mode'] ?? '');
    $interview_language = trim($_POST['interview_language'] ?? $interview['interview_language'] ?? '');
    $problem_severity = $_POST['problem_severity'] ?? $interview['problem_severity'] ?? null;
    if ($problem_severity === '' || $problem_severity === '0') $problem_severity = null;
    $loss_amount_npr  = trim($_POST['loss_amount_npr'] ?? $interview['loss_amount_npr'] ?? '');
    if ($loss_amount_npr === '') $loss_amount_npr = null;
    $status          = normalizeInterviewStatus($_POST['interview_status'] ?? $interview['interview_status']);
    if (!isLead() && !isEditor() && $status === 'approved') $status = normalizeInterviewStatus($interview['interview_status']);
    if (isset($_POST['submit_interview']) && canSubmitInterview($interview)) $status = 'submitted';
    $followUp        = isset($_POST['follow_up_required']) ? 1 : 0;
    $followUpDate    = $_POST['follow_up_date'] ?? null;
    $followUpReason  = trim($_POST['follow_up_reason'] ?? '');

    $farm_types    = $_POST['farm_type'] ?? [];
    $farm_size     = trim($_POST['farm_size'] ?? '');
    $land_unit     = trim($_POST['land_unit'] ?? $farmProfile['land_unit'] ?? '');
    $years_farming = trim($_POST['years_farming'] ?? '');
    $is_commercial = isset($_POST['is_commercial']) ? 1 : 0;

    $problemChecklist = $_POST['problems'] ?? [];
    $otherProblem     = trim($_POST['problems_other'] ?? '');
    if ($otherProblem !== '') $problemChecklist[] = 'Other: ' . $otherProblem;
    $problemsSelected = implode(', ', $problemChecklist);

    $rank1 = $_POST['problem_rank_1'] ?? '';
    $rank2 = $_POST['problem_rank_2'] ?? '';
    $rank3 = $_POST['problem_rank_3'] ?? '';

    $loss_causes_selected = $_POST['loss_causes'] ?? [];
    $loss_causes_other    = trim($_POST['loss_causes_other'] ?? '');
    if ($loss_causes_other !== '') $loss_causes_selected[] = 'Other: ' . $loss_causes_other;
    $loss_contributing = implode(', ', $loss_causes_selected);
    $loss_cause       = $_POST['loss_main_cause'] ?? '';
    $loss_main_other  = trim($_POST['loss_main_other'] ?? '');
    if ($loss_cause === 'Other' && $loss_main_other !== '') $loss_cause = 'Other: ' . $loss_main_other;
    $loss_description = trim($_POST['loss_description'] ?? '');
    $loss_amount      = trim($_POST['loss_amount'] ?? '');
    $loss_period      = trim($_POST['loss_period'] ?? '');

    $record_methods = $_POST['record_keeping'] ?? [];
    $record_str     = implode(', ', $record_methods);
    $record_freq    = $_POST['record_frequency'] ?? '';

    $tech_used       = $_POST['technology'] ?? [];
    $tech_str        = implode(', ', $tech_used);
    $smartphone_indep = $_POST['smartphone_independence'] ?? '';

    $voice_interest     = $_POST['voice_interest'] ?? '';
    $voice_reason_opts  = $_POST['voice_reason_options'] ?? [];
    $voice_reason_other = trim($_POST['voice_reason_other'] ?? '');
    if ($voice_reason_other !== '') $voice_reason_opts[] = 'Other: ' . $voice_reason_other;
    $voice_rejections    = $_POST['voice_rejection_reasons'] ?? [];
    $voice_reject_other  = trim($_POST['voice_reject_other'] ?? '');
    if ($voice_reject_other !== '') $voice_rejections[] = 'Other: ' . $voice_reject_other;
    $voice_reason_str    = implode(', ', $voice_reason_opts);
    $voice_rejection_str = implode(', ', $voice_rejections);
    $voice_use_cases     = $_POST['voice_use_cases'] ?? [];
    $voice_uc_other      = trim($_POST['voice_uc_other'] ?? '');
    if ($voice_uc_other !== '') $voice_use_cases[] = 'Other: ' . $voice_uc_other;
    $voice_uc_str        = implode(', ', $voice_use_cases);

    $assumption_reminders = $_POST['assumption_reminders'] ?? '';
    $reminder_types       = $_POST['reminder_types'] ?? [];
    $reminder_other       = trim($_POST['reminder_other'] ?? '');
    if ($reminder_other !== '') $reminder_types[] = 'Other: ' . $reminder_other;
    $reminder_types_str   = implode(', ', $reminder_types);
    $app_motivations      = $_POST['app_motivations'] ?? [];
    $motivation_other     = trim($_POST['motivation_other'] ?? '');
    if ($motivation_other !== '') $app_motivations[] = 'Other: ' . $motivation_other;
    $app_motivations_str  = implode(', ', $app_motivations);
    $recommendation_types  = $_POST['recommendation_types'] ?? [];
    $recommendation_other  = trim($_POST['recommendation_other'] ?? '');
    if ($recommendation_other !== '') $recommendation_types[] = 'Other: ' . $recommendation_other;
    $recommendation_types_str = implode(', ', $recommendation_types);

    $interview_summary  = trim($_POST['interview_summary'] ?? '');
    $important_findings = trim($_POST['important_findings'] ?? '');

    if ($farmer_id <= 0) {
        $errors[] = 'Please select a farmer.';
    } else {
        $checkFarmer = $pdo->prepare("SELECT COUNT(*) FROM research_farmers WHERE id = ? AND (" . activeWhere() . " OR id = ?)");
        $checkFarmer->execute([$farmer_id, (int) $interview['farmer_id']]);
        if ((int) $checkFarmer->fetchColumn() === 0) {
            $errors[] = 'Selected farmer is deleted or unavailable.';
        }
    }
    if ($interview_date === '') $errors[] = 'Interview date is required.';

    // Duration validation (soft warnings — does not block save)
    $durationWarnings = [];
    if ($duration > 0 && $duration < 2) {
        $durationWarnings[] = 'Duration seems very short (< 2 minutes). Please verify or use the timer.';
    }
    if ($duration > 240) {
        $durationWarnings[] = 'Duration seems very long (> 4 hours). Please verify the value.';
    }

    // ─── Phase 2B Field Validation ───────────────────────────────
    if ($problem_severity !== null) {
        $ps = (int) $problem_severity;
        if ($ps < 1 || $ps > 5) {
            $errors[] = 'Problem severity must be between 1 and 5.';
        }
    }
    if ($interview_round !== null) {
        $ir = (int) $interview_round;
        if ($ir < 1 || $ir > 20) {
            $errors[] = 'Interview round must be a positive number between 1 and 20.';
        }
    }
    if ($loss_amount_npr !== null && $loss_amount_npr !== '') {
        $ln = (float) $loss_amount_npr;
        if ($ln <= 0) {
            $errors[] = 'Loss amount (NPR) must be a positive number.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE research_interviews SET
                farmer_id=?, interview_date=?, location=?, duration_minutes=?,
                latitude=?, longitude=?, altitude=?, gps_altitude=?, gps_accuracy=?, location_source=?,
                problems_selected=?, loss_contributing_causes=?, loss_cause=?, loss_description=?, loss_amount=?, loss_period=?,
                record_keeping_method=?, record_frequency=?, technology_used=?, smartphone_independence=?,
                voice_interest=?, voice_reason_options=?, voice_rejection_reasons=?, voice_use_cases=?,
                assumption_reminders=?, reminder_types=?, app_motivations=?, recommendation_types=?,
                interview_status=?, follow_up_required=?, follow_up_date=?, follow_up_reason=?,
                submitted_at = CASE WHEN ? = 'submitted' AND submitted_at IS NULL THEN NOW() ELSE submitted_at END,
                submitted_by = CASE WHEN ? = 'submitted' AND submitted_by IS NULL THEN ? ELSE submitted_by END,
                interview_summary=?, important_findings=?,
                interview_round=?, interview_mode=?, interview_language=?, problem_severity=?, loss_amount_npr=?
                WHERE id=?");
            $stmt->execute([
                $farmer_id, $interview_date, $location, $duration ?: null,
                $latitude ?: null, $longitude ?: null,
                $altitude ?: null, $gps_altitude ?: null, $gps_accuracy ?: null, $location_source ?: null,
                $problemsSelected, $loss_contributing, $loss_cause, $loss_description, $loss_amount ?: null, $loss_period ?: null,
                $record_str, $record_freq, $tech_str, $smartphone_indep,
                $voice_interest, $voice_reason_str, $voice_rejection_str, $voice_uc_str,
                $assumption_reminders, $reminder_types_str, $app_motivations_str, $recommendation_types_str,
                $status, $followUp, $followUpDate ?: null, $followUpReason ?: null,
                $status, $status, currentUserId(),
                $interview_summary, $important_findings,
                $interview_round, $interview_mode ?: null, $interview_language ?: null,
                $problem_severity, $loss_amount_npr,
                $id
            ]);

            // Update problem rankings (delete & re-insert)
            $pdo->prepare("DELETE FROM research_problem_rankings WHERE interview_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("INSERT INTO research_problem_rankings (interview_id, problem_number, problem_description) VALUES (?, ?, ?)");
            if ($rank1 !== '') $stmt->execute([$id, 1, $rank1]);
            if ($rank2 !== '') $stmt->execute([$id, 2, $rank2]);
            if ($rank3 !== '') $stmt->execute([$id, 3, $rank3]);

            // Farm profile
            $farmTypeStr = implode(', ', is_array($farm_types) ? $farm_types : [$farm_types]);
            if ($farmProfile) {
                $pdo->prepare("UPDATE research_farm_profiles SET farm_type=?, farm_size=?, land_unit=?, years_farming=?, is_commercial=? WHERE farmer_id=?")
                    ->execute([$farmTypeStr, $farm_size, $land_unit ?: null, $years_farming, $is_commercial, $farmer_id]);
            } else {
                $pdo->prepare("INSERT INTO research_farm_profiles (farmer_id, farm_type, farm_size, land_unit, years_farming, is_commercial) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$farmer_id, $farmTypeStr, $farm_size, $land_unit ?: null, $years_farming, $is_commercial]);
            }

            $pdo->commit();
            logAudit($pdo, 'interview_updated', 'interview', $id, 'Updated — status: ' . $status);
            setFlash('success', 'Interview updated successfully.');
            header('Location: ' . BASE_URL . '/interview-view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Parse saved values for checkboxes (default: saved values)
$savedProblems = $interview['problems_selected'] ? array_map('trim', explode(',', $interview['problems_selected'])) : [];
$savedRecordMethods = $interview['record_keeping_method'] ? array_map('trim', explode(',', $interview['record_keeping_method'])) : [];
$savedTech = $interview['technology_used'] ? array_map('trim', explode(',', $interview['technology_used'])) : [];
$savedVoiceUC = $interview['voice_use_cases'] ? array_map('trim', explode(',', $interview['voice_use_cases'])) : [];
$savedFarmTypes = $farmProfile ? array_map('trim', explode(',', $farmProfile['farm_type'])) : [];

$formSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST');
$selectedProblems = $formSubmitted ? ($_POST['problems'] ?? []) : $savedProblems;
$recordMethods    = $formSubmitted ? ($_POST['record_keeping'] ?? []) : $savedRecordMethods;
$techUsed         = $formSubmitted ? ($_POST['technology'] ?? []) : $savedTech;
$voiceUC          = $formSubmitted ? ($_POST['voice_use_cases'] ?? []) : $savedVoiceUC;
$farmTypes        = $formSubmitted ? ($_POST['farm_type'] ?? []) : $savedFarmTypes;

// Check if "Other" was in saved problems
$hadOther = false;
$otherText = '';
foreach ($savedProblems as $sp) {
    if (strpos($sp, 'Other: ') === 0) {
        $hadOther = true;
        $otherText = substr($sp, 7);
    }
}
if ($formSubmitted) {
    $hadOther = in_array('Other', $_POST['problems'] ?? []);
    $otherText = $_POST['problems_other'] ?? '';
}
$showOtherInput = $formSubmitted ? $hadOther : $hadOther;
$otherProblemVal = $formSubmitted ? trim($_POST['problems_other'] ?? '') : $otherText;

$interviewOptions = require __DIR__ . '/config-interview-options.php';

$problemOptions       = $interviewOptions['problems'];
$lossCauseOptions     = $interviewOptions['loss_causes'];
$voiceReasonOptions   = $interviewOptions['voice_reasons'];
$voiceRejectionOptions = $interviewOptions['voice_rejections'];
$voiceUseCases        = $interviewOptions['voice_use_cases'];
$reminderTypeOptions  = $interviewOptions['reminder_types'];
$appMotivationOptions = $interviewOptions['app_motivations'];
$recommendationOptions = $interviewOptions['recommendations'];
$recordOptions        = $interviewOptions['record_options'];
$recordFrequencies    = $interviewOptions['record_frequencies'];
$techOptions          = $interviewOptions['tech_options'];
$smartphoneOpts       = $interviewOptions['smartphone_options'];
$farmTypeOptions      = $interviewOptions['farm_types'];

$allRankOptions = array_merge($problemOptions, ['Other']);

$pageTitle = 'Edit Interview - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-pencil"></i> Edit Interview #<?php echo $id; ?></h5>
                <p class="text-muted small mb-3">Update interview research data.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($durationWarnings)): ?>
                    <div class="alert alert-info py-2">
                        <?php foreach ($durationWarnings as $w): ?><div><i class="bi bi-info-circle"></i> <?php echo $w; ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <!-- 1. Interview Info + Status + Follow-Up -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Interview Information</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small">Farmer</label>
                                    <select name="farmer_id" class="form-select form-select-sm" required>
                                        <option value="">-- Select Farmer --</option>
                                        <?php foreach ($farmers as $f): ?>
                                            <option value="<?php echo $f['id']; ?>"
                                                <?php echo (int) ($_POST['farmer_id'] ?? $interview['farmer_id']) === (int) $f['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($f['name'] . ' (' . $f['farmer_id'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Date</label>
                                    <input type="date" name="interview_date" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['interview_date'] ?? $interview['interview_date']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Min</label>
                                    <input type="number" name="duration_minutes" class="form-control form-control-sm" min="0"
                                           value="<?php echo htmlspecialchars($_POST['duration_minutes'] ?? $interview['duration_minutes']); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Round</label>
                                    <select name="interview_round" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        <?php $irVal = $_POST['interview_round'] ?? $interview['interview_round'] ?? ''; ?>
                                        <?php for ($r = 1; $r <= 5; $r++): ?>
                                            <option value="<?php echo $r; ?>" <?php echo (string) $irVal === (string) $r ? 'selected' : ''; ?>>R<?php echo $r; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Mode</label>
                                    <select name="interview_mode" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $imVal = $_POST['interview_mode'] ?? $interview['interview_mode'] ?? ''; ?>
                                        <option value="in_person" <?php echo $imVal === 'in_person' ? 'selected' : ''; ?>>In Person</option>
                                        <option value="phone" <?php echo $imVal === 'phone' ? 'selected' : ''; ?>>Phone</option>
                                        <option value="video" <?php echo $imVal === 'video' ? 'selected' : ''; ?>>Video</option>
                                        <option value="other" <?php echo $imVal === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Language</label>
                                    <select name="interview_language" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $ilVal = $_POST['interview_language'] ?? $interview['interview_language'] ?? ''; ?>
                                        <option value="Nepali" <?php echo $ilVal === 'Nepali' ? 'selected' : ''; ?>>Nepali</option>
                                        <option value="English" <?php echo $ilVal === 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="Maithili" <?php echo $ilVal === 'Maithili' ? 'selected' : ''; ?>>Maithili</option>
                                        <option value="Bhojpuri" <?php echo $ilVal === 'Bhojpuri' ? 'selected' : ''; ?>>Bhojpuri</option>
                                        <option value="Tharu" <?php echo $ilVal === 'Tharu' ? 'selected' : ''; ?>>Tharu</option>
                                        <option value="Newar" <?php echo $ilVal === 'Newar' ? 'selected' : ''; ?>>Newar</option>
                                        <option value="Tamang" <?php echo $ilVal === 'Tamang' ? 'selected' : ''; ?>>Tamang</option>
                                        <option value="Other" <?php echo $ilVal === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Location (or use below)</label>
                                    <input type="text" name="location" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['location'] ?? $interview['location']); ?>" placeholder="e.g., Kavre, Panauti">
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                <label class="form-label small">Province</label>
                                <select class="form-select form-select-sm" id="eiProvince" onchange="filterDistricts('eiProvince','eiDistrict','eiMunicipality','eiWard')">
                                    <option value="">-- Select --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">District</label>
                                <select name="loc_district" id="eiDistrict" class="form-select form-select-sm" onchange="loadEIMunicipalities()">
                                        <option value="">-- Select --</option>
                                        <?php foreach (array_keys(getLocationData()) as $d): ?>
                                            <option value="<?php echo $d; ?>" <?php echo ($_POST['loc_district'] ?? '') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Municipality</label>
                                    <select name="loc_municipality" id="eiMunicipality" class="form-select form-select-sm" onchange="loadEIWards()">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Ward</label>
                                    <select name="loc_ward" id="eiWard" class="form-select form-select-sm">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tole</label>
                                    <input type="text" name="loc_tole" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['loc_tole'] ?? ''); ?>" placeholder="Optional">
                                </div>
                            </div>
                            <!-- GPS + Altitude for interview location -->
                            <input type="hidden" name="latitude" id="eiLatField" value="<?php echo htmlspecialchars($_POST['latitude'] ?? $interview['latitude']); ?>">
                            <input type="hidden" name="longitude" id="eiLngField" value="<?php echo htmlspecialchars($_POST['longitude'] ?? $interview['longitude']); ?>">
                            <input type="hidden" name="gps_altitude" id="eiGpsAltField" value="<?php echo htmlspecialchars($_POST['gps_altitude'] ?? $interview['gps_altitude']); ?>">
                            <input type="hidden" name="gps_accuracy" id="eiGpsAccuracyField" value="<?php echo htmlspecialchars($_POST['gps_accuracy'] ?? ($interview['gps_accuracy'] ?? '')); ?>">
                            <input type="hidden" name="location_source" id="eiLocationSourceField" value="<?php echo htmlspecialchars($_POST['location_source'] ?? ($interview['location_source'] ?? '')); ?>">
                            <div class="row g-2 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label small">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                                    <input type="number" name="altitude" class="form-control form-control-sm" step="0.01"
                                           value="<?php echo htmlspecialchars($_POST['altitude'] ?? $interview['altitude']); ?>"
                                           placeholder="e.g., 1350">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" id="eiGpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getEditInterviewGPS()">
                                        <i class="bi bi-geo-alt"></i> 📍 Interview GPS
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <small id="eiGpsStatus" class="text-muted">
                                        <?php if ($interview['latitude'] && $interview['longitude']): ?>
                                            ✅ GPS: <?php echo $interview['latitude']; ?>, <?php echo $interview['longitude']; ?>
                                            <?php if ($interview['gps_altitude']): ?> (<?php echo $interview['gps_altitude']; ?>m)<?php endif; ?>
                                        <?php else: ?>
                                            📍 Interview GPS not captured
                                        <?php endif; ?>
                                    </small>
                                    <small id="eiGpsCoords" class="text-muted d-block"></small>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label small">Status</label>
                                    <select name="interview_status" class="form-select form-select-sm">
                                        <?php $stVal = normalizeInterviewStatus($_POST['interview_status'] ?? $interview['interview_status'] ?? 'draft'); ?>
                                        <option value="draft" <?php echo $stVal === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="submitted" <?php echo $stVal === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <?php if (isLead()): ?>
                                            <option value="approved" <?php echo $stVal === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo $stVal === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Follow-Up Required?</label>
                                    <div class="form-check pt-1">
                                        <?php $furVal = $_POST['follow_up_required'] ?? $interview['follow_up_required'] ?? 0; ?>
                                        <input class="form-check-input" type="checkbox" name="follow_up_required" value="1" id="efur"
                                               onchange="document.getElementById('efollowUpDetails').style.display=this.checked?'block':'none'"
                                               <?php echo $furVal ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="efur">Schedule follow-up</label>
                                    </div>
                                </div>
                            </div>
                            <div id="efollowUpDetails" style="display:<?php echo (($_POST['follow_up_required'] ?? $interview['follow_up_required'] ?? 0)) ? 'block' : 'none'; ?>">
                                <div class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label small">Follow-Up Date</label>
                                        <input type="date" name="follow_up_date" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($_POST['follow_up_date'] ?? $interview['follow_up_date']); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Reason</label>
                                        <select name="follow_up_reason" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php $efrVal = $_POST['follow_up_reason'] ?? $interview['follow_up_reason']; ?>
                                            <option value="Need more information" <?php echo $efrVal === 'Need more information' ? 'selected' : ''; ?>>Need more information</option>
                                            <option value="Seasonal follow-up" <?php echo $efrVal === 'Seasonal follow-up' ? 'selected' : ''; ?>>Seasonal follow-up</option>
                                            <option value="Technology validation" <?php echo $efrVal === 'Technology validation' ? 'selected' : ''; ?>>Technology validation</option>
                                            <option value="Voice validation" <?php echo $efrVal === 'Voice validation' ? 'selected' : ''; ?>>Voice validation</option>
                                            <option value="Other" <?php echo $efrVal === 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Farm Profile -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Farm Profile</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php foreach ($farmTypeOptions as $ft): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="farm_type[]" value="<?php echo $ft; ?>" id="eft_<?php echo $ft; ?>"
                                                       <?php echo in_array($ft, $farmTypes) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="eft_<?php echo $ft; ?>"><?php echo $ft; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Farm Size</label>
                                    <input type="text" name="farm_size" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['farm_size'] ?? ($farmProfile['farm_size'] ?? '')); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Land Unit</label>
                                    <select name="land_unit" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $luVal = $_POST['land_unit'] ?? $farmProfile['land_unit'] ?? ''; ?>
                                        <option value="kattha" <?php echo $luVal === 'kattha' ? 'selected' : ''; ?>>Kattha</option>
                                        <option value="bigha" <?php echo $luVal === 'bigha' ? 'selected' : ''; ?>>Bigha</option>
                                        <option value="ropani" <?php echo $luVal === 'ropani' ? 'selected' : ''; ?>>Ropani</option>
                                        <option value="hectare" <?php echo $luVal === 'hectare' ? 'selected' : ''; ?>>Hectare</option>
                                        <option value="sqft" <?php echo $luVal === 'sqft' ? 'selected' : ''; ?>>Sq. Ft.</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Years Farming</label>
                                    <input type="text" name="years_farming" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['years_farming'] ?? ($farmProfile['years_farming'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_commercial" value="1" id="ecom"
                                            <?php echo (isset($_POST['is_commercial']) ? $_POST['is_commercial'] : ($farmProfile['is_commercial'] ?? 0)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="ecom">Commercial Farmer</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Problem Analysis -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span>Problem Analysis</span>
                            <div class="d-flex gap-2 align-items-center">
                                <label class="small text-muted mb-0">Severity:</label>
                                <select name="problem_severity" class="form-select form-select-sm" style="max-width:100px;">
                                    <option value="">—</option>
                                    <?php $psVal = $_POST['problem_severity'] ?? $interview['problem_severity'] ?? ''; ?>
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <option value="<?php echo $s; ?>" <?php echo (string) $psVal === (string) $s ? 'selected' : ''; ?>><?php echo $s; ?> <?php echo $s === 1 ? '(Low)' : ($s === 5 ? '(Critical)' : ''); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What problems does the farmer face?</label>
                                    <div class="row">
                                        <?php foreach ($problemOptions as $po): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="problems[]" value="<?php echo $po; ?>" id="eprob_<?php echo slugify($po); ?>"
                                                           <?php echo in_array($po, $selectedProblems) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="eprob_<?php echo slugify($po); ?>"><?php echo $po; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="problems[]" value="Other" id="eprob_other"
                                                       onchange="document.getElementById('eotherProblemInput').style.display=this.checked?'block':'none'"
                                                       <?php echo $showOtherInput ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="eprob_other">Other</label>
                                            </div>
                                            <div id="eotherProblemInput" style="display:<?php echo $showOtherInput ? 'block' : 'none'; ?>">
                                                <input type="text" name="problems_other" class="form-control form-control-sm mt-1" placeholder="Describe..."
                                                       value="<?php echo htmlspecialchars($otherProblemVal); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mt-2">
                                    <hr class="my-2">
                                    <label class="form-label small fw-medium">Rank top 3 problems</label>
                                    <div class="row g-2">
                                        <?php for ($i = 1; $i <= 3; $i++): 
                                            $savedRank = $rankMap[$i] ?? '';
                                            $submittedRank = $_POST["problem_rank_$i"] ?? '';
                                        ?>
                                        <div class="col-md-4">
                                            <label class="small text-muted">#<?php echo $i; ?></label>
                                            <select name="problem_rank_<?php echo $i; ?>" class="form-select form-select-sm">
                                                <option value="">-- Select --</option>
                                                <?php foreach ($allRankOptions as $ro): ?>
                                                    <option value="<?php echo $ro; ?>" <?php echo ($submittedRank ?: $savedRank) === $ro ? 'selected' : ''; ?>><?php echo $ro; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Loss Analysis -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Loss Analysis</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What contributed to major losses?</label>
                                    <div class="row">
                                        <?php $selCauses = $formSubmitted ? ($_POST['loss_causes'] ?? []) : ($interview['loss_contributing_causes'] ? array_map('trim', explode(',', $interview['loss_contributing_causes'])) : []); ?>
                                        <?php foreach ($lossCauseOptions as $lo): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="loss_causes[]" value="<?php echo $lo; ?>" id="elc_<?php echo slugify($lo); ?>" <?php echo in_array($lo, $selCauses) ? 'checked' : ''; ?>><label class="form-check-label small" for="elc_<?php echo slugify($lo); ?>"><?php echo $lo; ?></label></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12 mt-2">
                                    <label class="form-label small fw-medium">What was the MAIN cause?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $lmcVal = $_POST['loss_main_cause'] ?? $interview['loss_cause']; ?>
                                        <?php foreach ($lossCauseOptions as $lo): ?>
                                            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="loss_main_cause" value="<?php echo $lo; ?>" id="elmc_<?php echo slugify($lo); ?>" <?php echo $lmcVal === $lo ? 'checked' : ''; ?>><label class="form-check-label small" for="elmc_<?php echo slugify($lo); ?>"><?php echo $lo; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Description</label>
                                    <input type="text" name="loss_description" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['loss_description'] ?? $interview['loss_description']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Loss amount</label>
                                    <input type="text" name="loss_amount" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['loss_amount'] ?? $interview['loss_amount']); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Loss NPR</label>
                                    <input type="number" name="loss_amount_npr" class="form-control form-control-sm" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($_POST['loss_amount_npr'] ?? $interview['loss_amount_npr']); ?>"
                                           placeholder="e.g., 50000">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Loss period</label>
                                    <input type="text" name="loss_period" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['loss_period'] ?? $interview['loss_period']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Record Keeping -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Record Keeping</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Methods</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($recordOptions as $ro): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="record_keeping[]" value="<?php echo $ro; ?>" id="erk_<?php echo $ro; ?>" <?php echo in_array($ro, $recordMethods) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="erk_<?php echo $ro; ?>"><?php echo $ro; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Frequency</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $rfVal = $_POST['record_frequency'] ?? $interview['record_frequency']; ?>
                                        <?php foreach ($recordFrequencies as $freq): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="record_frequency" value="<?php echo $freq; ?>" id="erf_<?php echo $freq; ?>" <?php echo $rfVal === $freq ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="erf_<?php echo $freq; ?>"><?php echo $freq; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6. Technology -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Technology</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Used</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($techOptions as $to): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="technology[]" value="<?php echo $to; ?>" id="etech_<?php echo $to; ?>" <?php echo in_array($to, $techUsed) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="etech_<?php echo $to; ?>"><?php echo $to; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Smartphone independence</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $siVal = $_POST['smartphone_independence'] ?? $interview['smartphone_independence']; ?>
                                        <?php foreach ($smartphoneOpts as $so): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="smartphone_independence" value="<?php echo $so; ?>" id="esi_<?php echo $so; ?>" <?php echo $siVal === $so ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="esi_<?php echo $so; ?>"><?php echo $so; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 7. Voice Validation -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Voice Validation</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">Would use voice recording?</label>
                                    <div class="d-flex gap-3">
                                        <?php $viVal = $_POST['voice_interest'] ?? $interview['voice_interest']; ?>
                                        <div class="form-check form-check-inline"><input class="form-check-input voice-radio" type="radio" name="voice_interest" value="yes" id="evi_yes" <?php echo $viVal === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small text-success" for="evi_yes">Yes</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input voice-radio" type="radio" name="voice_interest" value="maybe" id="evi_maybe" <?php echo $viVal === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small text-warning" for="evi_maybe">Maybe</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input voice-radio" type="radio" name="voice_interest" value="no" id="evi_no" <?php echo $viVal === 'no' ? 'checked' : ''; ?>><label class="form-check-label small text-danger" for="evi_no">No</label></div>
                                    </div>
                                </div>
                                <!-- Reason checkboxes (Yes/Maybe) -->
                                <div class="col-12" id="eVoiceReasonSection" style="display:<?php echo ($viVal === 'yes' || $viVal === 'maybe') ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">Why would you use voice recording?</label>
                                    <div class="row">
                                        <?php $selReasons = $formSubmitted ? ($_POST['voice_reason_options'] ?? []) : ($interview['voice_reason_options'] ? array_map('trim', explode(',', $interview['voice_reason_options'])) : []); ?>
                                        <?php foreach ($voiceReasonOptions as $vr): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="voice_reason_options[]" value="<?php echo $vr; ?>" id="evr_<?php echo slugify($vr); ?>" <?php echo in_array($vr, $selReasons) ? 'checked' : ''; ?>><label class="form-check-label small" for="evr_<?php echo slugify($vr); ?>"><?php echo $vr; ?></label></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Rejection reason checkboxes (No) -->
                                <div class="col-12" id="eVoiceRejectSection" style="display:<?php echo $viVal === 'no' ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">Why not?</label>
                                    <div class="row">
                                        <?php $selReject = $formSubmitted ? ($_POST['voice_rejection_reasons'] ?? []) : ($interview['voice_rejection_reasons'] ? array_map('trim', explode(',', $interview['voice_rejection_reasons'])) : []); ?>
                                        <?php foreach ($voiceRejectionOptions as $vj): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="voice_rejection_reasons[]" value="<?php echo $vj; ?>" id="evj_<?php echo slugify($vj); ?>" <?php echo in_array($vj, $selReject) ? 'checked' : ''; ?>><label class="form-check-label small" for="evj_<?php echo slugify($vj); ?>"><?php echo $vj; ?></label></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Use cases -->
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What would the farmer use voice for?</label>
                                    <div class="row">
                                        <?php foreach ($voiceUseCases as $uc): ?>
                                            <?php if ($uc === 'Other'): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="voice_use_cases[]" value="Other" id="euc_other" onchange="document.getElementById('eVoiceUCOtherInput').style.display=this.checked?'block':'none'" <?php echo in_array('Other', $voiceUC) ? 'checked' : ''; ?>><label class="form-check-label small" for="euc_other">Other</label></div>
                                            <div id="eVoiceUCOtherInput" style="display:<?php echo in_array('Other', $voiceUC) ? 'block' : 'none'; ?>"><input type="text" name="voice_uc_other" class="form-control form-control-sm mt-1" placeholder="Other use case..." value="<?php echo htmlspecialchars($_POST['voice_uc_other'] ?? ''); ?>"></div></div>
                                            <?php else: ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="voice_use_cases[]" value="<?php echo $uc; ?>" id="euc_<?php echo slugify($uc); ?>" <?php echo in_array($uc, $voiceUC) ? 'checked' : ''; ?>><label class="form-check-label small" for="euc_<?php echo slugify($uc); ?>"><?php echo $uc; ?></label></div></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 8. Reminder Validation -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Reminder Validation</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">Would reminders be useful?</label>
                                    <div class="d-flex gap-2">
                                        <?php $arVal = $_POST['assumption_reminders'] ?? $interview['assumption_reminders']; ?>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="yes" id="ear_yes" <?php echo $arVal === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="ear_yes">Yes</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="maybe" id="ear_maybe" <?php echo $arVal === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="ear_maybe">Maybe</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="no" id="ear_no" <?php echo $arVal === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="ear_no">No</label></div>
                                    </div>
                                </div>
                                <div class="col-12" id="eReminderTypesSection" style="display:<?php echo ($arVal === 'yes' || $arVal === 'maybe') ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">What reminders would be useful?</label>
                                    <div class="row">
                                        <?php $selRem = $formSubmitted ? ($_POST['reminder_types'] ?? []) : ($interview['reminder_types'] ? array_map('trim', explode(',', $interview['reminder_types'])) : []); ?>
                                        <?php foreach ($reminderTypeOptions as $rt): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="reminder_types[]" value="<?php echo $rt; ?>" id="ert_<?php echo slugify($rt); ?>" <?php echo in_array($rt, $selRem) ? 'checked' : ''; ?>><label class="form-check-label small" for="ert_<?php echo slugify($rt); ?>"><?php echo $rt; ?></label></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 9. App Usage Motivation -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">App Usage Motivation</div>
                        <div class="card-body py-2">
                            <label class="form-label small fw-medium">What would motivate regular use?</label>
                            <div class="row">
                                <?php $selMot = $formSubmitted ? ($_POST['app_motivations'] ?? []) : ($interview['app_motivations'] ? array_map('trim', explode(',', $interview['app_motivations'])) : []); ?>
                                <?php foreach ($appMotivationOptions as $am): ?>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="app_motivations[]" value="<?php echo $am; ?>" id="eam_<?php echo slugify($am); ?>" <?php echo in_array($am, $selMot) ? 'checked' : ''; ?>><label class="form-check-label small" for="eam_<?php echo slugify($am); ?>"><?php echo $am; ?></label></div></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 10. Recommendation Validation -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Recommendation Validation</div>
                        <div class="card-body py-2">
                            <label class="form-label small fw-medium">What personalized recommendations would be useful?</label>
                            <div class="row">
                                <?php $selRec = $formSubmitted ? ($_POST['recommendation_types'] ?? []) : ($interview['recommendation_types'] ? array_map('trim', explode(',', $interview['recommendation_types'])) : []); ?>
                                <?php foreach ($recommendationOptions as $rc): ?>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="recommendation_types[]" value="<?php echo $rc; ?>" id="erc_<?php echo slugify($rc); ?>" <?php echo in_array($rc, $selRec) ? 'checked' : ''; ?>><label class="form-check-label small" for="erc_<?php echo slugify($rc); ?>"><?php echo $rc; ?></label></div></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 11. Research Notes -->

                    <!-- 9. Research Notes -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Research Notes <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Interview Summary</label>
                                    <textarea name="interview_summary" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['interview_summary'] ?? $interview['interview_summary']); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Important Findings</label>
                                    <textarea name="important_findings" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['important_findings'] ?? $interview['important_findings']); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg"></i> Update Interview</button>
                        <?php if (canSubmitInterview($interview)): ?>
                            <button type="submit" name="submit_interview" value="1" class="btn btn-success px-4"><i class="bi bi-send"></i> Submit</button>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (canEdit()): ?>
<?php
require_once __DIR__ . '/config-media.php';
$galleryItems = buildMediaGallery($pdo, ['interview_id' => $id]);
?>
<?php if (!empty($galleryItems)): ?>
<hr class="my-4">
<h5 class="mb-3"><i class="bi bi-images"></i> Existing Media</h5>
<div class="row g-3">
<?php foreach ($galleryItems as $m):
    $source = $m['source']; // 'photos' or 'media'
    $sourceId = $m['source_id'];
    $caption = $m['caption'] ?? '';
?>
<div class="col-md-4 col-sm-6">
    <div class="card">
        <?php if ($m['type'] === 'image'): ?>
        <img src="<?php echo htmlspecialchars($m['url']); ?>" class="card-img-top" style="height:150px;object-fit:cover" alt="">
        <?php elseif ($m['type'] === MEDIA_TYPE_VIDEO): ?>
        <video src="<?php echo htmlspecialchars($m['url']); ?>" class="card-img-top" style="height:150px;object-fit:cover" controls></video>
        <?php else: ?>
        <div class="card-img-top d-flex align-items-center justify-content-center bg-light" style="height:150px">
            <i class="bi bi-file-earmark-play" style="font-size:2rem"></i>
        </div>
        <?php endif; ?>
        <div class="card-body p-2">
            <div class="d-flex justify-content-between align-items-start">
                <small class="text-muted"><?php echo htmlspecialchars($m['name']); ?></small>
                <div class="d-flex gap-1">
                    <form method="post" class="d-inline" onsubmit="return confirm('Move this media to trash?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo $source === 'photos' ? 'delete_photo' : 'delete_media'; ?>">
                        <input type="hidden" name="<?php echo $source === 'photos' ? 'photo_id' : 'media_id'; ?>" value="<?php echo $sourceId; ?>">
                        <button type="submit" class="btn btn-sm btn-danger py-0 px-1" style="font-size:0.7rem;" title="Move to trash">&times;</button>
                    </form>
                    <?php if ($source === 'media'): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.7rem;" title="Replace file"
                            onclick="replaceMediaItem(<?php echo json_encode($sourceId); ?>)">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" class="mt-1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_caption">
                <input type="hidden" name="source" value="<?php echo $source; ?>">
                <input type="hidden" name="item_id" value="<?php echo $sourceId; ?>">
                <div class="input-group input-group-sm mb-1">
                    <input type="text" name="caption" class="form-control" placeholder="Caption" value="<?php echo htmlspecialchars($caption); ?>">
                    <button type="submit" class="btn btn-outline-primary"><i class="bi bi-check"></i></button>
                </div>
            </form>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_consent">
                <input type="hidden" name="source" value="<?php echo $source; ?>">
                <input type="hidden" name="item_id" value="<?php echo $sourceId; ?>">
                <div class="input-group input-group-sm">
                    <select name="consent" class="form-select form-select-sm">
                        <option value="research" <?php echo ($m['consent'] ?? 'research') === 'research' ? 'selected' : ''; ?>>Research</option>
                        <option value="internal" <?php echo ($m['consent'] ?? '') === 'internal' ? 'selected' : ''; ?>>Internal</option>
                        <option value="none" <?php echo ($m['consent'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                    </select>
                    <button type="submit" class="btn btn-outline-info btn-sm"><i class="bi bi-check"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// Toggle voice use cases visibility on edit
document.querySelectorAll('[name="voice_interest"]').forEach(rb => {
    rb.addEventListener('change', function() {
        const section = document.getElementById('eVoiceUseCasesSection');
        if (section) section.style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});

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

<!-- ═══ Cascading Location JS ═══ -->
<script>
const locationData = <?php echo getLocationDataJson(); ?>;
const provinceDistrictMap = <?php echo json_encode($provMap, JSON_UNESCAPED_UNICODE); ?>;

function filterDistricts(provId, distId, munId, wardId) {
    const pid = document.getElementById(provId).value;
    const dSel = document.getElementById(distId);
    for (const opt of dSel.options) {
        if (!opt.value) { opt.style.display = ''; continue; }
        opt.style.display = (!pid || (provinceDistrictMap[pid] && provinceDistrictMap[pid].includes(opt.value))) ? '' : 'none';
    }
    dSel.value = '';
    loadEIMunicipalities();
}

(function() {
    const dSel = document.getElementById('eiDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('eiProvince').value = pid;
                break;
            }
        }
    }
})();

function loadEIMunicipalities() {
    const dist = document.getElementById('eiDistrict').value;
    const mun = document.getElementById('eiMunicipality');
    const ward = document.getElementById('eiWard');
    mun.innerHTML = '<option value="">--</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });
    }
}

function loadEIWards() {
    const dist = document.getElementById('eiDistrict').value;
    const mun = document.getElementById('eiMunicipality').value;
    const ward = document.getElementById('eiWard');
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += `<option value="${w}">Ward ${w}</option>`;
        });
    }
}

// ─── Interview GPS ──────────────────────────────────────────────
function getEditInterviewGPS() {
    const btn = document.getElementById('eiGpsBtn');
    const status = document.getElementById('eiGpsStatus');
    const coords = document.getElementById('eiGpsCoords');
    if (!navigator.geolocation) { status.innerHTML = '❌ GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = '📍 Getting interview GPS position...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('eiLatField').value = lat;
            document.getElementById('eiLngField').value = lng;
            if (alt) document.getElementById('eiGpsAltField').value = alt;
            document.getElementById('eiGpsAccuracyField').value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
            document.getElementById('eiLocationSourceField').value = 'gps';
            coords.innerHTML = `${lat}, ${lng}${alt ? ' (' + alt + 'm)' : ''}${pos.coords.accuracy ? ' accuracy ' + pos.coords.accuracy.toFixed(0) + 'm' : ''}`;
            status.innerHTML = '✅ Interview GPS captured';
            status.className = 'text-success small';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> GPS OK';
            btn.className = 'btn btn-sm btn-success w-100';
        },
        function(err) {
            if (err.message && err.message.toLowerCase().indexOf('secure origin') !== -1) {
                status.innerHTML = '🔒 GPS requires HTTPS. Enable SSL on your server, or use manual altitude entry.';
                status.className = 'text-warning small';
            } else if (err.code === 1) {
                status.innerHTML = '❌ GPS permission denied. Allow location access in browser settings.';
            } else {
                status.innerHTML = '❌ GPS error: ' + err.message;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt"></i> Retry GPS';
            btn.className = 'btn btn-sm btn-outline-info w-100';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}
</script>

<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
<?php
function slugify($text) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $text);
}
include __DIR__ . '/footer.php'; ?>
