<?php
/**
 * Krishi Sathi Research System - Field Interview Assistant (Refined)
 * 70-80% Checkboxes, 10-20% Radio, 5-10% Free Text
 * Designed for fast data entry during real farmer conversations.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot create interviews.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

$pdo = getDB();
$errors = [];

$farmersStmt = $pdo->prepare("SELECT id, farmer_id, name, district, municipality, ward, tole, latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source, phone, primary_occupation, education_level, age_group, gender FROM research_farmers WHERE " . activeWhere() . " ORDER BY id DESC");
$farmersStmt->execute();
$farmers = $farmersStmt->fetchAll();
$selectedFarmerId = (int) ($_GET['farmer_id'] ?? 0);
$createdInterviewId = (int) ($_GET['created'] ?? 0);
$createdInterviewFarmerId = 0;
if ($createdInterviewId) {
    $stmt = $pdo->prepare("SELECT farmer_id FROM research_interviews WHERE id = ?");
    $stmt->execute([$createdInterviewId]);
    $createdInterviewFarmerId = (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }
    
    // ─── Basic Info ──────────────────────────────────────────────
    $farmer_id       = (int) ($_POST['farmer_id'] ?? 0);
    $interview_date  = $_POST['interview_date'] ?? date('Y-m-d');
    // Build location from cascading fields or plain text as fallback
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
    $interview_round  = $_POST['interview_round'] ?? null;
    if ($interview_round === '') $interview_round = null;
    $interview_mode   = trim($_POST['interview_mode'] ?? '');
    $interview_language = trim($_POST['interview_language'] ?? '');
    $problem_severity = $_POST['problem_severity'] ?? null;
    if ($problem_severity === '' || $problem_severity === '0') $problem_severity = null;
    $loss_amount_npr  = trim($_POST['loss_amount_npr'] ?? '');
    if ($loss_amount_npr === '') $loss_amount_npr = null;

    // Interview status + follow-up
    $status          = normalizeInterviewStatus($_POST['interview_status'] ?? 'draft');
    $submitNow       = isset($_POST['submit_interview']);
    if ($submitNow) $status = 'submitted';
    $followUp        = isset($_POST['follow_up_required']) ? 1 : 0;
    $followUpDate    = $_POST['follow_up_date'] ?? null;
    $followUpReason  = trim($_POST['follow_up_reason'] ?? '');

    // ─── Farm Profile ────────────────────────────────────────────
    $farm_types    = $_POST['farm_type'] ?? [];
    $farm_size     = trim($_POST['farm_size'] ?? '');
    $land_unit     = trim($_POST['land_unit'] ?? '');
    $years_farming = trim($_POST['years_farming'] ?? '');
    $is_commercial = isset($_POST['is_commercial']) ? 1 : 0;

    // ─── Problem Analysis (Checkboxes + Ranking) ────────────────
    $problemChecklist = $_POST['problems'] ?? [];
    $otherProblem     = trim($_POST['problems_other'] ?? '');
    if ($otherProblem !== '') {
        $problemChecklist[] = 'Other: ' . $otherProblem;
    }
    $problemsSelected = implode(', ', $problemChecklist);

    $rank1 = $_POST['problem_rank_1'] ?? '';
    $rank2 = $_POST['problem_rank_2'] ?? '';
    $rank3 = $_POST['problem_rank_3'] ?? '';

    // ─── Loss Analysis ───────────────────────────────────────────
    $loss_causes_selected = $_POST['loss_causes'] ?? [];
    $loss_other_text      = trim($_POST['loss_causes_other'] ?? '');
    if ($loss_other_text !== '') {
        $loss_causes_selected[] = 'Other: ' . $loss_other_text;
    }
    $loss_contributing = implode(', ', $loss_causes_selected);
    $loss_cause       = $_POST['loss_main_cause'] ?? '';
    $loss_main_other  = trim($_POST['loss_main_other'] ?? '');
    if ($loss_cause === 'Other' && $loss_main_other !== '') {
        $loss_cause = 'Other: ' . $loss_main_other;
    }
    $loss_description = trim($_POST['loss_description'] ?? '');
    $loss_amount      = trim($_POST['loss_amount'] ?? '');
    $loss_period      = trim($_POST['loss_period'] ?? '');

    // ─── Record Keeping ──────────────────────────────────────────
    $record_methods = $_POST['record_keeping'] ?? [];
    $record_str     = implode(', ', $record_methods);
    $record_freq    = $_POST['record_frequency'] ?? '';

    // ─── Technology ──────────────────────────────────────────────
    $tech_used = $_POST['technology'] ?? [];
    $tech_str  = implode(', ', $tech_used);
    $smartphone_indep = $_POST['smartphone_independence'] ?? '';

    // ─── Voice Validation ────────────────────────────────────────
    $voice_interest     = $_POST['voice_interest'] ?? '';
    if ($voice_interest === '') $voice_interest = null;
    $voice_reason_opts  = $_POST['voice_reason_options'] ?? [];
    $voice_reason_other = trim($_POST['voice_reason_other'] ?? '');
    if ($voice_reason_other !== '') {
        $voice_reason_opts[] = 'Other: ' . $voice_reason_other;
    }
    $voice_rejections    = $_POST['voice_rejection_reasons'] ?? [];
    $voice_reject_other  = trim($_POST['voice_reject_other'] ?? '');
    if ($voice_reject_other !== '') {
        $voice_rejections[] = 'Other: ' . $voice_reject_other;
    }
    $voice_reason_str   = implode(', ', $voice_reason_opts);
    $voice_rejection_str = implode(', ', $voice_rejections);
    $voice_use_cases    = $_POST['voice_use_cases'] ?? [];
    $voice_uc_other     = trim($_POST['voice_uc_other'] ?? '');
    if ($voice_uc_other !== '') $voice_use_cases[] = 'Other: ' . $voice_uc_other;
    $voice_uc_str       = implode(', ', $voice_use_cases);

    // ─── Reminder Validation ─────────────────────────────────────
    $assumption_reminders = $_POST['assumption_reminders'] ?? '';
    $reminder_types       = $_POST['reminder_types'] ?? [];
    $reminder_other       = trim($_POST['reminder_other'] ?? '');
    if ($reminder_other !== '') {
        $reminder_types[] = 'Other: ' . $reminder_other;
    }
    $reminder_types_str = implode(', ', $reminder_types);

    // ─── Regular App Usage Motivation ────────────────────────────
    $app_motivations    = $_POST['app_motivations'] ?? [];
    $motivation_other   = trim($_POST['motivation_other'] ?? '');
    if ($motivation_other !== '') {
        $app_motivations[] = 'Other: ' . $motivation_other;
    }
    $app_motivations_str = implode(', ', $app_motivations);

    // ─── Personalized Recommendations ────────────────────────────
    $recommendation_types  = $_POST['recommendation_types'] ?? [];
    $recommendation_other  = trim($_POST['recommendation_other'] ?? '');
    if ($recommendation_other !== '') {
        $recommendation_types[] = 'Other: ' . $recommendation_other;
    }
    $recommendation_types_str = implode(', ', $recommendation_types);

    // ─── Research Notes (reduced) ────────────────────────────────
    $interview_summary  = trim($_POST['interview_summary'] ?? '');
    $important_findings = trim($_POST['important_findings'] ?? '');

    // ─── Validation ──────────────────────────────────────────────
    if ($farmer_id <= 0) {
        $errors[] = 'Please select a farmer.';
    } else {
        $checkFarmer = $pdo->prepare("SELECT COUNT(*) FROM research_farmers WHERE id = ? AND " . activeWhere());
        $checkFarmer->execute([$farmer_id]);
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

            // 1. Insert interview
            $stmt = $pdo->prepare("INSERT INTO research_interviews
                (farmer_id, interview_date, interviewer_id, location, duration_minutes,
                 latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source,
                 problems_selected, loss_contributing_causes, loss_cause, loss_description, loss_amount, loss_period,
                 record_keeping_method, record_frequency, technology_used, smartphone_independence,
                 voice_interest, voice_reason_options, voice_rejection_reasons, voice_use_cases,
                 assumption_reminders, reminder_types, app_motivations, recommendation_types,
                 interview_status, follow_up_required, follow_up_date, follow_up_reason,
                 interview_summary, important_findings,
                 interview_round, interview_mode, interview_language, problem_severity, loss_amount_npr)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?)");
            $stmt->execute([
                $farmer_id, $interview_date, currentUserId(), $location, $duration ?: null,
                $latitude ?: null, $longitude ?: null,
                $altitude ?: null, $gps_altitude ?: null,
                $gps_accuracy ?: null, $location_source ?: null,
                $problemsSelected, $loss_contributing, $loss_cause, $loss_description, $loss_amount ?: null, $loss_period ?: null,
                $record_str, $record_freq, $tech_str, $smartphone_indep,
                $voice_interest, $voice_reason_str, $voice_rejection_str, $voice_uc_str,
                $assumption_reminders, $reminder_types_str, $app_motivations_str, $recommendation_types_str,
                $status, $followUp, $followUpDate ?: null, $followUpReason ?: null,
                $interview_summary, $important_findings,
                $interview_round, $interview_mode ?: null, $interview_language ?: null,
                $problem_severity, $loss_amount_npr
            ]);
            $interview_id = $pdo->lastInsertId();

            // 2. Insert problem rankings (top 3)
            $rankings = [];
            if ($rank1 !== '') $rankings[] = ['number' => 1, 'desc' => $rank1];
            if ($rank2 !== '') $rankings[] = ['number' => 2, 'desc' => $rank2];
            if ($rank3 !== '') $rankings[] = ['number' => 3, 'desc' => $rank3];

            if (!empty($rankings)) {
                $stmt = $pdo->prepare("INSERT INTO research_problem_rankings (interview_id, problem_number, problem_description) VALUES (?, ?, ?)");
                foreach ($rankings as $r) {
                    $stmt->execute([$interview_id, $r['number'], $r['desc']]);
                }
            }

            // 3. Farm profile
            $farmTypeStr = is_array($farm_types) ? implode(', ', $farm_types) : $farm_types;
            $check = $pdo->prepare("SELECT id FROM research_farm_profiles WHERE farmer_id = ?");
            $check->execute([$farmer_id]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE research_farm_profiles SET farm_type=?, farm_size=?, land_unit=?, years_farming=?, is_commercial=? WHERE farmer_id=?")
                    ->execute([$farmTypeStr, $farm_size, $land_unit ?: null, $years_farming, $is_commercial, $farmer_id]);
            } else {
                $pdo->prepare("INSERT INTO research_farm_profiles (farmer_id, farm_type, farm_size, land_unit, years_farming, is_commercial) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$farmer_id, $farmTypeStr, $farm_size, $land_unit ?: null, $years_farming, $is_commercial]);
            }

            $pdo->commit();
            logAudit($pdo, 'interview_created', 'interview', $interview_id,
                     'Created interview with Farmer #' . $farmer_id . ' — status: ' . $status);
            clearOld();
            header('Location: ' . BASE_URL . '/interview-add.php?created=' . $interview_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) saveOld($_POST);
}

// ─── Option Lists (loaded from centralized config) ─────────────
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
$interviewDefaults    = getInterviewDefaults();

// Helper for generating safe HTML IDs
function slugify($text) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $text);
}

$pageTitle = 'New Interview - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-plus-circle"></i> New Research Interview</h5>
                <p class="text-muted small mb-3">Field interview assistant — quick selections, minimal typing.</p>

                <?php if ($createdInterviewId): ?>
                    <?php
                    $ciStmt = $pdo->prepare("SELECT f.name, f.farmer_id, f.id as f_pk FROM research_interviews i JOIN research_farmers f ON i.farmer_id = f.id WHERE i.id = ?");
                    $ciStmt->execute([$createdInterviewId]);
                    $ciData = $ciStmt->fetch();
                    ?>
                    <div class="alert alert-success border-0 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                            <h6 class="mb-0">Interview Recorded Successfully!</h6>
                        </div>
                        <p class="small mb-2">Interview with <strong><?php echo htmlspecialchars($ciData['name'] ?? ''); ?></strong> (<?php echo htmlspecialchars($ciData['farmer_id'] ?? ''); ?>) has been saved.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?php echo BASE_URL; ?>/observation-add.php?farmer_id=<?php echo (int) ($ciData['f_pk'] ?? 0); ?>&interview_id=<?php echo $createdInterviewId; ?>" class="btn btn-primary btn-sm"><i class="bi bi-binoculars"></i> Submit &amp; Continue to Observation</a>
                            <a href="<?php echo BASE_URL; ?>/consent-add.php?farmer_id=<?php echo (int) ($ciData['f_pk'] ?? 0); ?>&from=interview" class="btn btn-success btn-sm"><i class="bi bi-check2"></i> Submit &amp; Skip Observation</a>
                            <a href="<?php echo BASE_URL; ?>/interview-view.php?id=<?php echo $createdInterviewId; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View Interview</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?>
                            <div><i class="bi bi-exclamation-circle"></i> <?php echo $e; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($durationWarnings)): ?>
                    <div class="alert alert-info py-2">
                        <?php foreach ($durationWarnings as $w): ?>
                            <div><i class="bi bi-info-circle"></i> <?php echo $w; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="interviewForm">
                    <?php echo csrf_field(); ?>
                    <!-- Track current step for form re-render after validation errors -->
                    <input type="hidden" name="current_step" id="currentStepInput" value="<?php echo (int) old('current_step', 0); ?>">

                    <!-- ═══ Step Progress Indicator ═══ -->
                    <div class="step-indicator" id="stepIndicator">
                        <div class="step-dots" id="stepDots"></div>
                        <div class="step-label" id="stepLabel">Step 1 of 12</div>
                    </div>

                    <!-- ═══ 1. Interview Info + Status + Timer ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-info-circle"></i> Interview Information</span>
                            <span id="timerDisplay" class="badge bg-secondary fs-6">00:00</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small">Farmer <span class="text-danger">*</span></label>
                                    <select name="farmer_id" class="form-select form-select-sm" required onchange="fillLocFromFarmer()">
                                        <option value="">-- Select Farmer --</option>
                                        <?php foreach ($farmers as $f): ?>
                                            <option value="<?php echo $f['id']; ?>"
                                                data-district="<?php echo htmlspecialchars($f['district'] ?? ''); ?>"
                                                data-municipality="<?php echo htmlspecialchars($f['municipality'] ?? ''); ?>"
                                                data-ward="<?php echo htmlspecialchars($f['ward'] ?? ''); ?>"
                                                data-tole="<?php echo htmlspecialchars($f['tole'] ?? ''); ?>"
                                                data-latitude="<?php echo htmlspecialchars($f['latitude'] ?? ''); ?>"
                                                data-longitude="<?php echo htmlspecialchars($f['longitude'] ?? ''); ?>"
                                                data-altitude="<?php echo htmlspecialchars($f['altitude'] ?? ''); ?>"
                                                data-gps-altitude="<?php echo htmlspecialchars($f['gps_altitude'] ?? ''); ?>"
                                                data-gps-accuracy="<?php echo htmlspecialchars($f['gps_accuracy'] ?? ''); ?>"
                                                data-location-source="<?php echo htmlspecialchars($f['location_source'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($f['phone'] ?? ''); ?>"
                                                data-primary-occupation="<?php echo htmlspecialchars($f['primary_occupation'] ?? ''); ?>"
                                                data-education-level="<?php echo htmlspecialchars($f['education_level'] ?? ''); ?>"
                                                data-age-group="<?php echo htmlspecialchars($f['age_group'] ?? ''); ?>"
                                                data-gender="<?php echo htmlspecialchars($f['gender'] ?? ''); ?>"
                                                <?php echo (old('farmer_id') ? (int) old('farmer_id') : $selectedFarmerId) === (int) $f['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($f['name'] . ' (' . $f['farmer_id'] . ')' . ($f['district'] ? ' - ' . $f['district'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><a href="<?php echo BASE_URL; ?>/farmer-quick-add.php?redirect=interview" target="_blank"><i class="bi bi-person-plus"></i> Quick add farmer</a></div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="interview_date" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars(old('interview_date') ?: date('Y-m-d')); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Duration (min)</label>
                                    <input type="number" name="duration_minutes" id="duration_minutes" class="form-control form-control-sm" min="0"
                                           value="<?php echo htmlspecialchars(old('duration_minutes') ?: 30); ?>" placeholder="30">
                                    <div class="d-flex gap-1 mt-1">
                                        <button type="button" id="startTimerBtn" class="btn btn-sm btn-outline-success w-100" onclick="toggleTimer()">
                                            <i class="bi bi-play-fill"></i> Start
                                        </button>
                                        <select name="interview_round" class="form-select form-select-sm" style="max-width:70px;">
                                            <option value="">Rd</option>
                                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                                <option value="<?php echo $r; ?>" <?php echo old('interview_round') == $r ? 'selected' : ''; ?>>R<?php echo $r; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Mode</label>
                                    <select name="interview_mode" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $im = old('interview_mode'); ?>
                                        <option value="in_person" <?php echo $im === 'in_person' || (!$im && !old('interview_mode')) ? 'selected' : ''; ?>>In Person</option>
                                        <option value="phone" <?php echo $im === 'phone' ? 'selected' : ''; ?>>Phone</option>
                                        <option value="video" <?php echo $im === 'video' ? 'selected' : ''; ?>>Video</option>
                                        <option value="other" <?php echo $im === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Language</label>
                                    <select name="interview_language" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $il = old('interview_language'); ?>
                                        <option value="Nepali" <?php echo $il === 'Nepali' || (!$il && !old('interview_language')) ? 'selected' : ''; ?>>Nepali</option>
                                        <option value="English" <?php echo $il === 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="Maithili" <?php echo $il === 'Maithili' ? 'selected' : ''; ?>>Maithili</option>
                                        <option value="Bhojpuri" <?php echo $il === 'Bhojpuri' ? 'selected' : ''; ?>>Bhojpuri</option>
                                        <option value="Tharu" <?php echo $il === 'Tharu' ? 'selected' : ''; ?>>Tharu</option>
                                        <option value="Newar" <?php echo $il === 'Newar' ? 'selected' : ''; ?>>Newar</option>
                                        <option value="Tamang" <?php echo $il === 'Tamang' ? 'selected' : ''; ?>>Tamang</option>
                                        <option value="Other" <?php echo $il === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                <label class="form-label small">Province</label>
                                <select class="form-select form-select-sm" id="iProvince" onchange="filterDistricts('iProvince','iDistrict','iMunicipality','iWard')">
                                    <option value="">-- Select --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Interview District</label>
                                <select name="loc_district" id="iDistrict" class="form-select form-select-sm" onchange="loadIMunicipalities()">
                                        <option value="">-- Select --</option>
                                        <?php foreach (array_keys(getLocationData()) as $d): ?>
                                            <option value="<?php echo $d; ?>" <?php echo old('loc_district') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Municipality</label>
                                    <select name="loc_municipality" id="iMunicipality" class="form-select form-select-sm" onchange="loadIWards()">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Ward</label>
                                    <select name="loc_ward" id="iWard" class="form-select form-select-sm">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tole</label>
                                    <input type="text" name="loc_tole" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('loc_tole')); ?>" placeholder="Optional">
                                </div>
                                <div class="col-md-1 d-flex align-items-end pb-1">
                                    <a href="#" onclick="fillLocFromFarmer(); return false;" class="btn btn-sm btn-outline-info" title="Fill from farmer's profile">
                                        <i class="bi bi-person"></i>
                                    </a>
                                </div>
                            </div>
                            <!-- Farmer Profile Summary Card -->
                            <div id="farmerSummaryCard" class="row g-1 mt-2 d-none">
                                <div class="col-12">
                                    <div class="border rounded p-2 bg-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="small fw-semibold"><i class="bi bi-person-badge"></i> <span id="summaryName"></span></span>
                                            <span class="badge bg-light text-dark" id="summaryFarmerId"></span>
                                        </div>
                                        <div class="row g-1 small text-muted mt-1">
                                            <div class="col-6"><i class="bi bi-geo-alt"></i> <span id="summaryLocation"></span></div>
                                            <div class="col-6"><i class="bi bi-telephone"></i> <span id="summaryPhone"></span></div>
                                            <div class="col-6"><i class="bi bi-briefcase"></i> <span id="summaryOccupation"></span></div>
                                            <div class="col-6"><i class="bi bi-person"></i> <span id="SummaryGenderAge"></span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- GPS + Altitude for interview location -->
                            <input type="hidden" name="latitude" id="iLatField" value="<?php echo htmlspecialchars(old('latitude')); ?>">
                            <input type="hidden" name="longitude" id="iLngField" value="<?php echo htmlspecialchars(old('longitude')); ?>">
                            <input type="hidden" name="gps_altitude" id="iGpsAltField" value="<?php echo htmlspecialchars(old('gps_altitude')); ?>">
                            <input type="hidden" name="gps_accuracy" id="iGpsAccuracyField" value="<?php echo htmlspecialchars(old('gps_accuracy')); ?>">
                            <input type="hidden" name="location_source" id="iLocationSourceField" value="<?php echo htmlspecialchars(old('location_source')); ?>">
                            <div class="row g-2 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label small">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                                    <input type="number" name="altitude" class="form-control form-control-sm" step="0.01"
                                           value="<?php echo htmlspecialchars(old('altitude')); ?>" placeholder="e.g., 1350">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" id="iGpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getInterviewGPS()">
                                        <i class="bi bi-geo-alt"></i> 📍 Interview GPS
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <small id="iGpsStatus" class="text-muted">📍 Interview GPS not captured</small>
                                    <small id="iGpsCoords" class="text-muted d-block"></small>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label small">Status</label>
                                    <select name="interview_status" class="form-select form-select-sm">
                                        <?php $st = old('interview_status'); ?>
                                        <option value="draft" <?php echo ($st === 'draft' || !$st) ? 'selected' : ''; ?>>Draft</option>
                                        <option value="submitted" <?php echo $st === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Follow-Up Required?</label>
                                    <div class="form-check pt-1">
                                        <input class="form-check-input" type="checkbox" name="follow_up_required" value="1" id="fur"
                                               onchange="document.getElementById('followUpDetails').style.display=this.checked?'block':'none'"
                                               <?php echo old('follow_up_required') ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="fur">Schedule follow-up</label>
                                    </div>
                                </div>
                            </div>
                            <div id="followUpDetails" style="display:<?php echo old('follow_up_required') ? 'block' : 'none'; ?>">
                                <div class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label small">Follow-Up Date</label>
                                        <input type="date" name="follow_up_date" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars(old('follow_up_date')); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Reason</label>
                                        <select name="follow_up_reason" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php $furVal = old('follow_up_reason'); ?>
                                            <option value="Need more information" <?php echo $furVal === 'Need more information' ? 'selected' : ''; ?>>Need more information</option>
                                            <option value="Seasonal follow-up" <?php echo $furVal === 'Seasonal follow-up' ? 'selected' : ''; ?>>Seasonal follow-up</option>
                                            <option value="Technology validation" <?php echo $furVal === 'Technology validation' ? 'selected' : ''; ?>>Technology validation</option>
                                            <option value="Voice validation" <?php echo $furVal === 'Voice validation' ? 'selected' : ''; ?>>Voice validation</option>
                                            <option value="Other" <?php echo $furVal === 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 2. Farm Profile ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-house-heart"></i> Farm Profile</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php $selectedTypes = old('farm_type') ? (array) old('farm_type') : $interviewDefaults['farm_type']; ?>
                                        <?php foreach ($farmTypeOptions as $ft): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="farm_type[]" value="<?php echo $ft; ?>" id="ft_<?php echo $ft; ?>"
                                                       <?php echo in_array($ft, $selectedTypes) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="ft_<?php echo $ft; ?>"><?php echo $ft; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Farm Size</label>
                                    <input type="text" name="farm_size" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars(old('farm_size')); ?>" placeholder="e.g., 2">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Land Unit</label>
                                    <select name="land_unit" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $lu = old('land_unit'); ?>
                                        <option value="kattha" <?php echo $lu === 'kattha' || (!$lu && !old('land_unit')) ? 'selected' : ''; ?>>Kattha</option>
                                        <option value="bigha" <?php echo $lu === 'bigha' ? 'selected' : ''; ?>>Bigha</option>
                                        <option value="ropani" <?php echo $lu === 'ropani' ? 'selected' : ''; ?>>Ropani</option>
                                        <option value="hectare" <?php echo $lu === 'hectare' ? 'selected' : ''; ?>>Hectare</option>
                                        <option value="sqft" <?php echo $lu === 'sqft' ? 'selected' : ''; ?>>Sq. Ft.</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Years Farming</label>
                                    <select name="years_farming" class="form-select form-select-sm">
                                        <option value="">Select...</option>
                                        <?php $yf = old('years_farming'); ?>
                                        <option value="Less than 1 year" <?php echo $yf === 'Less than 1 year' ? 'selected' : ''; ?>>Less than 1 year</option>
                                        <option value="1-2 years" <?php echo $yf === '1-2 years' ? 'selected' : ''; ?>>1-2 years</option>
                                        <option value="3-5 years" <?php echo $yf === '3-5 years' ? 'selected' : ''; ?>>3-5 years</option>
                                        <option value="6-10 years" <?php echo $yf === '6-10 years' ? 'selected' : ''; ?>>6-10 years</option>
                                        <option value="11-20 years" <?php echo $yf === '11-20 years' ? 'selected' : ''; ?>>11-20 years</option>
                                        <option value="More than 20 years" <?php echo $yf === 'More than 20 years' ? 'selected' : ''; ?>>More than 20 years</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_commercial" value="1" id="is_commercial" <?php echo old('is_commercial') ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="is_commercial">Commercial Farmer</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 3. Problem Analysis (Checkboxes + Ranking) ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-triangle text-danger"></i> Problem Analysis
                            <span class="text-muted fw-normal small">— tap all that apply, then rank top 3</span></span>
                            <div class="d-flex gap-2 align-items-center">
                                <label class="small text-muted mb-0">Severity:</label>
                                <select name="problem_severity" class="form-select form-select-sm" style="max-width:100px;">
                                    <option value="">—</option>
                                    <?php $ps = old('problem_severity'); ?>
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <option value="<?php echo $s; ?>" <?php echo (string) $ps === (string) $s ? 'selected' : ''; ?>><?php echo $s; ?> <?php echo $s === 1 ? '(Low)' : ($s === 5 ? '(Critical)' : ''); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What problems does the farmer face?</label>
                                    <div class="row">
                                        <?php $selectedProblems = old('problems') ? (array) old('problems') : $interviewDefaults['problems']; ?>
                                        <?php foreach ($problemOptions as $po): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input problem-check" type="checkbox" name="problems[]"
                                                           value="<?php echo $po; ?>" id="prob_<?php echo slugify($po); ?>"
                                                           <?php echo in_array($po, $selectedProblems) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="prob_<?php echo slugify($po); ?>"><?php echo $po; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input problem-check" type="checkbox" name="problems[]" value="Other" id="prob_other"
                                                       <?php echo in_array('Other', $selectedProblems) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="prob_other">Other</label>
                                            </div>
                                            <div id="otherProblemInput" style="display:<?php echo in_array('Other', $selectedProblems) ? 'block' : 'none'; ?>">
                                                <input type="text" name="problems_other" class="form-control form-control-sm mt-1"
                                                       placeholder="Describe the problem..."
                                                       value="<?php echo htmlspecialchars(old('problems_other')); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-2">
                                    <hr class="my-2">
                                    <label class="form-label small fw-medium">Rank top 3 problems</label>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="small text-muted">#1 (most serious)</label>
                                            <select name="problem_rank_1" class="form-select form-select-sm">
                                                <option value="">-- Select --</option>
                                                <?php $r1 = old('problem_rank_1'); ?>
                                                <option value="Disease/Pest Problems" <?php echo $r1 === 'Disease/Pest Problems' ? 'selected' : ''; ?>>Disease/Pest Problems</option>
                                                <option value="Weather Problems" <?php echo $r1 === 'Weather Problems' ? 'selected' : ''; ?>>Weather Problems</option>
                                                <option value="Market Price Issues" <?php echo $r1 === 'Market Price Issues' ? 'selected' : ''; ?>>Market Price Issues</option>
                                                <option value="Labor Shortage" <?php echo $r1 === 'Labor Shortage' ? 'selected' : ''; ?>>Labor Shortage</option>
                                                <option value="High Input Costs" <?php echo $r1 === 'High Input Costs' ? 'selected' : ''; ?>>High Input Costs</option>
                                                <option value="Irrigation Problems" <?php echo $r1 === 'Irrigation Problems' ? 'selected' : ''; ?>>Irrigation Problems</option>
                                                <option value="Lack of Technical Knowledge" <?php echo $r1 === 'Lack of Technical Knowledge' ? 'selected' : ''; ?>>Lack of Technical Knowledge</option>
                                                <option value="Record Keeping Challenges" <?php echo $r1 === 'Record Keeping Challenges' ? 'selected' : ''; ?>>Record Keeping Challenges</option>
                                                <option value="Financial Constraints" <?php echo $r1 === 'Financial Constraints' ? 'selected' : ''; ?>>Financial Constraints</option>
                                                <option value="Input Availability Issues" <?php echo $r1 === 'Input Availability Issues' ? 'selected' : ''; ?>>Input Availability Issues</option>
                                                <option value="Animal Health Problems" <?php echo $r1 === 'Animal Health Problems' ? 'selected' : ''; ?>>Animal Health Problems</option>
                                                <option value="Other" <?php echo $r1 === 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="small text-muted">#2</label>
                                            <select name="problem_rank_2" class="form-select form-select-sm">
                                                <option value="">-- Select --</option>
                                                <?php $r2 = old('problem_rank_2'); ?>
                                                <option value="Disease/Pest Problems" <?php echo $r2 === 'Disease/Pest Problems' ? 'selected' : ''; ?>>Disease/Pest Problems</option>
                                                <option value="Weather Problems" <?php echo $r2 === 'Weather Problems' ? 'selected' : ''; ?>>Weather Problems</option>
                                                <option value="Market Price Issues" <?php echo $r2 === 'Market Price Issues' ? 'selected' : ''; ?>>Market Price Issues</option>
                                                <option value="Labor Shortage" <?php echo $r2 === 'Labor Shortage' ? 'selected' : ''; ?>>Labor Shortage</option>
                                                <option value="High Input Costs" <?php echo $r2 === 'High Input Costs' ? 'selected' : ''; ?>>High Input Costs</option>
                                                <option value="Irrigation Problems" <?php echo $r2 === 'Irrigation Problems' ? 'selected' : ''; ?>>Irrigation Problems</option>
                                                <option value="Lack of Technical Knowledge" <?php echo $r2 === 'Lack of Technical Knowledge' ? 'selected' : ''; ?>>Lack of Technical Knowledge</option>
                                                <option value="Record Keeping Challenges" <?php echo $r2 === 'Record Keeping Challenges' ? 'selected' : ''; ?>>Record Keeping Challenges</option>
                                                <option value="Financial Constraints" <?php echo $r2 === 'Financial Constraints' ? 'selected' : ''; ?>>Financial Constraints</option>
                                                <option value="Input Availability Issues" <?php echo $r2 === 'Input Availability Issues' ? 'selected' : ''; ?>>Input Availability Issues</option>
                                                <option value="Animal Health Problems" <?php echo $r2 === 'Animal Health Problems' ? 'selected' : ''; ?>>Animal Health Problems</option>
                                                <option value="Other" <?php echo $r2 === 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="small text-muted">#3</label>
                                            <select name="problem_rank_3" class="form-select form-select-sm">
                                                <option value="">-- Select --</option>
                                                <?php $r3 = old('problem_rank_3'); ?>
                                                <option value="Disease/Pest Problems" <?php echo $r3 === 'Disease/Pest Problems' ? 'selected' : ''; ?>>Disease/Pest Problems</option>
                                                <option value="Weather Problems" <?php echo $r3 === 'Weather Problems' ? 'selected' : ''; ?>>Weather Problems</option>
                                                <option value="Market Price Issues" <?php echo $r3 === 'Market Price Issues' ? 'selected' : ''; ?>>Market Price Issues</option>
                                                <option value="Labor Shortage" <?php echo $r3 === 'Labor Shortage' ? 'selected' : ''; ?>>Labor Shortage</option>
                                                <option value="High Input Costs" <?php echo $r3 === 'High Input Costs' ? 'selected' : ''; ?>>High Input Costs</option>
                                                <option value="Irrigation Problems" <?php echo $r3 === 'Irrigation Problems' ? 'selected' : ''; ?>>Irrigation Problems</option>
                                                <option value="Lack of Technical Knowledge" <?php echo $r3 === 'Lack of Technical Knowledge' ? 'selected' : ''; ?>>Lack of Technical Knowledge</option>
                                                <option value="Record Keeping Challenges" <?php echo $r3 === 'Record Keeping Challenges' ? 'selected' : ''; ?>>Record Keeping Challenges</option>
                                                <option value="Financial Constraints" <?php echo $r3 === 'Financial Constraints' ? 'selected' : ''; ?>>Financial Constraints</option>
                                                <option value="Input Availability Issues" <?php echo $r3 === 'Input Availability Issues' ? 'selected' : ''; ?>>Input Availability Issues</option>
                                                <option value="Animal Health Problems" <?php echo $r3 === 'Animal Health Problems' ? 'selected' : ''; ?>>Animal Health Problems</option>
                                                <option value="Other" <?php echo $r3 === 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 4. Loss Analysis ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-arrow-down-circle"></i> Loss Analysis</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What contributed to major losses?</label>
                                    <div class="row">
                                        <?php $selCauses = old('loss_causes') ? (array) old('loss_causes') : $interviewDefaults['loss_causes']; ?>
                                        <?php foreach ($lossCauseOptions as $lo): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input loss-check" type="checkbox" name="loss_causes[]" value="<?php echo $lo; ?>" id="lc_<?php echo slugify($lo); ?>"
                                                           <?php echo in_array($lo, $selCauses) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="lc_<?php echo slugify($lo); ?>"><?php echo $lo; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input loss-check" type="checkbox" name="loss_causes[]" value="Other" id="lc_other"
                                                       <?php echo in_array('Other', $selCauses) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="lc_other">Other</label>
                                            </div>
                                            <div id="lossCausesOtherInput" style="display:<?php echo in_array('Other', $selCauses) ? 'block' : 'none'; ?>">
                                                <input type="text" name="loss_causes_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('loss_causes_other')); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mt-2">
                                    <label class="form-label small fw-medium">What was the MAIN cause?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $lmc = old('loss_main_cause'); ?>
                                        <?php foreach ($lossCauseOptions as $lo): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input loss-main-radio" type="radio" name="loss_main_cause" value="<?php echo $lo; ?>" id="lmc_<?php echo slugify($lo); ?>"
                                                       <?php echo $lmc === $lo ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="lmc_<?php echo slugify($lo); ?>"><?php echo $lo; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div id="lossMainOtherInput" style="display:<?php echo $lmc === 'Other' ? 'block' : 'none'; ?>">
                                        <input type="text" name="loss_main_other" class="form-control form-control-sm mt-1" placeholder="Describe..." value="<?php echo htmlspecialchars(old('loss_main_other')); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Description (optional)</label>
                                    <input type="text" name="loss_description" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('loss_description')); ?>" placeholder="Brief details...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Loss amount (optional)</label>
                                    <input type="text" name="loss_amount" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('loss_amount')); ?>" placeholder="e.g., NPR 50,000">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Loss NPR</label>
                                    <input type="number" name="loss_amount_npr" class="form-control form-control-sm" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars(old('loss_amount_npr')); ?>" placeholder="e.g., 50000">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Loss period (optional)</label>
                                    <input type="text" name="loss_period" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('loss_period')); ?>" placeholder="e.g., Last harvest, This year">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 5. Record Keeping ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-journal-text"></i> Record Keeping</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-medium">How does the farmer keep records?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $selectedRecords = old('record_keeping') ? (array) old('record_keeping') : $interviewDefaults['record_keeping']; ?>
                                        <?php foreach ($recordOptions as $ro): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="record_keeping[]" value="<?php echo $ro; ?>" id="rk_<?php echo $ro; ?>"
                                                       <?php echo in_array($ro, $selectedRecords) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="rk_<?php echo $ro; ?>"><?php echo $ro; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">How frequently updated?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $rf = old('record_frequency'); ?>
                                        <?php foreach ($recordFrequencies as $freq): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="record_frequency" value="<?php echo $freq; ?>" id="rf_<?php echo $freq; ?>"
                                                       <?php echo $rf === $freq ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="rf_<?php echo $freq; ?>"><?php echo $freq; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 6. Technology ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-phone"></i> Technology</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-medium">What technology does the farmer use?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $selectedTech = old('technology') ? (array) old('technology') : $interviewDefaults['technology']; ?>
                                        <?php foreach ($techOptions as $to): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="technology[]" value="<?php echo $to; ?>" id="tech_<?php echo $to; ?>"
                                                       <?php echo in_array($to, $selectedTech) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="tech_<?php echo $to; ?>"><?php echo $to; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Can use smartphone independently?</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $si = old('smartphone_independence'); ?>
                                        <?php foreach ($smartphoneOpts as $so): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="smartphone_independence" value="<?php echo $so; ?>" id="si_<?php echo $so; ?>"
                                                       <?php echo $si === $so ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="si_<?php echo $so; ?>"><?php echo $so; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 7. Voice Validation ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">
                            <i class="bi bi-mic"></i> Voice Validation
                            <span class="text-muted fw-normal small">— Critical Module</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">Would you use voice recording for farming activities?</label>
                                    <div class="d-flex gap-3">
                                        <?php $vi = old('voice_interest') ?: $interviewDefaults['voice_interest']; ?>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input voice-radio" type="radio" name="voice_interest" value="yes" id="vi_yes" <?php echo $vi === 'yes' ? 'checked' : ''; ?>>
                                            <label class="form-check-label small text-success fw-medium" for="vi_yes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input voice-radio" type="radio" name="voice_interest" value="maybe" id="vi_maybe" <?php echo $vi === 'maybe' ? 'checked' : ''; ?>>
                                            <label class="form-check-label small text-warning fw-medium" for="vi_maybe">Maybe</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input voice-radio" type="radio" name="voice_interest" value="no" id="vi_no" <?php echo $vi === 'no' ? 'checked' : ''; ?>>
                                            <label class="form-check-label small text-danger fw-medium" for="vi_no">No</label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Reason checkboxes: shown when Yes/Maybe -->
                                <div class="col-12" id="voiceReasonSection" style="display:<?php echo ($vi === 'yes' || $vi === 'maybe') ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">Why would you use voice recording?</label>
                                    <div class="row">
                                        <?php $selReasons = old('voice_reason_options') ? (array) old('voice_reason_options') : $interviewDefaults['voice_reason_options']; ?>
                                        <?php foreach ($voiceReasonOptions as $vr): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="voice_reason_options[]" value="<?php echo $vr; ?>" id="vr_<?php echo slugify($vr); ?>"
                                                           <?php echo in_array($vr, $selReasons) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="vr_<?php echo slugify($vr); ?>"><?php echo $vr; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div id="voiceReasonOtherInput" style="display:<?php echo in_array('Other', $selReasons) ? 'block' : 'none'; ?>">
                                        <input type="text" name="voice_reason_other" class="form-control form-control-sm mt-1" placeholder="Other reason..." value="<?php echo htmlspecialchars(old('voice_reason_other')); ?>">
                                    </div>
                                </div>

                                <!-- Rejection reason checkboxes: shown when No -->
                                <div class="col-12" id="voiceRejectSection" style="display:<?php echo $vi === 'no' ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">Why not?</label>
                                    <div class="row">
                                        <?php $selReject = old('voice_rejection_reasons') ? (array) old('voice_rejection_reasons') : $interviewDefaults['voice_rejection_reasons']; ?>
                                        <?php foreach ($voiceRejectionOptions as $vj): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="voice_rejection_reasons[]" value="<?php echo $vj; ?>" id="vj_<?php echo slugify($vj); ?>"
                                                           <?php echo in_array($vj, $selReject) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="vj_<?php echo slugify($vj); ?>"><?php echo $vj; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div id="voiceRejectOtherInput" style="display:<?php echo in_array('Other', $selReject) ? 'block' : 'none'; ?>">
                                        <input type="text" name="voice_reject_other" class="form-control form-control-sm mt-1" placeholder="Other reason..." value="<?php echo htmlspecialchars(old('voice_reject_other')); ?>">
                                    </div>
                                </div>

                                <!-- Use cases (always visible) -->
                                <div class="col-12">
                                    <label class="form-label small fw-medium">What would the farmer use voice for?</label>
                                    <div class="row">
                                        <?php $selectedUC = old('voice_use_cases') ? (array) old('voice_use_cases') : $interviewDefaults['voice_use_cases']; ?>
                                        <?php foreach ($voiceUseCases as $uc): ?>
                                            <?php if ($uc === 'Other'): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input uc-other-check" type="checkbox" name="voice_use_cases[]" value="Other" id="uc_other"
                                                           onchange="document.getElementById('voiceUCOtherInput').style.display=this.checked?'block':'none'"
                                                           <?php echo in_array('Other', $selectedUC) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="uc_other">Other</label>
                                                </div>
                                                <div id="voiceUCOtherInput" style="display:<?php echo in_array('Other', $selectedUC) ? 'block' : 'none'; ?>">
                                                    <input type="text" name="voice_uc_other" class="form-control form-control-sm mt-1" placeholder="Other use case..." value="<?php echo htmlspecialchars(old('voice_uc_other')); ?>">
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="voice_use_cases[]" value="<?php echo $uc; ?>" id="uc_<?php echo slugify($uc); ?>"
                                                           <?php echo in_array($uc, $selectedUC) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="uc_<?php echo slugify($uc); ?>"><?php echo $uc; ?></label>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 8. Reminder Validation ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">
                            <i class="bi bi-bell text-info"></i> Reminder Validation
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium">Would reminders for farming activities be useful?</label>
                                    <div class="d-flex gap-2">
                                        <?php $ar = old('assumption_reminders') ?: $interviewDefaults['assumption_reminders']; ?>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="yes" id="ar_yes" <?php echo $ar === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="ar_yes">Yes</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="maybe" id="ar_maybe" <?php echo $ar === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="ar_maybe">Maybe</label></div>
                                        <div class="form-check form-check-inline"><input class="form-check-input reminder-radio" type="radio" name="assumption_reminders" value="no" id="ar_no" <?php echo $ar === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="ar_no">No</label></div>
                                    </div>
                                </div>
                                <div class="col-12" id="reminderTypesSection" style="display:<?php echo ($ar === 'yes' || $ar === 'maybe') ? 'block' : 'none'; ?>">
                                    <label class="form-label small fw-medium">What reminders would be useful?</label>
                                    <div class="row">
                                        <?php $selRem = old('reminder_types') ? (array) old('reminder_types') : $interviewDefaults['reminder_types']; ?>
                                        <?php foreach ($reminderTypeOptions as $rt): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reminder_types[]" value="<?php echo $rt; ?>" id="rt_<?php echo slugify($rt); ?>"
                                                           <?php echo in_array($rt, $selRem) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="rt_<?php echo slugify($rt); ?>"><?php echo $rt; ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div id="reminderOtherInput" style="display:<?php echo in_array('Other', $selRem) ? 'block' : 'none'; ?>">
                                        <input type="text" name="reminder_other" class="form-control form-control-sm mt-1" placeholder="Other reminder..." value="<?php echo htmlspecialchars(old('reminder_other')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 9. Regular App Usage Validation ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">
                            <i class="bi bi-phone text-primary"></i> App Usage Motivation
                        </div>
                        <div class="card-body py-2">
                            <label class="form-label small fw-medium">What would motivate regular use of such an app?</label>
                            <div class="row">
                                <?php $selMot = old('app_motivations') ? (array) old('app_motivations') : $interviewDefaults['app_motivations']; ?>
                                <?php foreach ($appMotivationOptions as $am): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="app_motivations[]" value="<?php echo $am; ?>" id="am_<?php echo slugify($am); ?>"
                                                   <?php echo in_array($am, $selMot) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="am_<?php echo slugify($am); ?>"><?php echo $am; ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="app_motivations[]" value="Other" id="am_other"
                                               onchange="document.getElementById('motivationOtherInput').style.display=this.checked?'block':'none'"
                                               <?php echo in_array('Other', $selMot) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="am_other">Other</label>
                                    </div>
                                    <div id="motivationOtherInput" style="display:<?php echo in_array('Other', $selMot) ? 'block' : 'none'; ?>">
                                        <input type="text" name="motivation_other" class="form-control form-control-sm mt-1" placeholder="Other..." value="<?php echo htmlspecialchars(old('motivation_other')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 10. Personalized Recommendation Validation ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">
                            <i class="bi bi-star text-warning"></i> Recommendation Validation
                        </div>
                        <div class="card-body py-2">
                            <label class="form-label small fw-medium">What personalized recommendations would be useful?</label>
                            <div class="row">
                                <?php $selRec = old('recommendation_types') ? (array) old('recommendation_types') : $interviewDefaults['recommendation_types']; ?>
                                <?php foreach ($recommendationOptions as $rc): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="recommendation_types[]" value="<?php echo $rc; ?>" id="rc_<?php echo slugify($rc); ?>"
                                                   <?php echo in_array($rc, $selRec) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="rc_<?php echo slugify($rc); ?>"><?php echo $rc; ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="recommendation_types[]" value="Other" id="rc_other"
                                               onchange="document.getElementById('recommendationOtherInput').style.display=this.checked?'block':'none'"
                                               <?php echo in_array('Other', $selRec) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="rc_other">Other</label>
                                    </div>
                                    <div id="recommendationOtherInput" style="display:<?php echo in_array('Other', $selRec) ? 'block' : 'none'; ?>">
                                        <input type="text" name="recommendation_other" class="form-control form-control-sm mt-1" placeholder="Other..." value="<?php echo htmlspecialchars(old('recommendation_other')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Research Notes (Reduced) ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-journal-richtext"></i> Research Notes <span class="text-muted fw-normal small">— optional</span></div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Interview Summary</label>
                                    <textarea name="interview_summary" class="form-control form-control-sm" rows="2" placeholder="Brief summary..."><?php echo htmlspecialchars(old('interview_summary')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Most Important Findings</label>
                                    <textarea name="important_findings" class="form-control form-control-sm" rows="2" placeholder="Key insights..."><?php echo htmlspecialchars(old('important_findings')); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ 11. Photo Upload (optional) ═══ -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2"><i class="bi bi-camera"></i> Photos <span class="text-muted fw-normal small">— optional, upload after saving</span></div>
                        <div class="card-body py-2">
                            <div class="alert alert-info py-2 small mb-0">
                                <i class="bi bi-info-circle"></i> Photos can be uploaded after saving the interview. Go to the interview view page and use the <strong>Photos</strong> section to upload with consent selection.
                            </div>
                        </div>
                    </div>

                    <!-- Submit Area (shown only on last step) -->
                    <div class="step-submit sticky-submit" id="stepSubmit" style="display:none;">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary flex-fill">
                                <i class="bi bi-save"></i> Save Draft
                            </button>
                            <button type="submit" name="submit_interview" value="1" class="btn btn-success flex-fill">
                                <i class="bi bi-send"></i> Submit Interview
                            </button>
                            <a href="<?php echo BASE_URL; ?>/interviews.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Farmer location helper ═══ -->
<script>
const farmerLocations = <?php
$farmerLocData = [];
foreach ($farmers as $f) {
    $farmerLocData[$f['id']] = [
        'district' => $f['district'] ?? '',
        'municipality' => $f['municipality'] ?? '',
        'ward' => $f['ward'] ?? '',
        'tole' => $f['tole'] ?? '',
        'latitude' => $f['latitude'] ?? '',
        'longitude' => $f['longitude'] ?? '',
        'altitude' => $f['altitude'] ?? '',
        'gps_altitude' => $f['gps_altitude'] ?? '',
        'gps_accuracy' => $f['gps_accuracy'] ?? '',
        'location_source' => $f['location_source'] ?? '',
        'phone' => $f['phone'] ?? '',
        'primary_occupation' => $f['primary_occupation'] ?? '',
        'education_level' => $f['education_level'] ?? '',
        'age_group' => $f['age_group'] ?? '',
        'gender' => $f['gender'] ?? '',
        'name' => $f['name'] ?? '',
        'farmer_id' => $f['farmer_id'] ?? '',
    ];
}
echo json_encode($farmerLocData, JSON_UNESCAPED_UNICODE);
?>;

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
    loadIMunicipalities();
}

(function() {
    const dSel = document.getElementById('iDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('iProvince').value = pid;
                break;
            }
        }
    }
})();

function loadIMunicipalities() {
    const dist = document.getElementById('iDistrict').value;
    const mun = document.getElementById('iMunicipality');
    const ward = document.getElementById('iWard');
    mun.innerHTML = '<option value="">--</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });
        <?php if (old('loc_municipality')): ?>
        mun.value = <?php echo json_encode(old('loc_municipality')); ?>;
        loadIWards();
        <?php endif; ?>
    }
}

function loadIWards() {
    const dist = document.getElementById('iDistrict').value;
    const mun = document.getElementById('iMunicipality').value;
    const ward = document.getElementById('iWard');
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += `<option value="${w}">Ward ${w}</option>`;
        });
        <?php if (old('loc_ward')): ?>
        ward.value = <?php echo json_encode(old('loc_ward')); ?>;
        <?php endif; ?>
    }
}

function fillLocFromFarmer() {
    console.log('[fillLocFromFarmer] called');
    const sel = document.querySelector('[name="farmer_id"]');
    console.log('[fillLocFromFarmer] select element:', sel);
    const opt = sel?.options[sel.selectedIndex];
    const fid = parseInt(sel?.value);
    console.log('[fillLocFromFarmer] fid:', fid);
    const card = document.getElementById('farmerSummaryCard');
    if (!fid || !farmerLocations[fid]) {
        console.warn('[fillLocFromFarmer] no farmer data for fid:', fid, 'keys:', Object.keys(farmerLocations).slice(0, 5));
        if (card) card.classList.add('d-none');
        return;
    }
    const loc = farmerLocations[fid];
    console.log('[fillLocFromFarmer] loc:', loc);
    const tole = document.querySelector('[name="loc_tole"]');
    const altitude = document.querySelector('[name="altitude"]');
    if (tole) tole.value = loc.tole || '';
    if (altitude) altitude.value = loc.altitude || '';
    document.getElementById('iLatField').value = loc.latitude || '';
    document.getElementById('iLngField').value = loc.longitude || '';
    document.getElementById('iGpsAltField').value = loc.gps_altitude || '';
    document.getElementById('iGpsAccuracyField').value = loc.gps_accuracy || '';
    document.getElementById('iLocationSourceField').value = loc.location_source || 'farmer_profile';
    const gpsStatus = document.getElementById('iGpsStatus');
    const gpsCoords = document.getElementById('iGpsCoords');
    if (loc.latitude && loc.longitude) {
        gpsStatus.innerHTML = 'Farmer profile GPS auto-filled';
        gpsStatus.className = 'text-success small';
        gpsCoords.innerHTML = `${loc.latitude}, ${loc.longitude}${loc.gps_accuracy ? ' (accuracy ' + loc.gps_accuracy + 'm)' : ''}`;
    }
    // Inline cascading district → municipality → ward (synchronous, no setTimeout)
    console.log('[fillLocFromFarmer] district:', loc.district, 'exists in locationData:', !!(locationData && loc.district && locationData[loc.district]));
    var distSelect = document.getElementById('iDistrict');
    var munSelect = document.getElementById('iMunicipality');
    var wardSelect = document.getElementById('iWard');
    console.log('[fillLocFromFarmer] district select found:', !!distSelect, 'mun select found:', !!munSelect, 'ward select found:', !!wardSelect);
    if (loc.district && locationData && locationData[loc.district]) {
        distSelect.value = loc.district;
        munSelect.innerHTML = '<option value="">--</option>';
        wardSelect.innerHTML = '<option value="">--</option>';
        var munKeys = Object.keys(locationData[loc.district]);
        for (var mi = 0; mi < munKeys.length; mi++) {
            munSelect.innerHTML += '<option value="' + munKeys[mi] + '">' + munKeys[mi] + '</option>';
        }
        if (loc.municipality) {
            var munFound = false;
            for (var mi2 = 0; mi2 < munSelect.options.length; mi2++) {
                if (munSelect.options[mi2].value === loc.municipality) {
                    munSelect.value = loc.municipality;
                    wardSelect.innerHTML = '<option value="">--</option>';
                    if (locationData[loc.district][loc.municipality]) {
                        var wards = locationData[loc.district][loc.municipality];
                        for (var wi = 0; wi < wards.length; wi++) {
                            wardSelect.innerHTML += '<option value="' + wards[wi] + '">Ward ' + wards[wi] + '</option>';
                        }
                    }
                    if (loc.ward) {
                        for (var wi2 = 0; wi2 < wardSelect.options.length; wi2++) {
                            if (wardSelect.options[wi2].value == loc.ward) {
                                wardSelect.value = loc.ward; break;
                            }
                        }
                    }
                    munFound = true;
                    break;
                }
            }
            if (!munFound) {
                for (var mi3 = 0; mi3 < munSelect.options.length; mi3++) {
                    if (munSelect.options[mi3].value.toLowerCase().indexOf(loc.municipality.toLowerCase()) !== -1 ||
                        loc.municipality.toLowerCase().indexOf(munSelect.options[mi3].value.toLowerCase()) !== -1) {
                        munSelect.value = munSelect.options[mi3].value;
                        break;
                    }
                }
            }
        }
    } else {
        distSelect.value = '';
        munSelect.innerHTML = '<option value="">--</option>';
        wardSelect.innerHTML = '<option value="">--</option>';
    }
    // Update summary card
    if (card) {
        document.getElementById('summaryName').textContent = loc.name || '';
        document.getElementById('summaryFarmerId').textContent = loc.farmer_id || '';
        const parts = [];
        if (loc.district) parts.push(loc.district);
        if (loc.municipality) parts.push(loc.municipality);
        if (loc.ward) parts.push('Ward ' + loc.ward);
        if (loc.tole) parts.push(loc.tole);
        document.getElementById('summaryLocation').textContent = parts.join(', ') || '—';
        document.getElementById('summaryPhone').textContent = loc.phone || '—';
        document.getElementById('summaryOccupation').textContent = loc.primary_occupation || '—';
        const ageGender = [];
        if (loc.age_group) ageGender.push(loc.age_group);
        if (loc.gender) ageGender.push(loc.gender);
        document.getElementById('SummaryGenderAge').textContent = ageGender.join(', ') || '—';
        card.classList.remove('d-none');
    }
}

function ready(fn) {
    if (document.readyState !== 'loading') setTimeout(fn, 0);
    else document.addEventListener('DOMContentLoaded', fn);
}
ready(function() {
            <?php if (old('loc_district')): ?>
    document.getElementById('iDistrict').value = <?php echo json_encode(old('loc_district')); ?>;
    loadIMunicipalities();
    <?php elseif ($selectedFarmerId): ?>
    fillLocFromFarmer();
    <?php endif; ?>
});
</script>

<!-- ═══ Toggle Logic (vanilla JS, no framework) ═══ -->
<script>
// Problem: show/hide Other text input
document.querySelectorAll('.problem-check').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.id === 'prob_other') {
            document.getElementById('otherProblemInput').style.display = this.checked ? 'block' : 'none';
        }
    });
});

// Loss causes: show/hide Other text input
document.querySelectorAll('.loss-check').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.id === 'lc_other') {
            document.getElementById('lossCausesOtherInput').style.display = this.checked ? 'block' : 'none';
        }
    });
});

// Loss main cause: show/hide Other text input
document.querySelectorAll('.loss-main-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('lossMainOtherInput').style.display = this.value === 'Other' ? 'block' : 'none';
    });
});

// Voice: toggle reason section vs rejection section based on interest
document.querySelectorAll('.voice-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        const val = this.value;
        document.getElementById('voiceReasonSection').style.display = (val === 'yes' || val === 'maybe') ? 'block' : 'none';
        document.getElementById('voiceRejectSection').style.display = val === 'no' ? 'block' : 'none';
    });
});

// Voice reason: show/hide Other text
document.querySelectorAll('[name^="voice_reason_options"]').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.value === 'Other') {
            document.getElementById('voiceReasonOtherInput').style.display = this.checked ? 'block' : 'none';
        }
    });
});

// Voice rejection: show/hide Other text
document.querySelectorAll('[name^="voice_rejection_reasons"]').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.value === 'Other') {
            document.getElementById('voiceRejectOtherInput').style.display = this.checked ? 'block' : 'none';
        }
    });
});

// Reminder: toggle reminder type checkboxes
document.querySelectorAll('.reminder-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('reminderTypesSection').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});

// Reminder types: show/hide Other text
document.querySelectorAll('[name^="reminder_types"]').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.value === 'Other') {
            document.getElementById('reminderOtherInput').style.display = this.checked ? 'block' : 'none';
        }
    });
});
</script>

<!-- ═══ Interview Timer ═══ -->
<script>
let timerInterval = null;
let seconds = 0;
let timerRunning = false;

function toggleTimer() {
    const btn = document.getElementById('startTimerBtn');
    if (!timerRunning) {
        timerRunning = true;
        btn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop Timer';
        btn.className = 'btn btn-sm btn-danger mt-1 w-100';
        timerInterval = setInterval(function() {
            seconds++;
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            document.getElementById('timerDisplay').textContent =
                String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }, 1000);
    } else {
        clearInterval(timerInterval);
        timerRunning = false;
        const mins = Math.round(seconds / 60);
        document.getElementById('duration_minutes').value = mins > 0 ? mins : 1;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> ' + mins + ' min recorded';
        btn.className = 'btn btn-sm btn-success mt-1 w-100';
        btn.disabled = true;
    }
}

// ─── Reverse Geocode (OSM Nominatim) ───────────────────────────
function reverseGeocode(lat, lng, prefix) {
    const url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&addressdetails=1&accept-language=en';
    fetch(url, { headers: { 'User-Agent': 'KrishiSathiResearch/1.0' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.address) return;
            const addr = data.address;
            let district = addr.county || addr.state_district || addr.region || '';
            let municipality = addr.city || addr.town || addr.municipality || addr.village || addr.locality || '';
            district = district.replace(/ District$/i, '').trim();
            municipality = municipality.replace(/ Municipality$/i, '').trim();
            
            const distSelect = document.getElementById(prefix + 'District');
            const munSelect = document.getElementById(prefix + 'Municipality');
            if (!distSelect) return;
            
            var bestDist = '';
            var bestDistScore = 0;
            var distLower = district.toLowerCase();
            for (var d in locationData) {
                var dl = d.toLowerCase();
                var score = 0;
                if (dl === distLower) score = 100;
                else if (dl.indexOf(distLower) !== -1 || distLower.indexOf(dl) !== -1) score = 80;
                else if (dl.substring(0, 4) === distLower.substring(0, 4)) score = 50;
                if (score > bestDistScore) { bestDistScore = score; bestDist = d; }
            }
            if (bestDist && bestDistScore >= 50) {
                distSelect.value = bestDist;
                if (typeof loadIMunicipalities === 'function') {
                    loadIMunicipalities();
                }
                setTimeout(function() {
                    if (!munSelect) return;
                    var bestMun = '';
                    var bestMunScore = 0;
                    var munLower = municipality.toLowerCase();
                    for (var i = 0; i < munSelect.options.length; i++) {
                        var ml = munSelect.options[i].value.toLowerCase();
                        if (!ml) continue;
                        var score = 0;
                        if (ml === munLower) score = 100;
                        else if (ml.indexOf(munLower) !== -1 || munLower.indexOf(ml) !== -1) score = 80;
                        else if (ml.substring(0, 4) === munLower.substring(0, 4)) score = 50;
                        if (score > bestMunScore) { bestMunScore = score; bestMun = munSelect.options[i].value; }
                    }
                    if (bestMun && bestMunScore >= 50) {
                        munSelect.value = bestMun;
                        if (typeof loadIWards === 'function') loadIWards();
                    }
                }, 300);
            }
        })
        .catch(function(err) {
            console.log('Reverse geocode failed (non-critical):', err);
        });
}

// ─── Interview GPS ──────────────────────────────────────────────
function getInterviewGPS() {
    const btn = document.getElementById('iGpsBtn');
    const status = document.getElementById('iGpsStatus');
    const coords = document.getElementById('iGpsCoords');
    if (!navigator.geolocation) { status.innerHTML = '❌ GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = '📍 Getting interview GPS position...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('iLatField').value = lat;
            document.getElementById('iLngField').value = lng;
            if (alt) {
                document.getElementById('iGpsAltField').value = alt;
                var visAlt = document.querySelector('[name="altitude"]');
                if (visAlt) visAlt.value = alt;
            }
            document.getElementById('iGpsAccuracyField').value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
            document.getElementById('iLocationSourceField').value = 'gps';
            coords.innerHTML = `${lat}, ${lng}${alt ? ' (' + alt + 'm)' : ''}${pos.coords.accuracy ? ' accuracy ' + pos.coords.accuracy.toFixed(0) + 'm' : ''}`;
            status.innerHTML = '✅ Interview GPS captured';
            status.className = 'text-success small';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> GPS OK';
            btn.className = 'btn btn-sm btn-success w-100';
            // Reverse geocode to auto-fill district & municipality
            reverseGeocode(lat, lng, 'i');
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

<!-- ═══ Step-by-Step Progressive Form (Mobile Wizard) ═══ -->
<script>
(function() {
    'use strict';
    
    var cards = document.querySelectorAll('#interviewForm .card.bg-light');
    var totalSteps = cards.length;
    if (totalSteps < 2) return;
    
    var currentStep = 0;
    
    var stepNames = [
        'Interview Info',
        'Farm Profile',
        'Problem Analysis',
        'Loss Analysis',
        'Record Keeping',
        'Technology',
        'Voice Validation',
        'Reminder Validation',
        'App Motivation',
        'Recommendations',
        'Research Notes',
        'Photos'
    ];
    
    var stepDots = document.getElementById('stepDots');
    var stepLabel = document.getElementById('stepLabel');
    var stepSubmit = document.getElementById('stepSubmit');
    if (!stepDots || !stepLabel) return;
    
    // Create step dots
    for (var i = 0; i < totalSteps; i++) {
        var dot = document.createElement('div');
        dot.className = 'step-dot' + (i === 0 ? ' active' : '');
        dot.dataset.index = i;
        stepDots.appendChild(dot);
    }
    
    // Create navigation bar
    var navBar = document.createElement('div');
    navBar.className = 'step-nav';
    navBar.innerHTML = '<div class="d-flex gap-2">' +
        '<button type="button" class="btn btn-outline-secondary btn-back flex-fill" onclick="prevStep()">' +
        '<i class="bi bi-chevron-left"></i> Back</button>' +
        '<button type="button" class="btn btn-success btn-next flex-fill" onclick="nextStep()">' +
        'Next <i class="bi bi-chevron-right"></i></button>' +
        '</div>';
    
    // Insert nav bar before the submit area
    var form = document.getElementById('interviewForm');
    var submitDiv = document.getElementById('stepSubmit');
    if (form && submitDiv) {
        form.insertBefore(navBar, submitDiv);
    }
    
    var backBtn = navBar.querySelector('.btn-back');
    
    function goToStep(step) {
        currentStep = step;
        
        for (var i = 0; i < totalSteps; i++) {
            cards[i].style.display = i === step ? '' : 'none';
        }
        
        // Update dots
        var dots = stepDots.querySelectorAll('.step-dot');
        for (var i = 0; i < dots.length; i++) {
            dots[i].className = 'step-dot';
            if (i < step) dots[i].classList.add('completed');
            if (i === step) dots[i].classList.add('active');
        }
        
        // Update label
        stepLabel.textContent = 'Step ' + (step + 1) + ' of ' + totalSteps + ' — ' + (stepNames[step] || '');
        
        // Show/hide nav and submit buttons
        var isLastStep = step === totalSteps - 1;
        navBar.style.display = isLastStep ? 'none' : '';
        stepSubmit.style.display = isLastStep ? '' : 'none';
        backBtn.style.display = step === 0 ? 'none' : '';
        
        // Scroll to top of form
        var formTop = form ? form.getBoundingClientRect().top + window.pageYOffset - 70 : 0;
        window.scrollTo({ top: formTop, behavior: 'smooth' });
    }
    
    // Expose navigation functions globally
    window.nextStep = function() {
        if (currentStep < totalSteps - 1) {
            goToStep(currentStep + 1);
        }
    };
    
    window.prevStep = function() {
        if (currentStep > 0) {
            goToStep(currentStep - 1);
        }
    };
    
    // Track current step in hidden input for form re-render
    var stepInput = document.getElementById('currentStepInput');
    
    // Clear any stale auto-save draft from previous sessions
    try { localStorage.removeItem('krishi_interview_draft'); } catch(e) {}
    
    // Initialize - restore saved step or start at 0
    var savedStep = stepInput ? parseInt(stepInput.value) : 0;
    if (isNaN(savedStep) || savedStep < 0 || savedStep >= totalSteps) savedStep = 0;
    goToStep(savedStep);
    
    // Update hidden input on each step change
    var origGoTo = goToStep;
    goToStep = function(step) {
        origGoTo(step);
        if (stepInput) stepInput.value = step;
    };
    
    // Handle keyboard: Enter on non-last-step triggers Next; on last step submits form
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var active = document.activeElement;
            if (active && active.closest('#interviewForm') && !active.closest('textarea')) {
                if (currentStep < totalSteps - 1) {
                    e.preventDefault();
                    window.nextStep();
                }
            }
        }
    });
})();
</script>

<script src="<?php echo BASE_URL; ?>/assets/js/interview-draft.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
<?php include __DIR__ . '/footer.php'; ?>
