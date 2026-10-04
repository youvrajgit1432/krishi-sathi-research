<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: Edit Interview
 *
 * Phase 3: Interview Engine
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/config-participant-questions.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot edit interviews.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$interview = $pdo->prepare("SELECT pi.*, p.name AS participant_name, p.participant_type, p.participant_id AS pid
                           FROM research_participant_interviews pi
                           JOIN research_participants p ON pi.participant_id = p.id
                           WHERE pi.id = ?");
$interview->execute([$id]);
$interview = $interview->fetch();

if (!$interview) {
    setFlash('error', 'Interview not found.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

if (!canEditParticipantInterview($interview)) {
    setFlash('error', 'This interview is locked by the approval workflow.');
    header('Location: ' . BASE_URL . '/participant-interview-view.php?id=' . $id);
    exit;
}

// Fetch role-specific responses
$responsesStmt = $pdo->prepare("SELECT * FROM research_participant_responses WHERE interview_id = ?");
$responsesStmt->execute([$id]);
$savedResponses = [];
foreach ($responsesStmt->fetchAll() as $r) {
    $savedResponses[$r['question_key']] = $r['response_value'];
}

$participantsStmt = $pdo->prepare("SELECT id, participant_id, name, participant_type, district, municipality                                    FROM research_participants p WHERE (" . visibleParticipantWhere() . ") OR id = ? ORDER BY name ASC");
$participantsStmt->execute([(int) $interview['participant_id']]);
$participants = $participantsStmt->fetchAll();

$errors = [];
$durationWarnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        setFlash('error', 'Invalid form submission (CSRF).');
        header('Location: ' . BASE_URL . '/participant-interview-edit.php?id=' . $id);
        exit;
    }

    $participant_id    = (int) ($_POST['participant_id'] ?? 0);
    $interview_date    = $_POST['interview_date'] ?? '';
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
    } else { $location = $locPlain; }
    $duration          = (int) ($_POST['duration_minutes'] ?? 0);
    $status            = normalizeParticipantInterviewStatus($_POST['interview_status'] ?? $interview['interview_status']);
    if (!isLead() && !isEditor() && $status === 'approved') $status = normalizeParticipantInterviewStatus($interview['interview_status']);
    if (isset($_POST['submit_interview']) && canSubmitParticipantInterview($interview)) $status = 'submitted';

    $problems_observed       = $_POST['problems_observed'] ?? [];
    $problems_increasing     = $_POST['problems_increasing'] ?? [];
    $problems_greatest_losses = $_POST['problems_greatest_losses'] ?? [];
    $hardest_decisions      = $_POST['hardest_decisions'] ?? [];
    $decision_difficulties  = $_POST['decision_difficulties'] ?? [];
    $info_sources           = $_POST['info_sources'] ?? [];
    $trusted_sources        = $_POST['trusted_sources'] ?? [];
    $late_info              = $_POST['late_info'] ?? [];
    $record_methods         = $_POST['record_methods'] ?? [];
    $important_recs         = $_POST['important_records'] ?? [];
    $record_barriers        = $_POST['record_barriers'] ?? [];
    $tech_used              = $_POST['tech_used'] ?? [];
    $tech_barriers          = $_POST['tech_barriers'] ?? [];
    $voice_recording_useful       = $_POST['voice_recording_useful'] ?? '';
    $voice_recording_uses         = $_POST['voice_recording_uses'] ?? [];
    $voice_recommendations_useful = $_POST['voice_recommendations_useful'] ?? '';
    $reminders_valuable      = $_POST['reminders_valuable'] ?? '';
    $useful_reminders        = $_POST['useful_reminders'] ?? [];
    $weekly_recs_useful      = $_POST['weekly_recs_useful'] ?? '';
    $valuable_recommendations = $_POST['valuable_recommendations'] ?? [];
    $most_valuable_feature   = $_POST['most_valuable_feature'] ?? [];
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

    if ($participant_id <= 0) {
        $errors[] = 'Please select a participant.';
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

            $stmt = $pdo->prepare("UPDATE research_participant_interviews SET
                participant_id=?, interview_date=?, location=?, duration_minutes=?,
                latitude=?, longitude=?, altitude=?, gps_altitude=?, gps_accuracy=?, location_source=?,
                problems_observed=?, problems_increasing=?, problems_greatest_losses=?,
                hardest_decisions=?, decision_difficulty_reasons=?,
                info_sources=?, trusted_sources=?, info_arrives_late=?,
                record_keeping_methods=?, important_records=?, record_keeping_barriers=?,
                technologies_used=?, tech_adoption_barriers=?,
                voice_recording_useful=?, voice_recording_uses=?, voice_recommendations_useful=?,
                reminders_valuable=?, useful_reminders=?, weekly_recs_useful=?, valuable_recommendations=?, most_valuable_feature=?,
                interview_summary=?, major_findings=?, contradictions=?, new_research_opportunities=?, product_opportunities=?,
                recommended_action=?, research_importance=?, interview_quality=?, follow_up_needed=?, follow_up_notes=?,
                interview_status=?, current_step=?,
                submitted_at = CASE WHEN ? = 'submitted' AND submitted_at IS NULL THEN NOW() ELSE submitted_at END,
                submitted_by = CASE WHEN ? = 'submitted' AND submitted_by IS NULL THEN ? ELSE submitted_by END
                WHERE id=?");
            $stmt->execute([
                $participant_id, $interview_date, $location, $duration ?: null,
                $latitude ?: null, $longitude ?: null, $altitude ?: null,
                $gps_altitude ?: null, $gps_accuracy ?: null, $location_source ?: null,
                implode(', ', $problems_observed) ?: null, implode(', ', $problems_increasing) ?: null, implode(', ', $problems_greatest_losses) ?: null,
                implode(', ', $hardest_decisions) ?: null, implode(', ', $decision_difficulties) ?: null,
                implode(', ', $info_sources) ?: null, implode(', ', $trusted_sources) ?: null, implode(', ', $late_info) ?: null,
                implode(', ', $record_methods) ?: null, implode(', ', $important_recs) ?: null, implode(', ', $record_barriers) ?: null,
                implode(', ', $tech_used) ?: null, implode(', ', $tech_barriers) ?: null,
                $voice_recording_useful ?: null, implode(', ', $voice_recording_uses) ?: null, $voice_recommendations_useful ?: null,
                $reminders_valuable ?: null, implode(', ', $useful_reminders) ?: null,
                $weekly_recs_useful ?: null, implode(', ', $valuable_recommendations) ?: null, implode(', ', $most_valuable_feature) ?: null,
                $interview_summary ?: null, $major_findings ?: null, $contradictions ?: null,
                $new_research_opportunities ?: null, $product_opportunities ?: null,
                implode(', ', $recommended_action) ?: null, $research_importance ?: null, $interview_quality ?: null,
                $follow_up_needed, $follow_up_notes ?: null,
                $status, (int) ($_POST['current_step'] ?? 0),
                $status, $status, currentUserId(),
                $id
            ]);

            // Update role-specific responses
            $participant = $pdo->prepare("SELECT participant_type FROM research_participants WHERE id = ?");
            $participant->execute([$participant_id]);
            $pType = $participant->fetchColumn() ?: 'Other';
            $roleQuestions = getRoleSpecificQuestions($pType);

            $pdo->prepare("DELETE FROM research_participant_responses WHERE interview_id = ?")->execute([$id]);
            $respStmt = $pdo->prepare("INSERT INTO research_participant_responses (interview_id, question_group, question_key, response_value) VALUES (?, ?, ?, ?)");
            foreach ($roleQuestions as $q) {
                $key = $q['key'];
                $val = '';
                if ($q['type'] === 'checkbox') {
                    $selected = $_POST[$key] ?? [];
                    $val = implode(', ', $selected);
                } elseif ($q['type'] === 'radio') {
                    $val = $_POST[$key] ?? '';
                } else {
                    $val = trim($_POST[$key] ?? '');
                }
                if ($val !== '') {
                    $respStmt->execute([$id, $pType, $key, $val]);
                }
            }

            $pdo->commit();
            logAudit($pdo, 'participant_interview_updated', 'participant_interview', $id, 'Updated');
            setFlash('success', 'Interview updated successfully.');
            header('Location: ' . BASE_URL . '/participant-interview-view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Parse saved values
function parseSaved($val) { return $val ? array_map('trim', explode(',', $val)) : []; }
$savedProblemsObserved      = parseSaved($interview['problems_observed']);
$savedProblemsIncreasing    = parseSaved($interview['problems_increasing']);
$savedProblemsLosses        = parseSaved($interview['problems_greatest_losses']);
$savedHardestDecisions      = parseSaved($interview['hardest_decisions']);
$savedDecisionDifficulties  = parseSaved($interview['decision_difficulty_reasons']);
$savedInfoSources           = parseSaved($interview['info_sources']);
$savedTrustedSources        = parseSaved($interview['trusted_sources']);
$savedLateInfo              = parseSaved($interview['info_arrives_late']);
$savedRecordMethods         = parseSaved($interview['record_keeping_methods']);
$savedImportantRecords      = parseSaved($interview['important_records']);
$savedRecordBarriers        = parseSaved($interview['record_keeping_barriers']);
$savedTechUsed              = parseSaved($interview['technologies_used']);
$savedTechBarriers          = parseSaved($interview['tech_adoption_barriers']);
$savedVoiceUses             = parseSaved($interview['voice_recording_uses']);
$savedUsefulReminders       = parseSaved($interview['useful_reminders']);
$savedValuableRecs          = parseSaved($interview['valuable_recommendations']);
$savedMostValuableFeature   = parseSaved($interview['most_valuable_feature']);
$savedRecommendedAction     = parseSaved($interview['recommended_action']);

$formSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST');

$pageTitle = 'Edit Stakeholder Interview - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-pencil"></i> Edit Interview #<?php echo $id; ?></h5>
                <p class="text-muted small mb-2">
                    Participant: <strong><?php echo htmlspecialchars($interview['participant_name']); ?></strong>
                    (<code><?php echo htmlspecialchars($interview['pid']); ?></code>)
                    &bull; <?php echo htmlspecialchars($interview['participant_type']); ?>
                </p>

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

                <form method="post">
                    <?php echo csrf_field(); ?>

                    <!-- Step 1: Interview Info -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Interview Information</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label small" for="eparticipant_id_select">Participant</label>
                                    <select name="participant_id" id="eparticipant_id_select" class="form-select form-select-sm" required>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($participants as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"
                                                <?php echo (int) ($_POST['participant_id'] ?? $interview['participant_id']) === (int) $p['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['name'] . ' (' . $p['participant_id'] . ') — ' . $p['participant_type']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="einterview_date_input">Date</label>
                                    <input type="date" name="interview_date" id="einterview_date_input" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['interview_date'] ?? $interview['interview_date']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="eduration_minutes">Min</label>
                                    <input type="number" name="duration_minutes" id="eduration_minutes" class="form-control form-control-sm" min="0"
                                           value="<?php echo htmlspecialchars($_POST['duration_minutes'] ?? $interview['duration_minutes']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="einterview_location">Location</label>
                                    <input type="text" name="location" id="einterview_location" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($_POST['location'] ?? $interview['location']); ?>">
                                </div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-md-4">
                                <label class="form-label small" for="eiProvince">Province</label>
                                <select class="form-select form-select-sm" id="eiProvince" onchange="filterDistricts('eiProvince','eiDistrict','eiMunicipality','eiWard')">
                                    <option value="">-- Select --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="eiDistrict">District</label>
                                <select name="loc_district" id="eiDistrict" class="form-select form-select-sm" onchange="loadEIMunicipalities()">
                                        <option value="">--</option>
                                        <?php foreach (array_keys(getLocationData()) as $d): ?>
                                            <option value="<?php echo $d; ?>" <?php echo ($_POST['loc_district'] ?? '') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="eiMunicipality">Municipality</label>
                                    <select name="loc_municipality" id="eiMunicipality" class="form-select form-select-sm" onchange="loadEIWards()">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="eiWard">Ward</label>
                                    <select name="loc_ward" id="eiWard" class="form-select form-select-sm">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="eloc_tole_input">Tole</label>
                                    <input type="text" name="loc_tole" id="eloc_tole_input" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_POST['loc_tole'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label small" for="einterview_status_select">Status</label>
                                    <select name="interview_status" id="einterview_status_select" class="form-select form-select-sm">
                                        <?php $stVal = normalizeParticipantInterviewStatus($_POST['interview_status'] ?? $interview['interview_status']); ?>
                                        <option value="draft" <?php echo $stVal === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="submitted" <?php echo $stVal === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <?php if (isLead()): ?>
                                            <option value="approved" <?php echo $stVal === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo $stVal === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sections 2-10: Quick edit sections -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Problems Observed</div>
                        <div class="card-body py-2">
                            <div class="row g-1">
                                <?php $sel = $formSubmitted ? ($_POST['problems_observed'] ?? []) : $savedProblemsObserved;
                                foreach (getProblemsObservedOptions() as $o): ?>
                                <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="problems_observed[]" value="<?php echo $o; ?>" id="epo_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="epo_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Problems Detail</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Increasing?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['problems_increasing'] ?? []) : $savedProblemsIncreasing;
                                    foreach (getProblemsIncreasingOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="problems_increasing[]" value="<?php echo $o; ?>" id="epi_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="epi_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Greatest Losses?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['problems_greatest_losses'] ?? []) : $savedProblemsLosses;
                                    foreach (getProblemsGreatestLossesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="problems_greatest_losses[]" value="<?php echo $o; ?>" id="epgl_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="epgl_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Decision Making</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Hardest decisions?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['hardest_decisions'] ?? []) : $savedHardestDecisions;
                                    foreach (getHardestDecisionsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="hardest_decisions[]" value="<?php echo $o; ?>" id="ehd_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="ehd_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Why difficult?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['decision_difficulties'] ?? []) : $savedDecisionDifficulties;
                                    foreach (getDecisionDifficultyOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="decision_difficulties[]" value="<?php echo $o; ?>" id="edd_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="edd_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Trust &amp; Information</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Info sources?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['info_sources'] ?? []) : $savedInfoSources;
                                    foreach (getInfoSourcesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="info_sources[]" value="<?php echo $o; ?>" id="eis_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="eis_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Trusted sources?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['trusted_sources'] ?? []) : $savedTrustedSources;
                                    foreach (getTrustedSourcesOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="trusted_sources[]" value="<?php echo $o; ?>" id="ets_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="ets_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Late info?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['late_info'] ?? []) : $savedLateInfo;
                                    foreach (getLateInformationOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="late_info[]" value="<?php echo $o; ?>" id="eli_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="eli_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Record Keeping</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Methods?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['record_methods'] ?? []) : $savedRecordMethods;
                                    foreach (getRecordKeepingMethodsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="record_methods[]" value="<?php echo $o; ?>" id="erm_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="erm_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Important records?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['important_records'] ?? []) : $savedImportantRecords;
                                    foreach (getImportantRecordsOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="important_records[]" value="<?php echo $o; ?>" id="eir_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="eir_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Barriers?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['record_barriers'] ?? []) : $savedRecordBarriers;
                                    foreach (getRecordKeepingBarriersOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="record_barriers[]" value="<?php echo $o; ?>" id="erb_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="erb_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Technology Adoption</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Used?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['tech_used'] ?? []) : $savedTechUsed;
                                    foreach (getTechnologiesUsedOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="tech_used[]" value="<?php echo $o; ?>" id="etu_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="etu_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Barriers?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['tech_barriers'] ?? []) : $savedTechBarriers;
                                    foreach (getTechAdoptionBarriersOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="tech_barriers[]" value="<?php echo $o; ?>" id="etb_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="etb_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Voice &amp; Validation</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Voice useful?</span>
                                    <?php $vru = $_POST['voice_recording_useful'] ?? $interview['voice_recording_useful']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="yes" id="evru_yes" <?php echo $vru === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="evru_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="maybe" id="evru_maybe" <?php echo $vru === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="evru_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input voice-radio" type="radio" name="voice_recording_useful" value="no" id="evru_no" <?php echo $vru === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="evru_no">No</label></div>
                                    </div>
                                    <div id="eVoiceUses" style="display:<?php echo ($vru === 'yes' || $vru === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">Uses?</span>
                                        <?php $sel = $formSubmitted ? ($_POST['voice_recording_uses'] ?? []) : $savedVoiceUses;
                                        foreach (getVoiceRecordingUsesOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="voice_recording_uses[]" value="<?php echo $o; ?>" id="evru2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="evru2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Reminders valuable?</span>
                                    <?php $rv = $_POST['reminders_valuable'] ?? $interview['reminders_valuable']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="yes" id="erv_yes" <?php echo $rv === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="erv_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="maybe" id="erv_maybe" <?php echo $rv === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="erv_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input reminder-radio" type="radio" name="reminders_valuable" value="no" id="erv_no" <?php echo $rv === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="erv_no">No</label></div>
                                    </div>
                                    <div id="eReminderTypes" style="display:<?php echo ($rv === 'yes' || $rv === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">Which?</span>
                                        <?php $sel = $formSubmitted ? ($_POST['useful_reminders'] ?? []) : $savedUsefulReminders;
                                        foreach (getUsefulRemindersOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="useful_reminders[]" value="<?php echo $o; ?>" id="erv2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="erv2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="form-label small fw-medium">Weekly recs useful?</span>
                                    <?php $wru = $_POST['weekly_recs_useful'] ?? $interview['weekly_recs_useful']; ?>
                                    <div class="d-flex gap-2">
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="yes" id="ewru_yes" <?php echo $wru === 'yes' ? 'checked' : ''; ?>><label class="form-check-label small" for="ewru_yes">Yes</label></div>
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="maybe" id="ewru_maybe" <?php echo $wru === 'maybe' ? 'checked' : ''; ?>><label class="form-check-label small" for="ewru_maybe">Maybe</label></div>
                                        <div class="form-check"><input class="form-check-input rec-radio" type="radio" name="weekly_recs_useful" value="no" id="ewru_no" <?php echo $wru === 'no' ? 'checked' : ''; ?>><label class="form-check-label small" for="ewru_no">No</label></div>
                                    </div>
                                    <div id="eValuableRecs" style="display:<?php echo ($wru === 'yes' || $wru === 'maybe') ? 'block' : 'none'; ?>">
                                        <span class="form-label small mt-2">Which?</span>
                                        <?php $sel = $formSubmitted ? ($_POST['valuable_recommendations'] ?? []) : $savedValuableRecs;
                                        foreach (getValuableRecommendationsOptions() as $o): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" name="valuable_recommendations[]" value="<?php echo $o; ?>" id="ewru2_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="ewru2_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Most Valuable Feature?</span>
                                    <?php $sel = $formSubmitted ? ($_POST['most_valuable_feature'] ?? []) : $savedMostValuableFeature;
                                    foreach (getMostValuableFeatureOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="most_valuable_feature[]" value="<?php echo $o; ?>" id="emvf_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="emvf_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Role-Specific Responses -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Role-Specific Questions</div>
                        <div class="card-body py-2">
                            <?php
                            $pType = $interview['participant_type'];
                            $roleQuestionsEdit = getRoleSpecificQuestions($pType);
                            if (!empty($roleQuestionsEdit)): foreach ($roleQuestionsEdit as $q):
                                $key = $q['key'];
                                $val = $formSubmitted ? ($_POST[$key] ?? '') : ($savedResponses[$key] ?? '');
                            ?>
                            <div class="row g-2 mb-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium"><?php echo htmlspecialchars($q['label']); ?></label>
                                    <?php if ($q['type'] === 'checkbox'): ?>
                                        <div class="row g-1">
                                        <?php $selected = $formSubmitted ? ($_POST[$key] ?? []) : explode(', ', $val); ?>
                                        <?php foreach ($q['options'] as $o): ?>
                                            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="<?php echo $key; ?>[]" value="<?php echo $o; ?>" id="ers_<?php echo slugifyParticipant($key . '_' . $o); ?>" <?php echo in_array($o, $selected) ? 'checked' : ''; ?>><label class="form-check-label small" for="ers_<?php echo slugifyParticipant($key . '_' . $o); ?>"><?php echo $o; ?></label></div></div>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($q['type'] === 'radio'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($q['options'] as $o): ?>
                                            <div class="form-check"><input class="form-check-input" type="radio" name="<?php echo $key; ?>" value="<?php echo $o; ?>" id="ers_<?php echo slugifyParticipant($key . '_' . $o); ?>" <?php echo $val === $o ? 'checked' : ''; ?>><label class="form-check-label small" for="ers_<?php echo slugifyParticipant($key . '_' . $o); ?>"><?php echo $o; ?></label></div>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($q['type'] === 'textarea'): ?>
                                        <textarea name="<?php echo $key; ?>" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($val); ?></textarea>
                                    <?php else: ?>
                                        <input type="text" name="<?php echo $key; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val); ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <p class="text-muted small mb-0">No role-specific questions for this participant type.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Researcher Findings -->
                    <div class="card bg-light mb-3 border-0">
                        <div class="card-header bg-transparent fw-semibold py-2">Research Findings</div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small" for="esummary_text">Summary</label>
                                    <textarea name="interview_summary" id="esummary_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['interview_summary'] ?? $interview['interview_summary']); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small" for="emajor_findings_text">Major Findings</label>
                                    <textarea name="major_findings" id="emajor_findings_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['major_findings'] ?? $interview['major_findings']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="econtradictions_text">Contradictions</label>
                                    <textarea name="contradictions" id="econtradictions_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['contradictions'] ?? $interview['contradictions']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="enew_research_text">New Research Opportunities</label>
                                    <textarea name="new_research_opportunities" id="enew_research_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['new_research_opportunities'] ?? $interview['new_research_opportunities']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small" for="eproduct_opportunities_text">Product Opportunities</label>
                                    <textarea name="product_opportunities" id="eproduct_opportunities_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['product_opportunities'] ?? $interview['product_opportunities']); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <span class="form-label small fw-medium">Recommended Action</span>
                                    <?php $sel = $formSubmitted ? ($_POST['recommended_action'] ?? []) : $savedRecommendedAction;
                                    foreach (getRecommendedActionOptions() as $o): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="recommended_action[]" value="<?php echo $o; ?>" id="era_<?php echo slugifyParticipant($o); ?>" <?php echo in_array($o, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="era_<?php echo slugifyParticipant($o); ?>"><?php echo $o; ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="eresearch_importance_select">Importance</label>
                                    <?php $ri = $_POST['research_importance'] ?? $interview['research_importance']; ?>
                                    <select name="research_importance" id="eresearch_importance_select" class="form-select form-select-sm">
                                        <option value="">--</option>
                                        <?php foreach (getResearchImportanceOptions() as $o): ?>
                                            <option value="<?php echo $o; ?>" <?php echo $ri === $o ? 'selected' : ''; ?>><?php echo $o; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small" for="einterview_quality_select">Quality</label>
                                    <?php $iq = $_POST['interview_quality'] ?? $interview['interview_quality']; ?>
                                    <select name="interview_quality" id="einterview_quality_select" class="form-select form-select-sm">
                                        <option value="">--</option>
                                        <?php foreach (getInterviewQualityOptions() as $o): ?>
                                            <option value="<?php echo $o; ?>" <?php echo $iq === $o ? 'selected' : ''; ?>><?php echo $o; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="follow_up_needed" value="1" id="efun"
                                               onchange="document.getElementById('efollowUpNotes').style.display=this.checked?'block':'none'"
                                               <?php echo ($_POST['follow_up_needed'] ?? $interview['follow_up_needed']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="efun">Follow-up</label>
                                    </div>
                                </div>
                                <div class="col-12" id="efollowUpNotes" style="display:<?php echo ($_POST['follow_up_needed'] ?? $interview['follow_up_needed']) ? 'block' : 'none'; ?>">
                                    <label class="form-label small" for="efollow_up_notes_text">Follow-up Notes</label>
                                    <textarea name="follow_up_notes" id="efollow_up_notes_text" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($_POST['follow_up_notes'] ?? $interview['follow_up_notes']); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg"></i> Update Interview</button>
                        <?php if (canSubmitParticipantInterview($interview)): ?>
                            <button type="submit" name="submit_interview" value="1" class="btn btn-success px-4"><i class="bi bi-send"></i> Submit</button>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/participant-interview-view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });
        <?php
        $emun = htmlspecialchars($_POST['loc_municipality'] ?? '');
        if ($emun): ?>
        mun.value = <?php echo json_encode($emun); ?>;
        loadEIWards();
        <?php endif; ?>
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
        <?php
        $eward = htmlspecialchars($_POST['loc_ward'] ?? '');
        if ($eward): ?>
        ward.value = <?php echo json_encode($eward); ?>;
        <?php endif; ?>
    }
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($_POST['loc_district']) || !empty($interview['location'])): ?>
    const dist = document.getElementById('eiDistrict');
    if (dist.value) loadEIMunicipalities();
    <?php endif; ?>
});

document.querySelectorAll('.voice-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('eVoiceUses').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});
document.querySelectorAll('.reminder-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('eReminderTypes').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});
document.querySelectorAll('.rec-radio').forEach(rb => {
    rb.addEventListener('change', function() {
        document.getElementById('eValuableRecs').style.display = (this.value === 'yes' || this.value === 'maybe') ? 'block' : 'none';
    });
});
</script>

<script src="<?php echo BASE_URL; ?>/assets/js/checkbox-bulk.js"></script>
<?php include __DIR__ . '/footer.php'; ?>
