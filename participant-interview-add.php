<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: Add Interview
 *
 * Phase 3: Interview Engine — 10-Step Wizard
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/config-participant-questions.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot create interviews.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

$pdo = getDB();
$errors = [];
$durationWarnings = [];

// Clear stale session data on fresh form load — prevents old() from re-populating fields
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clearOld();
}

$participantsStmt = $pdo->prepare("SELECT id, participant_id, name, participant_type, organization, phone, district, municipality, ward, tole, latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source, primary_role, experience_years FROM research_participants p WHERE " . visibleParticipantWhere() . " ORDER BY name ASC");
$participantsStmt->execute();
$participants = $participantsStmt->fetchAll();
$selectedParticipantId = (int) ($_GET['participant_id'] ?? 0);
$createdInterviewId = (int) ($_GET['created'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }

    // Step 1: Basic Info
    $participant_id    = (int) ($_POST['participant_id'] ?? 0);
    $interview_date    = $_POST['interview_date'] ?? date('Y-m-d');
    $locDistrict       = trim($_POST['loc_district'] ?? '');
    $locMunicipality   = trim($_POST['loc_municipality'] ?? '');
    $locWard           = trim($_POST['loc_ward'] ?? '');
    $locTole           = trim($_POST['loc_tole'] ?? '');
    $locPlain          = trim($_POST['location'] ?? '');
    $latitude          = $_POST['latitude'] ?? null;
    $longitude         = $_POST['longitude'] ?? null;
    $altitude          = trim($_POST['altitude'] ?? '');
    $gps_altitude      = $_POST['gps_altitude'] ?? null;
    $gps_accuracy      = $_POST['gps_accuracy'] ?? null;
    $location_source   = trim($_POST['location_source'] ?? '');
    if ($altitude === '') $altitude = null;
    if ($locDistrict) {
        $location = $locDistrict;
        if ($locMunicipality) $location .= ', ' . $locMunicipality;
        if ($locWard) $location .= ', Ward ' . $locWard;
        if ($locTole) $location .= ', ' . $locTole;
    } else {
        $location = $locPlain;
    }
    $duration          = (int) ($_POST['duration_minutes'] ?? 0);
    $status            = normalizeParticipantInterviewStatus($_POST['interview_status'] ?? 'draft');
    $submitNow         = isset($_POST['submit_interview']);
    if ($submitNow) $status = 'submitted';

    // Section 2: Problems Observed
    $problems_observed       = $_POST['problems_observed'] ?? [];
    $problems_increasing     = $_POST['problems_increasing'] ?? [];
    $problems_greatest_losses = $_POST['problems_greatest_losses'] ?? [];

    $problems_observed_str       = implode(', ', $problems_observed);
    $problems_increasing_str     = implode(', ', $problems_increasing);
    $problems_greatest_losses_str = implode(', ', $problems_greatest_losses);

    // Section 3: Decision Making
    $hardest_decisions      = $_POST['hardest_decisions'] ?? [];
    $decision_difficulties  = $_POST['decision_difficulties'] ?? [];
    $hardest_decisions_str  = implode(', ', $hardest_decisions);
    $decision_difficulties_str = implode(', ', $decision_difficulties);

    // Section 4: Trust & Information
    $info_sources     = $_POST['info_sources'] ?? [];
    $trusted_sources  = $_POST['trusted_sources'] ?? [];
    $late_info        = $_POST['late_info'] ?? [];
    $info_sources_str     = implode(', ', $info_sources);
    $trusted_sources_str  = implode(', ', $trusted_sources);
    $late_info_str        = implode(', ', $late_info);

    // Section 5: Record Keeping
    $record_methods   = $_POST['record_methods'] ?? [];
    $important_recs   = $_POST['important_records'] ?? [];
    $record_barriers  = $_POST['record_barriers'] ?? [];
    $record_methods_str   = implode(', ', $record_methods);
    $important_recs_str   = implode(', ', $important_recs);
    $record_barriers_str  = implode(', ', $record_barriers);

    // Section 6: Technology Adoption
    $tech_used    = $_POST['tech_used'] ?? [];
    $tech_barriers = $_POST['tech_barriers'] ?? [];
    $tech_used_str    = implode(', ', $tech_used);
    $tech_barriers_str = implode(', ', $tech_barriers);

    // Section 7: Voice Validation
    $voice_recording_useful       = $_POST['voice_recording_useful'] ?? '';
    $voice_recording_uses         = $_POST['voice_recording_uses'] ?? [];
    $voice_recommendations_useful = $_POST['voice_recommendations_useful'] ?? '';
    $voice_recording_uses_str     = implode(', ', $voice_recording_uses);

    // Section 8: Krishi Sathi Validation
    $reminders_valuable      = $_POST['reminders_valuable'] ?? '';
    $useful_reminders        = $_POST['useful_reminders'] ?? [];
    $weekly_recs_useful      = $_POST['weekly_recs_useful'] ?? '';
    $valuable_recommendations = $_POST['valuable_recommendations'] ?? [];
    $most_valuable_feature   = $_POST['most_valuable_feature'] ?? [];
    $useful_reminders_str        = implode(', ', $useful_reminders);
    $valuable_recommendations_str = implode(', ', $valuable_recommendations);
    $most_valuable_feature_str   = implode(', ', $most_valuable_feature);

    // Section 10: Researcher Findings
    $interview_summary          = trim($_POST['interview_summary'] ?? '');
    $major_findings             = trim($_POST['major_findings'] ?? '');
    $contradictions             = trim($_POST['contradictions'] ?? '');
    $new_research_opportunities = trim($_POST['new_research_opportunities'] ?? '');
    $product_opportunities      = trim($_POST['product_opportunities'] ?? '');
    $recommended_action         = $_POST['recommended_action'] ?? [];
    $research_importance        = $_POST['research_importance'] ?? '';
    $interview_quality          = $_POST['interview_quality'] ?? '';
    $follow_up_needed           = isset($_POST['follow_up_needed']) ? 1 : 0;
    $follow_up_notes            = trim($_POST['follow_up_notes'] ?? '');
    $recommended_action_str     = implode(', ', $recommended_action);

    // Validation
    if ($participant_id <= 0) {
        $errors[] = 'Please select a participant.';
    } else {
        $check = $pdo->prepare("SELECT COUNT(*) FROM research_participants WHERE id = ? AND " . activeWhere());
        $check->execute([$participant_id]);
        if ((int) $check->fetchColumn() === 0) {
            $errors[] = 'Selected participant is deleted or unavailable.';
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

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Insert interview
            $stmt = $pdo->prepare("INSERT INTO research_participant_interviews
                (participant_id, interviewer_id, interview_date, location, duration_minutes,
                 latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source,
                 problems_observed, problems_increasing, problems_greatest_losses,
                 hardest_decisions, decision_difficulty_reasons,
                 info_sources, trusted_sources, info_arrives_late,
                 record_keeping_methods, important_records, record_keeping_barriers,
                 technologies_used, tech_adoption_barriers,
                 voice_recording_useful, voice_recording_uses, voice_recommendations_useful,
                 reminders_valuable, useful_reminders, weekly_recs_useful, valuable_recommendations, most_valuable_feature,
                 interview_summary, major_findings, contradictions, new_research_opportunities, product_opportunities,
                 recommended_action, research_importance, interview_quality, follow_up_needed, follow_up_notes,
                 current_step, interview_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $participant_id, currentUserId(), $interview_date, $location, $duration ?: null,
                $latitude ?: null, $longitude ?: null,
                $altitude ?: null, $gps_altitude ?: null, $gps_accuracy ?: null, $location_source ?: null,
                $problems_observed_str ?: null, $problems_increasing_str ?: null, $problems_greatest_losses_str ?: null,
                $hardest_decisions_str ?: null, $decision_difficulties_str ?: null,
                $info_sources_str ?: null, $trusted_sources_str ?: null, $late_info_str ?: null,
                $record_methods_str ?: null, $important_recs_str ?: null, $record_barriers_str ?: null,
                $tech_used_str ?: null, $tech_barriers_str ?: null,
                $voice_recording_useful ?: null, $voice_recording_uses_str ?: null, $voice_recommendations_useful ?: null,
                $reminders_valuable ?: null, $useful_reminders_str ?: null,
                $weekly_recs_useful ?: null, $valuable_recommendations_str ?: null, $most_valuable_feature_str ?: null,
                $interview_summary ?: null, $major_findings ?: null, $contradictions ?: null,
                $new_research_opportunities ?: null, $product_opportunities ?: null,
                $recommended_action_str ?: null, $research_importance ?: null, $interview_quality ?: null,
                $follow_up_needed, $follow_up_notes ?: null,
                (int) ($_POST['current_step'] ?? 0),
                $status,
            ]);
            $interview_id = $pdo->lastInsertId();

            // Save role-specific responses (Section 9)
            $participant = $pdo->prepare("SELECT participant_type FROM research_participants WHERE id = ?");
            $participant->execute([$participant_id]);
            $pType = $participant->fetchColumn();

            $roleQuestions = getRoleSpecificQuestions($pType ?: 'Other');
            $responseStmt = $pdo->prepare("INSERT INTO research_participant_responses
                (interview_id, question_group, question_key, response_value) VALUES (?, ?, ?, ?)");

            foreach ($roleQuestions as $q) {
                $key = $q['key'];
                $val = '';
                if ($q['type'] === 'checkbox') {
                    $selected = $_POST[$key] ?? [];
                    $val = implode(', ', $selected);
                } elseif ($q['type'] === 'radio') {
                    $val = $_POST[$key] ?? '';
                } elseif ($q['type'] === 'text' || $q['type'] === 'textarea') {
                    $val = trim($_POST[$key] ?? '');
                }
                if ($val !== '') {
                    $responseStmt->execute([$interview_id, $pType, $key, $val]);
                }
            }

            $pdo->commit();
            logAudit($pdo, 'participant_interview_created', 'participant_interview', $interview_id,
                     'Created interview with ' . ($pType ?: 'Unknown') . ' — status: ' . $status);

            clearOld();
            header('Location: ' . BASE_URL . '/participant-interview-add.php?created=' . $interview_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    if (!empty($errors)) saveOld($_POST);
}

$participantDefaults = getParticipantInterviewDefaults();
$pageTitle = 'New Stakeholder Interview - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-plus-circle"></i> New Stakeholder Interview</h5>
                <p class="text-muted small mb-3">10-step interview wizard — checkbox-first design for fast data collection.</p>

                <?php if ($createdInterviewId): ?>
                    <?php
                    $ciStmt = $pdo->prepare("SELECT p.name, p.participant_id, p.id as f_pk FROM research_participant_interviews i JOIN research_participants p ON i.participant_id = p.id WHERE i.id = ?");
                    $ciStmt->execute([$createdInterviewId]);
                    $ciData = $ciStmt->fetch();
                    ?>
                    <div class="alert alert-success border-0 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                            <h6 class="mb-0">Interview Recorded Successfully!</h6>
                        </div>
                        <p class="small mb-2">Interview with <strong><?php echo htmlspecialchars($ciData['name'] ?? ''); ?></strong> (<?php echo htmlspecialchars($ciData['participant_id'] ?? ''); ?>) has been saved.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?php echo BASE_URL; ?>/consent-add.php?participant_id=<?php echo (int) ($ciData['f_pk'] ?? 0); ?>&from=interview" class="btn btn-success btn-sm"><i class="bi bi-check2"></i> Continue to Consent</a>
                            <a href="<?php echo BASE_URL; ?>/participant-interview-view.php?id=<?php echo $createdInterviewId; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View Interview</a>
                            <a href="<?php echo BASE_URL; ?>/participant-interview-add.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-circle"></i> Start New Interview</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
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
                    <input type="hidden" name="current_step" id="currentStepInput" value="<?php echo (int) old('current_step', 0); ?>">

                    <!-- Step Indicator -->
                    <div class="step-indicator mb-3" id="stepIndicator">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="step-dots d-flex gap-1" id="stepDots"></div>
                            <div class="step-label small text-muted fw-medium" id="stepLabel"></div>
                        </div>
                    </div>

                    <!-- ═══ STEP 1: PARTICIPANT INFO ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-info-circle"></i> Step 1: Participant Info</span>
                            <span class="badge bg-secondary step-badge">1/10</span>
                            <span id="timerDisplay" class="badge bg-info fs-6">00:00</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small" for="participant_id_select">Participant <span class="text-danger">*</span></label>
                                    <select name="participant_id" id="participant_id_select" class="form-select form-select-sm" required>
                                        <option value="">-- Select Participant --</option>
                                        <?php foreach ($participants as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"
                                                data-ptype="<?php echo htmlspecialchars($p['participant_type']); ?>"
                                                data-organization="<?php echo htmlspecialchars($p['organization'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($p['phone'] ?? ''); ?>"
                                                data-district="<?php echo htmlspecialchars($p['district'] ?? ''); ?>"
                                                data-municipality="<?php echo htmlspecialchars($p['municipality'] ?? ''); ?>"
                                                data-ward="<?php echo htmlspecialchars($p['ward'] ?? ''); ?>"
                                                data-tole="<?php echo htmlspecialchars($p['tole'] ?? ''); ?>"
                                                data-latitude="<?php echo htmlspecialchars($p['latitude'] ?? ''); ?>"
                                                data-longitude="<?php echo htmlspecialchars($p['longitude'] ?? ''); ?>"
                                                data-altitude="<?php echo htmlspecialchars($p['altitude'] ?? ''); ?>"
                                                data-gps-altitude="<?php echo htmlspecialchars($p['gps_altitude'] ?? ''); ?>"
                                                data-gps-accuracy="<?php echo htmlspecialchars($p['gps_accuracy'] ?? ''); ?>"
                                                data-location-source="<?php echo htmlspecialchars($p['location_source'] ?? ''); ?>"
                                                data-primary-role="<?php echo htmlspecialchars($p['primary_role'] ?? ''); ?>"
                                                data-experience-years="<?php echo htmlspecialchars($p['experience_years'] ?? ''); ?>"
                                                <?php echo ((int) old('participant_id', $selectedParticipantId)) === (int) $p['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['name'] . ' (' . $p['participant_id'] . ') — ' . $p['participant_type']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <!-- Profile Summary Card -->
                                    <div id="participantSummaryCard" class="border rounded p-2 bg-white mt-2 d-none">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="small fw-semibold"><i class="bi bi-person-badge"></i> <span id="pSummaryName"></span></span>
                                            <span class="badge bg-light text-dark" id="pSummaryType"></span>
                                        </div>
                                        <div class="row g-1 small text-muted mt-1">
                                            <div class="col-6"><i class="bi bi-building"></i> <span id="pSummaryOrg"></span></div>
                                            <div class="col-6"><i class="bi bi-briefcase"></i> <span id="pSummaryRole"></span></div>
                                            <div class="col-6"><i class="bi bi-geo-alt"></i> <span id="pSummaryLocation"></span></div>
                                            <div class="col-6"><i class="bi bi-telephone"></i> <span id="pSummaryPhone"></span></div>
                                            <div class="col-6"><i class="bi bi-clock-history"></i> Exp: <span id="pSummaryExperience"></span></div>
                                            <div class="col-6"><i class="bi bi-satellite"></i> GPS: <span id="pSummaryGps"></span></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="interview_date_input">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="interview_date" id="interview_date_input" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars(old('interview_date') ?: date('Y-m-d')); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="duration_minutes">Duration (min)</label>
                                    <input type="number" name="duration_minutes" id="duration_minutes" class="form-control form-control-sm" min="0"
                                           value="<?php echo htmlspecialchars(old('duration_minutes') ?: 30); ?>" placeholder="30">
                                    <button type="button" id="startTimerBtn" class="btn btn-sm btn-outline-success mt-1 w-100" onclick="toggleTimer()">
                                        <i class="bi bi-play-fill"></i> Start Timer
                                    </button>
                                </div>
                                <div class="col-md-4">
                                <label class="form-label small" for="iProvince">Province</label>
                                <select class="form-select form-select-sm" id="iProvince" onchange="filterDistricts('iProvince','iDistrict','iMunicipality','iWard')">
                                    <option value="">-- Select --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="iDistrict">District</label>
                                <select name="loc_district" id="iDistrict" class="form-select form-select-sm" onchange="loadIMunicipalities()">
                                        <option value="">-- Select --</option>
                                        <?php foreach (array_keys(getLocationData()) as $d): ?>
                                            <option value="<?php echo $d; ?>" <?php echo old('loc_district') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="iMunicipality">Municipality</label>
                                    <select name="loc_municipality" id="iMunicipality" class="form-select form-select-sm" onchange="loadIWards()">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="iWard">Ward</label>
                                    <select name="loc_ward" id="iWard" class="form-select form-select-sm">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="loc_tole_input">Tole</label>
                                    <input type="text" name="loc_tole" id="loc_tole_input" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('loc_tole')); ?>">
                                </div>
                            </div>
                            <input type="hidden" name="latitude" id="iLatField" value="<?php echo htmlspecialchars(old('latitude')); ?>">
                            <input type="hidden" name="longitude" id="iLngField" value="<?php echo htmlspecialchars(old('longitude')); ?>">
                            <input type="hidden" name="gps_altitude" id="iGpsAltField" value="<?php echo htmlspecialchars(old('gps_altitude')); ?>">
                            <input type="hidden" name="gps_accuracy" id="iGpsAccuracyField" value="<?php echo htmlspecialchars(old('gps_accuracy')); ?>">
                            <input type="hidden" name="location_source" id="iLocationSourceField" value="<?php echo htmlspecialchars(old('location_source')); ?>">
                            <div class="row g-2 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label small" for="altitude_manual_input">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                                    <input type="number" name="altitude" id="altitude_manual_input" class="form-control form-control-sm" step="0.01" value="<?php echo htmlspecialchars(old('altitude')); ?>" placeholder="e.g., 1350">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" id="iGpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getInterviewGPS()">
                                        <i class="bi bi-geo-alt"></i> 📍 Capture GPS
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <small id="iGpsStatus" class="text-muted">📍 GPS not captured</small>
                                    <small id="iGpsCoords" class="text-muted d-block"></small>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label small" for="interview_status_select">Status</label>
                                    <select name="interview_status" id="interview_status_select" class="form-select form-select-sm">
                                        <?php $st = old('interview_status'); ?>
                                        <option value="draft" <?php echo ($st === 'draft' || !$st) ? 'selected' : ''; ?>>Draft</option>
                                        <option value="submitted" <?php echo $st === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 2: PROBLEMS OBSERVED ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-triangle text-danger"></i> Step 2: Problems Observed</span>
                            <span class="badge bg-secondary step-badge">2/10</span>
                        </div>
                        <div class="card-body py-2">
                            <span class="form-label small fw-medium">What problems do farmers face most often?</span>
                            <div class="row g-1"><?php $sel = old('problems_observed') ? (array) old('problems_observed') : $participantDefaults['problems_observed'];
                                foreach (getProblemsObservedOptions() as $o): ?>
                                <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="problems_observed[]" value="<?php echo $o; ?>" id="pobs_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="pobs_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div></div>
                                <?php endforeach; ?></div>
                        </div>
                    </div>

                    <!-- ═══ STEP 3: PROBLEMS DETAIL ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-arrow-up-circle text-warning"></i> Step 3: Problems Detail</span>
                            <span class="badge bg-secondary step-badge">3/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Which problems are increasing?</span>
<?php $sel2 = old('problems_increasing') ? (array) old('problems_increasing') : $participantDefaults['problems_increasing'];
foreach (getProblemsIncreasingOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="problems_increasing[]" value="<?php echo $o; ?>" id="pinc_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel2) ? 'checked' : ''; ?>><label class="form-check-label small" for="pinc_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Which cause the GREATEST losses?</span>
<?php $sel3 = old('problems_greatest_losses') ? (array) old('problems_greatest_losses') : $participantDefaults['problems_greatest_losses'];
foreach (getProblemsGreatestLossesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="problems_greatest_losses[]" value="<?php echo $o; ?>" id="ploss_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel3) ? 'checked' : ''; ?>><label class="form-check-label small" for="ploss_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 4: DECISION MAKING ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-question-circle text-info"></i> Step 4: Decision Making</span>
                            <span class="badge bg-secondary step-badge">4/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">What decisions are hardest for farmers?</span>
<?php $sel4 = old('hardest_decisions') ? (array) old('hardest_decisions') : $participantDefaults['hardest_decisions'];
foreach (getHardestDecisionsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="hardest_decisions[]" value="<?php echo $o; ?>" id="hd_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel4) ? 'checked' : ''; ?>><label class="form-check-label small" for="hd_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Why are these decisions difficult?</span>
<?php $sel5 = old('decision_difficulties') ? (array) old('decision_difficulties') : $participantDefaults['decision_difficulties'];
foreach (getDecisionDifficultyOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="decision_difficulties[]" value="<?php echo $o; ?>" id="dd_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel5) ? 'checked' : ''; ?>><label class="form-check-label small" for="dd_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 5: TRUST & INFORMATION ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-share text-primary"></i> Step 5: Trust & Information</span>
                            <span class="badge bg-secondary step-badge">5/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Where do farmers get information?</span>
<?php $sel6 = old('info_sources') ? (array) old('info_sources') : $participantDefaults['info_sources'];
foreach (getInfoSourcesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="info_sources[]" value="<?php echo $o; ?>" id="is_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel6) ? 'checked' : ''; ?>><label class="form-check-label small" for="is_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Which sources do they trust most?</span>
<?php $sel7 = old('trusted_sources') ? (array) old('trusted_sources') : $participantDefaults['trusted_sources'];
foreach (getTrustedSourcesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="trusted_sources[]" value="<?php echo $o; ?>" id="ts_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel7) ? 'checked' : ''; ?>><label class="form-check-label small" for="ts_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">What info arrives too late?</span>
<?php $sel8 = old('late_info') ? (array) old('late_info') : $participantDefaults['late_info'];
foreach (getLateInformationOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="late_info[]" value="<?php echo $o; ?>" id="li_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel8) ? 'checked' : ''; ?>><label class="form-check-label small" for="li_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 6: RECORD KEEPING ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-journal-text"></i> Step 6: Record Keeping</span>
                            <span class="badge bg-secondary step-badge">6/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">How do farmers keep records?</span>
<?php $sel9 = old('record_methods') ? (array) old('record_methods') : $participantDefaults['record_methods'];
foreach (getRecordKeepingMethodsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="record_methods[]" value="<?php echo $o; ?>" id="rm_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel9) ? 'checked' : ''; ?>><label class="form-check-label small" for="rm_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Which records are most important?</span>
<?php $sel10 = old('important_records') ? (array) old('important_records') : $participantDefaults['important_records'];
foreach (getImportantRecordsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="important_records[]" value="<?php echo $o; ?>" id="ir_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel10) ? 'checked' : ''; ?>><label class="form-check-label small" for="ir_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">What prevents proper record keeping?</span>
<?php $sel11 = old('record_barriers') ? (array) old('record_barriers') : $participantDefaults['record_barriers'];
foreach (getRecordKeepingBarriersOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="record_barriers[]" value="<?php echo $o; ?>" id="rb_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel11) ? 'checked' : ''; ?>><label class="form-check-label small" for="rb_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 7: TECHNOLOGY ADOPTION ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-phone"></i> Step 7: Technology Adoption</span>
                            <span class="badge bg-secondary step-badge">7/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">What technologies are farmers already using?</span>
<?php $sel12 = old('tech_used') ? (array) old('tech_used') : $participantDefaults['tech_used'];
foreach (getTechnologiesUsedOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="tech_used[]" value="<?php echo $o; ?>" id="tu_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel12) ? 'checked' : ''; ?>><label class="form-check-label small" for="tu_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">What prevents technology adoption?</span>
<?php $sel13 = old('tech_barriers') ? (array) old('tech_barriers') : $participantDefaults['tech_barriers'];
foreach (getTechAdoptionBarriersOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="tech_barriers[]" value="<?php echo $o; ?>" id="tb_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel13) ? 'checked' : ''; ?>><label class="form-check-label small" for="tb_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 8: VOICE & VALIDATION ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-mic text-success"></i> Step 8: Voice & Krishi Sathi Validation</span>
                            <span class="badge bg-secondary step-badge">8/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Would voice recording be useful?</span>
                                    <?php $vru = old('voice_recording_useful') ?: $participantDefaults['voice_recording_useful']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="yes" id="vru_yes" <?php echo $vru === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="vru_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="maybe" id="vru_maybe" <?php echo $vru === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="vru_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="no" id="vru_no" <?php echo $vru === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="vru_no">No</label></div>
                                    </div>
                                    <div id="voiceUsesSection" style="display:<?php echo ($vru === 'yes' || $vru === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">What should voice be used for?</span>
<?php $vru2 = old('voice_recording_uses') ? (array) old('voice_recording_uses') : $participantDefaults['voice_recording_uses'];
    foreach (getVoiceRecordingUsesOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="voice_recording_uses[]" value="<?php echo $o; ?>" id="vru2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $vru2) ? 'checked' : ''; ?>><label class="form-check-label small" for="vru2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Would reminders be valuable?</span>
                                    <?php $rv = old('reminders_valuable') ?: $participantDefaults['reminders_valuable']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="yes" id="rv_yes" <?php echo $rv === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="rv_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="maybe" id="rv_maybe" <?php echo $rv === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="rv_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="no" id="rv_no" <?php echo $rv === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="rv_no">No</label></div>
                                    </div>
                                    <div id="reminderTypesSection" style="display:<?php echo ($rv === 'yes' || $rv === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">Which reminders?</span>
<?php $rv2 = old('useful_reminders') ? (array) old('useful_reminders') : $participantDefaults['useful_reminders'];
    foreach (getUsefulRemindersOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="useful_reminders[]" value="<?php echo $o; ?>" id="rv2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $rv2) ? 'checked' : ''; ?>><label class="form-check-label small" for="rv2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Would weekly recommendations be useful?</span>
                                    <?php $wru = old('weekly_recs_useful') ?: $participantDefaults['weekly_recs_useful']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="yes" id="wru_yes" <?php echo $wru === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="wru_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="maybe" id="wru_maybe" <?php echo $wru === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="wru_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="no" id="wru_no" <?php echo $wru === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="wru_no">No</label></div>
                                    </div>
                                    <div id="valuableRecsSection" style="display:<?php echo ($wru === 'yes' || $wru === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">Which recommendations?</span>
<?php $wru2 = old('valuable_recommendations') ? (array) old('valuable_recommendations') : $participantDefaults['valuable_recommendations'];
    foreach (getValuableRecommendationsOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="valuable_recommendations[]" value="<?php echo $o; ?>" id="wru2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $wru2) ? 'checked' : ''; ?>><label class="form-check-label small" for="wru2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="form-label small mt-2">Most Valuable Future Feature?</span>
<?php $mvf = old('most_valuable_feature') ? (array) old('most_valuable_feature') : $participantDefaults['most_valuable_feature'];
foreach (getMostValuableFeatureOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="most_valuable_feature[]" value="<?php echo $o; ?>" id="mvf_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $mvf) ? 'checked' : ''; ?>><label class="form-check-label small" for="mvf_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 9: ROLE-SPECIFIC QUESTIONS (dynamic) ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-person-badge text-dark"></i> Step 9: Role-Specific Questions</span>
                            <span class="badge bg-secondary step-badge">9/10</span>
                        </div>
                        <div class="card-body py-2" id="roleSpecificContainer">
                            <p class="small text-muted mb-0"><i class="bi bi-person-badge"></i> Select a participant on Step 1 to see role-specific questions.</p>
                        </div>
                    </div>

                    <!-- ═══ STEP 10: RESEARCHER FINDINGS ═══ -->
                    <div class="card bg-light mb-3 border-0 step-card">
                        <div class="card-header bg-transparent fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-journal-richtext"></i> Step 10: Research Findings</span>
                            <span class="badge bg-secondary step-badge">10/10</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small" for="interview_summary_text">Interview Summary</label>
                                    <textarea name="interview_summary" id="interview_summary_text" class="form-control form-control-sm" rows="2" placeholder="Key discussion points..."><?php echo htmlspecialchars(old('interview_summary')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small" for="major_findings_text">Major Findings</label>
                                    <textarea name="major_findings" id="major_findings_text" class="form-control form-control-sm" rows="2" placeholder="Major insights, unexpected findings..."><?php echo htmlspecialchars(old('major_findings')); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="contradictions_text">Contradictions Observed</label>
                                    <textarea name="contradictions" id="contradictions_text" class="form-control form-control-sm" rows="2" placeholder="..."><?php echo htmlspecialchars(old('contradictions')); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="new_research_text">New Research Opportunities</label>
                                    <textarea name="new_research_opportunities" id="new_research_text" class="form-control form-control-sm" rows="2" placeholder="..."><?php echo htmlspecialchars(old('new_research_opportunities')); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="product_opportunities_text">Product Opportunities</label>
                                    <textarea name="product_opportunities" id="product_opportunities_text" class="form-control form-control-sm" rows="2" placeholder="..."><?php echo htmlspecialchars(old('product_opportunities')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Recommended Action</span>
<?php $ra = old('recommended_action') ? (array) old('recommended_action') : $participantDefaults['recommended_action'];
foreach (getRecommendedActionOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="recommended_action[]" value="<?php echo $o; ?>" id="ra_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $ra) ? 'checked' : ''; ?>><label class="form-check-label small" for="ra_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="research_importance_select">Importance</label>
                                    <?php $ri = old('research_importance'); ?>
                                    <select name="research_importance" id="research_importance_select" class="form-select form-select-sm">
                                        <option value="">--</option>
                                        <?php foreach (getResearchImportanceOptions() as $o): ?>
                                            <option value="<?php echo $o; ?>" <?php echo $ri === $o ? 'selected' : ''; ?>><?php echo $o; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="interview_quality_select">Quality</label>
                                    <?php $iq = old('interview_quality'); ?>
                                    <select name="interview_quality" id="interview_quality_select" class="form-select form-select-sm">
                                        <option value="">--</option>
                                        <?php foreach (getInterviewQualityOptions() as $o): ?>
                                            <option value="<?php echo $o; ?>" <?php echo $iq === $o ? 'selected' : ''; ?>><?php echo $o; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="follow_up_needed" value="1" id="fun" <?php echo old('follow_up_needed') ? 'checked' : ''; ?>
                                               onchange="document.getElementById('followUpNotes').style.display=this.checked?'block':'none'">
                                        <label class="form-check-label small" for="fun">Follow-up Needed</label>
                                    </div>
                                </div>
                                <div class="col-12" id="followUpNotes" style="display:<?php echo old('follow_up_needed') ? 'block' : 'none'; ?>">
                                    <label class="form-label small" for="follow_up_notes_text">Follow-up Notes</label>
                                    <textarea name="follow_up_notes" id="follow_up_notes_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars(old('follow_up_notes')); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation + Submit -->
                    <div class="step-nav d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary btn-back flex-fill" onclick="prevStep()" style="display:none;">
                            <i class="bi bi-chevron-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-success btn-next flex-fill" onclick="nextStep()">
                            Next <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                    <div class="step-submit sticky-submit mt-3" style="display:none;">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary flex-fill"><i class="bi bi-save"></i> Save Draft</button>
                            <button type="submit" name="submit_interview" value="1" class="btn btn-success flex-fill"><i class="bi bi-send"></i> Submit Interview</button>
                            <a href="<?php echo BASE_URL; ?>/participant-interviews.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/phone-validation.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
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

function getInterviewGPS() {
    const btn = document.getElementById('iGpsBtn');
    const status = document.getElementById('iGpsStatus');
    const coords = document.getElementById('iGpsCoords');
    if (!navigator.geolocation) { status.innerHTML = '❌ GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('iLatField').value = lat;
            document.getElementById('iLngField').value = lng;
            if (alt) { document.getElementById('iGpsAltField').value = alt; document.querySelector('[name="altitude"]').value = alt; }
            document.getElementById('iGpsAccuracyField').value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
            document.getElementById('iLocationSourceField').value = 'gps';
            coords.innerHTML = `${lat}, ${lng}${alt ? ' (' + alt + 'm)' : ''}`;
            status.innerHTML = '✅ GPS captured';
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
                status.innerHTML = '❌ GPS error: ' + (err.message || 'unknown');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt"></i> Retry';
            btn.className = 'btn btn-sm btn-outline-info w-100';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

// ─── Interview Timer ──────────────────────────────────────────
let timerInterval = null, seconds = 0, timerRunning = false;
function toggleTimer() {
    const btn = document.getElementById('startTimerBtn');
    if (!timerRunning) {
        timerRunning = true;
        btn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop Timer';
        btn.className = 'btn btn-sm btn-danger mt-1 w-100';
        timerInterval = setInterval(function() {
            seconds++;
            document.getElementById('timerDisplay').textContent =
                String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
        }, 1000);
    } else {
        clearInterval(timerInterval); timerRunning = false;
        const mins = Math.round(seconds / 60);
        document.getElementById('duration_minutes').value = mins > 0 ? mins : 1;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> ' + mins + ' min';
        btn.className = 'btn btn-sm btn-success mt-1 w-100';
        btn.disabled = true;
    }
}

// ─── Voice/Reminder/Rec toggles ───────────────────────────────
document.querySelectorAll('.voice-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('voiceUsesSection').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});
document.querySelectorAll('.reminder-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('reminderTypesSection').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});
document.querySelectorAll('.rec-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('valuableRecsSection').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});

// ─── Role-Specific Questions Data ─────────────────────────────
const roleQuestions = <?php
    $allRoleQuestions = [];
    foreach (getParticipantTypes() as $type) {
        $allRoleQuestions[$type] = getRoleSpecificQuestions($type);
    }
    echo json_encode($allRoleQuestions, JSON_UNESCAPED_UNICODE);
?>;

function slugifyRSQ(str) {
    return str.replace(/[^a-zA-Z0-9]/g, '_');
}

function renderRoleSpecificQuestions() {
    var sel = document.querySelector('[name="participant_id"]');
    var opt = sel.options[sel.selectedIndex];
    var type = opt ? opt.getAttribute('data-ptype') : '';
    var container = document.getElementById('roleSpecificContainer');
    if (!type || !roleQuestions[type] || !roleQuestions[type].length) {
        container.innerHTML = '<p class="small text-muted mb-0"><i class="bi bi-person-badge"></i> Select a participant to see role-specific questions.</p>';
        return;
    }
    var questions = roleQuestions[type];
    var html = '';
    questions.forEach(function(q, idx) {
        var key = q.key.replace(/[^a-zA-Z0-9_]/g, '');
        html += '<div class="mb-2">';
        html += '<label class="form-label small fw-medium" for="rsq_' + key + '_input">' + q.label + '</label>';
        if (q.type === 'checkbox') {
            html += '<div class="row g-1">';
            q.options.forEach(function(o) {
                var safeOpt = slugifyRSQ(o);
                html += '<div class="col-md-6"><div class="form-check">';
                html += '<input class="form-check-input" type="checkbox" name="' + key + '[]" value="' + o + '" id="rsq_' + key + '_' + safeOpt + '">';
                html += '<label class="form-check-label small" for="rsq_' + key + '_' + safeOpt + '">' + o + '</label></div></div>';
            });
            html += '</div>';
        } else if (q.type === 'radio') {
            html += '<div class="d-flex flex-wrap gap-2">';
            q.options.forEach(function(o) {
                var safeOpt = slugifyRSQ(o);
                html += '<div class="form-check">';
                html += '<input class="form-check-input" type="radio" name="' + key + '" value="' + o + '" id="rsq_' + key + '_' + safeOpt + '">';
                html += '<label class="form-check-label small" for="rsq_' + key + '_' + safeOpt + '">' + o + '</label></div>';
            });
            html += '</div>';
        } else {
            html += (q.type === 'textarea')
                ? '<textarea name="' + key + '" id="rsq_' + key + '_input" class="form-control form-control-sm" rows="2" placeholder="..."></textarea>'
                : '<input type="text" name="' + key + '" id="rsq_' + key + '_input" class="form-control form-control-sm" placeholder="...">';
        }
        html += '</div>';
    });
    container.innerHTML = html;
    // Initialize bulk controls for the dynamically added checkboxes
    if (window.initCheckboxBulkControls) {
        window.initCheckboxBulkControls();
    }
}

function autoFillParticipantLocation() {
    console.log('[autoFillParticipantLocation] called');
    var sel = document.querySelector('[name="participant_id"]');
    console.log('[autoFillParticipantLocation] select:', sel, 'value:', sel?.value);
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        console.warn('[autoFillParticipantLocation] no option selected');
        document.getElementById('participantSummaryCard')?.classList.add('d-none');
        return;
    }
    var district = opt.getAttribute('data-district') || '';
    var municipality = opt.getAttribute('data-municipality') || '';
    var ward = opt.getAttribute('data-ward') || '';
    var tole = opt.getAttribute('data-tole') || '';
    console.log('[autoFillParticipantLocation] data-district:', district, 'data-municipality:', municipality, 'data-ward:', ward, 'data-tole:', tole);
    var altitude = opt.getAttribute('data-altitude') || '';
    var latitude = opt.getAttribute('data-latitude') || '';
    var longitude = opt.getAttribute('data-longitude') || '';
    var gpsAlt = opt.getAttribute('data-gps-altitude') || '';
    var gpsAcc = opt.getAttribute('data-gps-accuracy') || '';
    var locSource = opt.getAttribute('data-location-source') || '';

    // Set non-cascading fields immediately
    document.getElementById('loc_tole_input').value = tole;
    document.getElementById('altitude_manual_input').value = altitude;
    document.getElementById('iLatField').value = latitude;
    document.getElementById('iLngField').value = longitude;
    document.getElementById('iGpsAltField').value = gpsAlt;
    document.getElementById('iGpsAccuracyField').value = gpsAcc;
    document.getElementById('iLocationSourceField').value = locSource;

    if (latitude && longitude) {
        var coordsEl = document.getElementById('iGpsCoords');
        if (coordsEl) coordsEl.innerHTML = latitude + ', ' + longitude + (gpsAlt ? ' (' + gpsAlt + 'm)' : '');
        var statusEl = document.getElementById('iGpsStatus');
        if (statusEl) { statusEl.innerHTML = 'Loaded from profile'; statusEl.className = 'text-success small'; }
    }

    // Handle cascading district → municipality → ward
    console.log('[autoFillParticipantLocation] locationData:', typeof locationData, locationData ? Object.keys(locationData).slice(0, 3) : 'undefined');
    // Inlined to avoid PHP timing conditions in shared loadIMunicipalities/loadIWards
    var distSelect = document.getElementById('iDistrict');
    var munSelect = document.getElementById('iMunicipality');
    var wardSelect = document.getElementById('iWard');
    console.log('[autoFillParticipantLocation] district select:', !!distSelect, 'mun select:', !!munSelect, 'ward select:', !!wardSelect);
    var cascadeData = locationData;

    if (district && cascadeData && cascadeData[district]) {
        distSelect.value = district;
        // Populate municipality dropdown
        munSelect.innerHTML = '<option value="">--</option>';
        wardSelect.innerHTML = '<option value="">--</option>';
        var munKeys = Object.keys(cascadeData[district]);
        for (var mi = 0; mi < munKeys.length; mi++) {
            munSelect.innerHTML += '<option value="' + munKeys[mi] + '">' + munKeys[mi] + '</option>';
        }
        // Select municipality
        if (municipality) {
            var munFound = false;
            for (var mi2 = 0; mi2 < munSelect.options.length; mi2++) {
                if (munSelect.options[mi2].value === municipality) {
                    munSelect.value = municipality;
                    // Populate ward dropdown
                    wardSelect.innerHTML = '<option value="">--</option>';
                    if (cascadeData[district][municipality]) {
                        var wards = cascadeData[district][municipality];
                        for (var wi = 0; wi < wards.length; wi++) {
                            wardSelect.innerHTML += '<option value="' + wards[wi] + '">Ward ' + wards[wi] + '</option>';
                        }
                    }
                    // Select ward
                    if (ward) {
                        for (var wi2 = 0; wi2 < wardSelect.options.length; wi2++) {
                            if (wardSelect.options[wi2].value == ward) {
                                wardSelect.value = ward;
                                break;
                            }
                        }
                    }
                    munFound = true;
                    break;
                }
            }
            // Fallback: fuzzy match if exact match fails
            if (!munFound) {
                for (var mi3 = 0; mi3 < munSelect.options.length; mi3++) {
                    if (munSelect.options[mi3].value.toLowerCase().indexOf(municipality.toLowerCase()) !== -1 ||
                        municipality.toLowerCase().indexOf(munSelect.options[mi3].value.toLowerCase()) !== -1) {
                        munSelect.value = munSelect.options[mi3].value;
                        break;
                    }
                }
            }
        }
    } else {
        console.warn('[autoFillParticipantLocation] district not found in locationData:', district);
        distSelect.value = '';
        munSelect.innerHTML = '<option value="">--</option>';
        wardSelect.innerHTML = '<option value="">--</option>';
    }

    // Update summary card
    var card = document.getElementById('participantSummaryCard');
    if (card) {
        document.getElementById('pSummaryName').textContent = opt.textContent.split(' — ')[0] || '';
        document.getElementById('pSummaryType').textContent = opt.getAttribute('data-ptype') || '';
        document.getElementById('pSummaryOrg').textContent = opt.getAttribute('data-organization') || '—';
        document.getElementById('pSummaryRole').textContent = opt.getAttribute('data-primary-role') || '—';
        var pLocParts = [];
        if (district) pLocParts.push(district);
        if (municipality) pLocParts.push(municipality);
        if (ward) pLocParts.push('Ward ' + ward);
        if (tole) pLocParts.push(tole);
        document.getElementById('pSummaryLocation').textContent = pLocParts.join(', ') || '—';
        document.getElementById('pSummaryPhone').textContent = opt.getAttribute('data-phone') || '—';
        document.getElementById('pSummaryExperience').textContent = opt.getAttribute('data-experience-years') || '—';
        document.getElementById('pSummaryGps').textContent = (latitude && longitude) ? 'Yes (' + latitude + ', ' + longitude + ')' : 'Not captured';
        card.classList.remove('d-none');
    }
}

function ready(fn) {
    if (document.readyState !== 'loading') setTimeout(fn, 0);
    else document.addEventListener('DOMContentLoaded', fn);
}
ready(function() {
    var participantSelect = document.querySelector('[name="participant_id"]');
    if (participantSelect) {
        participantSelect.addEventListener('change', function() {
            renderRoleSpecificQuestions();
            autoFillParticipantLocation();
        });
        if (participantSelect.value !== '') {
            renderRoleSpecificQuestions();
            autoFillParticipantLocation();
        }
    }
});

// ─── Step-by-Step Navigation ─────────────────────────────────
(function() {
    var cards = document.querySelectorAll('.step-card');
    var totalSteps = cards.length;
    if (totalSteps < 2) return;
    var currentStep = 0;
    var stepNames = ['Participant Info','Problems Observed','Problems Detail','Decision Making','Trust & Information','Record Keeping','Technology Adoption','Voice & Validation','Role-Specific','Research Findings'];

    var stepDots = document.getElementById('stepDots');
    var stepLabel = document.getElementById('stepLabel');
    var stepSubmit = document.querySelector('.step-submit');
    var stepNav = document.querySelector('.step-nav');
    var backBtn = stepNav ? stepNav.querySelector('.btn-back') : null;
    var nextBtn = stepNav ? stepNav.querySelector('.btn-next') : null;

    for (var i = 0; i < totalSteps; i++) {
        var dot = document.createElement('span');
        dot.className = 'step-dot badge bg-secondary me-1' + (i === 0 ? ' active-dot' : '');
        dot.style.opacity = i === 0 ? '1' : '0.3';
        stepDots.appendChild(dot);
    }

    function goToStep(step) {
        currentStep = step;
        document.getElementById('currentStepInput').value = step;
        for (var i = 0; i < totalSteps; i++) {
            cards[i].style.display = i === step ? '' : 'none';
        }
        var dots = stepDots.querySelectorAll('.step-dot');
        for (var i = 0; i < dots.length; i++) {
            dots[i].className = 'step-dot badge bg-secondary me-1' + (i === step ? ' active-dot' : '');
            dots[i].style.opacity = i <= step ? '1' : '0.3';
        }
        stepLabel.textContent = 'Step ' + (step + 1) + ': ' + (stepNames[step] || '');
        var isLast = step === totalSteps - 1;
        if (stepNav) stepNav.style.display = isLast ? 'none' : '';
        if (stepSubmit) stepSubmit.style.display = isLast ? '' : 'none';
        if (backBtn) backBtn.style.display = step === 0 ? 'none' : '';
    }

    window.nextStep = function() { if (currentStep < totalSteps - 1) { goToStep(currentStep + 1); } };
    window.prevStep = function() { if (currentStep > 0) { goToStep(currentStep - 1); } };

    var savedStep = parseInt(document.getElementById('currentStepInput').value);
    if (isNaN(savedStep) || savedStep < 0 || savedStep >= totalSteps) savedStep = 0;
    goToStep(savedStep);

    document.addEventListener('DOMContentLoaded', function() {
        <?php if (old('loc_district')): ?>loadIMunicipalities();<?php endif; ?>
    });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
