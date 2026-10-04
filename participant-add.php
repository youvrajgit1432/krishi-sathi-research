<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: Add Participant
 *
 * Phase 2: Participant CRUD
 * Optimized: Multi-step form, smart role defaults, searchable work area, auto-focus.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot add stakeholders.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

$pdo = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $participant_type = trim($_POST['participant_type'] ?? '');
    $name             = trim($_POST['name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $organization     = trim($_POST['organization'] ?? '');
    $district         = trim($_POST['district'] ?? '');
    $municipality     = trim($_POST['municipality'] ?? '');
    $ward             = trim($_POST['ward'] ?? '');
    $tole             = trim($_POST['tole'] ?? '');
    $experience_years = $_POST['experience_years'] ?? '';
    $service_area     = $_POST['service_area'] ?? [];
    $main_work_area   = $_POST['main_work_area'] ?? [];
    $primary_role     = trim($_POST['primary_role'] ?? '');
    $latitude         = $_POST['latitude'] ?? null;
    $longitude        = $_POST['longitude'] ?? null;
    $altitude         = trim($_POST['altitude'] ?? '');
    $gps_altitude     = $_POST['gps_altitude'] ?? null;
    $gps_accuracy     = $_POST['gps_accuracy'] ?? null;
    $location_source  = trim($_POST['location_source'] ?? '');
    $other_type_text  = trim($_POST['other_type_text'] ?? '');
    $other_role_text  = trim($_POST['other_role_text'] ?? '');
    if ($altitude === '') $altitude = null;

    // Validation
    if ($participant_type === '') $errors[] = 'Participant type is required.';
    if ($participant_type === 'Other' && $other_type_text !== '') {
        $participant_type = 'Other: ' . $other_type_text;
    }
    if ($name === '') $errors[] = 'Name is required.';
    if ($primary_role === 'Other' && $other_role_text !== '') {
        $primary_role = 'Other: ' . $other_role_text;
    }

    if ($phone !== '') {
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
            $errors[] = 'Phone number must be exactly 10 digits (e.g., 98XXXXXXXX).';
        } elseif (!preg_match('/^(98|97|96)[0-9]{8}$/', $phone)) {
            $errors[] = 'Phone number must start with 98, 97, or 96 (Nepal mobile number).';
        } else {
            $dupCheck = $pdo->prepare("SELECT id, name, participant_id FROM research_participants WHERE phone = ? AND deleted_at IS NULL");
            $dupCheck->execute([$phone]);
            $dup = $dupCheck->fetch();
            if ($dup) {
                $errors[] = 'Phone number ' . htmlspecialchars($phone) . ' is already registered to ' . htmlspecialchars($dup['name']) . ' (' . htmlspecialchars($dup['participant_id']) . ').';
            }
        }
    }

    if (empty($errors)) {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $participant_id = generateParticipantId($pdo);
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO research_participants
                    (participant_id, participant_type, name, phone, organization,
                     district, municipality, ward, tole,
                     experience_years, service_area, main_work_area, primary_role,
                     latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source, gps_timestamp,
                     created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([
                    $participant_id, $participant_type, $name, $phone ?: null, $organization ?: null,
                    $district ?: null, $municipality ?: null, $ward ?: null, $tole ?: null,
                    $experience_years ?: null,
                    !empty($service_area) ? implode(', ', $service_area) : null,
                    !empty($main_work_area) ? implode(', ', $main_work_area) : null,
                    $primary_role ?: null,
                    $latitude ?: null, $longitude ?: null,
                    $altitude ?: null, $gps_altitude ?: null, $gps_accuracy ?: null,
                    $location_source ?: null,
                    currentUserId()
                ]);

                $newId = $pdo->lastInsertId();
                $pdo->commit();

                logAudit($pdo, 'participant_created', 'participant', $newId,
                         'Created stakeholder ' . $participant_id . ' - ' . $name);

                $redirectTo = $_POST['redirect_to'] ?? '';
                if ($redirectTo === 'interview') {
                    setFlash('success', 'Stakeholder "' . htmlspecialchars($name) . '" created successfully!');
                    header('Location: ' . BASE_URL . '/participant-interview-add.php?participant_id=' . $newId);
                    exit;
                }
                setFlash('success', 'Stakeholder "' . htmlspecialchars($name) . '" created successfully (ID: ' . $participant_id . ')!');
                header('Location: ' . BASE_URL . '/participant-view.php?id=' . $newId);
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000 && strpos($e->getMessage(), 'participant_id') !== false && $attempt < $maxRetries) {
                    continue;
                }
                $errors[] = 'Database error: ' . $e->getMessage();
                break;
            }
        }
    }
    if (!empty($errors)) saveOld($_POST);
}

$pageTitle = 'Add Stakeholder - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-person-plus"></i> Add New Stakeholder</h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Progress Indicator -->
                <div class="step-progress mb-4">
                    <div class="d-flex align-items-center justify-content-between" id="stepIndicators">
                        <div class="step-indicator active" data-step="1">
                            <div class="step-circle">1</div>
                            <div class="step-label">Basic Info</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step-indicator" data-step="2">
                            <div class="step-circle">2</div>
                            <div class="step-label">Professional</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step-indicator" data-step="3">
                            <div class="step-circle">3</div>
                            <div class="step-label">Location</div>
                        </div>
                    </div>
                    <div class="text-center text-muted small mt-1" id="stepCounter">Step 1 of 3</div>
                </div>

                <form method="post" id="addParticipantForm" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="latitude" id="latField" value="<?php echo htmlspecialchars(old('latitude')); ?>">
                    <input type="hidden" name="longitude" id="lngField" value="<?php echo htmlspecialchars(old('longitude')); ?>">
                    <input type="hidden" name="gps_altitude" id="gpsAltField" value="<?php echo htmlspecialchars(old('gps_altitude')); ?>">
                    <input type="hidden" name="gps_accuracy" id="gpsAccuracyField" value="<?php echo htmlspecialchars(old('gps_accuracy')); ?>">
                    <input type="hidden" name="location_source" id="locationSourceField" value="<?php echo htmlspecialchars(old('location_source')); ?>">
                    <input type="hidden" name="redirect_to" id="redirectToField" value="">

                    <!-- ═══ STEP 1: Basic Information ═══ -->
                    <div class="step-content" id="step1">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-medium" id="participant_type_label">Participant Type <span class="text-danger">*</span></label>
                                <div class="row g-2" role="radiogroup" aria-labelledby="participant_type_label">
                                    <?php $selectedType = old('participant_type'); ?>
                                    <?php foreach (getParticipantTypes() as $type): ?>
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <div class="form-check">
                                                <input class="form-check-input participant-type-radio" type="radio" name="participant_type"
                                                       id="pt_<?php echo slugifyParticipant($type); ?>"
                                                       value="<?php echo $type; ?>"
                                                       onchange="toggleOtherType(); updateRoleOptions(this.value)"
                                                       <?php echo $selectedType === $type ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="pt_<?php echo slugifyParticipant($type); ?>">
                                                    <?php echo $type; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div id="otherTypeDiv" class="mt-2 <?php echo $selectedType === 'Other' ? '' : 'd-none'; ?>">
                                    <label class="form-label small" for="other_type_text">Specify Other Type:</label>
                                    <input type="text" name="other_type_text" id="other_type_text" class="form-control form-control-lg"
                                           value="<?php echo htmlspecialchars(old('other_type_text')); ?>" placeholder="Please specify...">
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="participant_name" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('name')); ?>" required placeholder="Enter stakeholder name" autocomplete="name">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Phone (Nepal Mobile)</label>
                                <input type="tel" name="phone" id="phone" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('phone')); ?>" placeholder="98XXXXXXXX" maxlength="10" autocomplete="tel" inputmode="numeric">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Organization</label>
                                <input type="text" name="organization" id="participant_org" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('organization')); ?>" placeholder="Institution, business..." autocomplete="organization">
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 2: Professional Information ═══ -->
                    <div class="step-content d-none" id="step2">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Years of Experience</label>
                                <select name="experience_years" id="experience_years_select" class="form-select form-select-lg">
                                    <option value="">Select...</option>
                                    <?php foreach (getExperienceOptions() as $exp): ?>
                                        <option value="<?php echo $exp; ?>" <?php echo old('experience_years') === $exp ? 'selected' : ''; ?>><?php echo $exp; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Service Area</label>
                                <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;">
                                    <?php $svcAreas = old('service_area') ? (array) old('service_area') : ['Municipality']; ?>
                                    <?php foreach (getServiceAreaOptions() as $sa): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="service_area[]"
                                                   value="<?php echo $sa; ?>" id="sa_<?php echo slugifyParticipant($sa); ?>"
                                                   <?php echo in_array($sa, $svcAreas) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="sa_<?php echo slugifyParticipant($sa); ?>"><?php echo $sa; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Main Work Area</label>
                                <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;">
                                    <?php $workAreas = old('main_work_area') ? (array) old('main_work_area') : []; ?>
                                    <?php foreach (getMainWorkAreaOptions() as $wa): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="main_work_area[]"
                                                   value="<?php echo $wa; ?>" id="wa_<?php echo slugifyParticipant($wa); ?>"
                                                   <?php echo in_array($wa, $workAreas) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="wa_<?php echo slugifyParticipant($wa); ?>"><?php echo $wa; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Primary Role</label>
                                <select name="primary_role" id="primary_role_select" class="form-select form-select-lg" onchange="toggleOtherRole()">
                                    <option value="">Select role...</option>
                                </select>
                                <div id="otherRoleDiv" class="mt-2 d-none">
                                    <label class="form-label small" for="other_role_text">Specify Role:</label>
                                    <input type="text" name="other_role_text" id="other_role_text" class="form-control form-control-lg" placeholder="Enter custom role...">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 3: Location ═══ -->
                    <div class="step-content d-none" id="step3">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Province</label>
                                <select class="form-select form-select-lg" id="pProvince" onchange="filterDistricts('pProvince','pDistrict','pMunicipality','pWard')">
                                    <option value="">-- Select Province --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">District</label>
                                <select name="district" id="pDistrict" class="form-select form-select-lg" onchange="loadMunicipalities('pDistrict','pMunicipality','pWard')">
                                    <option value="">-- Select District --</option>
                                    <?php $selDist = old('district'); ?>
                                    <?php foreach (array_keys(getLocationData()) as $d): ?>
                                        <option value="<?php echo $d; ?>" <?php echo $selDist === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Municipality</label>
                                <select name="municipality" id="pMunicipality" class="form-select form-select-lg" onchange="loadWards('pDistrict','pMunicipality','pWard')">
                                    <option value="">-- Select District First --</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Ward</label>
                                <select name="ward" id="pWard" class="form-select form-select-lg">
                                    <option value="">--</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Tole</label>
                                <input type="text" name="tole" id="tole_input" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('tole')); ?>" placeholder="Optional">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Altitude (m)</label>
                                <input type="number" name="altitude" id="paltitude_manual" class="form-control form-control-lg" step="0.01" value="<?php echo htmlspecialchars(old('altitude')); ?>" placeholder="e.g., 1350">
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-end pb-1">
                                <button type="button" id="gpsBtn" class="btn btn-outline-info w-100" onclick="getGPSFullLocation()">
                                    <i class="bi bi-geo-alt"></i> Use GPS
                                </button>
                            </div>
                            <div class="col-12">
                                <small id="gpsStatus" class="text-muted">GPS not captured</small>
                                <small id="gpsCoords" class="text-muted d-block"></small>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Action Buttons ═══ -->
                    <div class="step-actions sticky-submit mt-3" id="stepActions">
                        <div class="d-flex gap-2" id="step1Actions">
                            <button type="button" class="btn btn-success flex-fill" onclick="goToStep(2)">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                            <a href="<?php echo BASE_URL; ?>/participants.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                        <div class="d-flex gap-2 d-none" id="step2Actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">
                                <i class="bi bi-arrow-left"></i> Previous
                            </button>
                            <button type="button" class="btn btn-success flex-fill" onclick="goToStep(3)">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                        <div class="d-flex gap-2 d-none" id="step3Actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                                <i class="bi bi-arrow-left"></i> Previous
                            </button>
                            <button type="submit" class="btn btn-success flex-fill" onclick="document.getElementById('redirectToField').value=''">
                                <i class="bi bi-check-lg"></i> Save
                            </button>
                            <button type="submit" class="btn btn-primary flex-fill" onclick="document.getElementById('redirectToField').value='interview'">
                                <i class="bi bi-arrow-right-circle"></i> Save &amp; Start Interview
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.step-progress { padding: 0; }
.step-indicator { display: flex; flex-direction: column; align-items: center; flex: 1; }
.step-circle { width: 36px; height: 36px; border-radius: 50%; background: #e9ecef; color: #6c757d; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; transition: all 0.3s ease; border: 2px solid transparent; }
.step-indicator.active .step-circle { background: #198754; color: #fff; border-color: #146c43; }
.step-indicator.completed .step-circle { background: #198754; color: #fff; opacity: 0.7; }
.step-label { font-size: 0.65rem; color: #6c757d; margin-top: 4px; white-space: nowrap; font-weight: 500; }
.step-indicator.active .step-label { color: #198754; font-weight: 700; }
.step-connector { flex: 1; height: 2px; background: #e9ecef; margin: 0 4px; margin-bottom: 20px; position: relative; }
.step-connector.completed { background: #198754; }
@media (max-width: 767.98px) {
    .step-circle { width: 32px; height: 32px; font-size: 0.75rem; }
    .step-label { font-size: 0.6rem; }
    .step-connector { margin-bottom: 18px; }
}
[data-bs-theme="dark"] .step-circle { background: #334155; color: #94a3b8; }
[data-bs-theme="dark"] .step-indicator.active .step-circle { background: #22c55e; border-color: #16a34a; }
[data-bs-theme="dark"] .step-indicator.completed .step-circle { background: #22c55e; }
[data-bs-theme="dark"] .step-connector { background: #334155; }
[data-bs-theme="dark"] .step-connector.completed { background: #22c55e; }
[data-bs-theme="dark"] .step-indicator.active .step-label { color: #22c55e; }
</style>

<script src="<?php echo BASE_URL; ?>/assets/js/phone-validation.js"></script>
<script>
const locationData = <?php echo getLocationDataJson(); ?>;
const provinceDistrictMap = <?php echo json_encode($provMap, JSON_UNESCAPED_UNICODE); ?>;
let currentStep = 1;

const roleMap = {
    'Agrovet Owner': ['Agrovet Owner'],
    'Municipality Agriculture Officer': ['Agriculture Officer'],
    'Agriculture Technician': ['Agriculture Technician'],
    'Veterinarian': ['Veterinarian'],
    'Cooperative Leader': ['Cooperative Manager'],
    'Trader': ['Agricultural Trader'],
    'Researcher': ['Researcher'],
    'Collection Center': ['Collection Center Manager'],
    'Dairy Farmer': ['Dairy Farmer'],
    'Poultry Farmer': ['Poultry Farmer'],
    'Goat Farmer': ['Goat Farmer'],
    'Other': ['Other'],
};
const allRoles = ['Agrovet Owner','Agriculture Officer','Agriculture Technician','Veterinarian','Cooperative Manager','Agricultural Trader','Researcher','Collection Center Manager','Dairy Farmer','Poultry Farmer','Goat Farmer','Extension Officer','Field Technician','Program Manager','Coordinator','Other'];

function updateRoleOptions(participantType) {
    var sel = document.getElementById('primary_role_select');
    var oldVal = sel.value;
    sel.innerHTML = '<option value="">Select role...</option>';
    var suggested = roleMap[participantType] || allRoles;
    if (participantType && participantType !== 'Other' && suggested.length === 1) {
        // Show all roles with the suggested one first
        var allRolesCopy = allRoles.slice();
        suggested.forEach(function(r) {
            var idx = allRolesCopy.indexOf(r);
            if (idx > -1) allRolesCopy.splice(idx, 1);
        });
        suggested.forEach(function(r) {
            sel.innerHTML += '<option value="' + r + '">' + r + ' (suggested)</option>';
        });
        allRolesCopy.forEach(function(r) {
            sel.innerHTML += '<option value="' + r + '">' + r + '</option>';
        });
    } else {
        allRoles.forEach(function(r) {
            sel.innerHTML += '<option value="' + r + '">' + r + '</option>';
        });
    }
    if (oldVal && oldVal !== 'Other') {
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === oldVal) { sel.value = oldVal; break; }
        }
    } else if (suggested.length === 1) {
        sel.value = suggested[0];
    }
}

function toggleOtherType() {
    var otherRadio = document.getElementById('pt_Other');
    var otherDiv = document.getElementById('otherTypeDiv');
    if (otherRadio && otherRadio.checked) {
        otherDiv.classList.remove('d-none');
        updateRoleOptions('Other');
    } else {
        otherDiv.classList.add('d-none');
    }
}

function toggleOtherRole() {
    var sel = document.getElementById('primary_role_select');
    var otherDiv = document.getElementById('otherRoleDiv');
    if (sel.value === 'Other') {
        otherDiv.classList.remove('d-none');
    } else {
        otherDiv.classList.add('d-none');
    }
}

function goToStep(step) {
    if (step === currentStep) return;
    if (step > currentStep && !validateStep(currentStep)) return;
    showStep(step);
}

function showStep(step) {
    document.querySelectorAll('.step-content').forEach(function(el) { el.classList.add('d-none'); });
    document.getElementById('step' + step).classList.remove('d-none');
    document.querySelectorAll('.step-actions > div').forEach(function(el) { el.classList.add('d-none'); });
    document.getElementById('step' + step + 'Actions').classList.remove('d-none');
    document.querySelectorAll('.step-indicator').forEach(function(el) {
        el.classList.remove('active', 'completed');
        var s = parseInt(el.dataset.step);
        if (s === step) el.classList.add('active');
        else if (s < step) el.classList.add('completed');
    });
    document.querySelectorAll('.step-connector').forEach(function(el, i) {
        if (i + 1 < step) el.classList.add('completed');
        else el.classList.remove('completed');
    });
    document.getElementById('stepCounter').textContent = 'Step ' + step + ' of 3';
    currentStep = step;
    var firstInput = document.querySelector('#step' + step + ' input:not([type=hidden]):not([type=radio]):not([list]), #step' + step + ' select');
    if (firstInput) setTimeout(function() { firstInput.focus(); }, 100);
}

function validateStep(step) {
    var container = document.getElementById('step' + step);
    var required = container.querySelectorAll('[required]');
    var valid = true;
    required.forEach(function(el) {
        if (!el.value.trim()) {
            el.classList.add('is-invalid');
            valid = false;
            if (!el.nextElementSibling || !el.nextElementSibling.classList.contains('invalid-feedback')) {
                var feedback = document.createElement('div');
                feedback.className = 'invalid-feedback';
                feedback.textContent = 'This field is required';
                el.parentNode.insertBefore(feedback, el.nextSibling);
            }
        } else {
            el.classList.remove('is-invalid');
        }
    });
    var phone = container.querySelector('[name="phone"]');
    if (phone && phone.value) {
        if (!/^[0-9]{10}$/.test(phone.value) || !/^(98|97|96)[0-9]{8}$/.test(phone.value)) {
            phone.classList.add('is-invalid');
            valid = false;
        } else {
            phone.classList.remove('is-invalid');
        }
    }
    if (!valid) {
        var firstInvalid = container.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.focus();
    }
    return valid;
}

function loadMunicipalities(distId, munId, wardId) {
    var dist = document.getElementById(distId).value;
    var mun = document.getElementById(munId);
    var ward = document.getElementById(wardId);
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(function(m) {
            mun.innerHTML += '<option value="' + m + '">' + m + '</option>';
        });
        <?php if (old('municipality')): ?>
        mun.value = <?php echo json_encode(old('municipality')); ?>;
        loadWards(distId, munId, wardId);
        <?php endif; ?>
    }
}

function loadWards(distId, munId, wardId) {
    var dist = document.getElementById(distId).value;
    var mun = document.getElementById(munId).value;
    var ward = document.getElementById(wardId);
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(function(w) {
            ward.innerHTML += '<option value="' + w + '">Ward ' + w + '</option>';
        });
        <?php if (old('ward')): ?>
        ward.value = <?php echo json_encode(old('ward')); ?>;
        <?php endif; ?>
    }
}

function getGPSFullLocation() {
    var btn = document.getElementById('gpsBtn');
    var status = document.getElementById('gpsStatus');
    var coords = document.getElementById('gpsCoords');
    if (!navigator.geolocation) { status.innerHTML = 'GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = 'Getting GPS position...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            var lat = pos.coords.latitude.toFixed(7);
            var lng = pos.coords.longitude.toFixed(7);
            var alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('latField').value = lat;
            document.getElementById('lngField').value = lng;
            if (alt) {
                document.getElementById('gpsAltField').value = alt;
                var visAlt = document.querySelector('[name="altitude"]');
                if (visAlt) visAlt.value = alt;
            }
            document.getElementById('gpsAccuracyField').value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
            document.getElementById('locationSourceField').value = 'gps';
            coords.innerHTML = lat + ', ' + lng + (alt ? ' (' + alt + 'm)' : '') + (pos.coords.accuracy ? ' accuracy ' + pos.coords.accuracy.toFixed(0) + 'm' : '');
            status.innerHTML = 'GPS captured';
            status.className = 'text-success small';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> GPS OK';
            btn.className = 'btn btn-success w-100';
            if (lat && lng) reverseGeocodeFull(lat, lng);
        },
        function(err) {
            if (err.message && err.message.toLowerCase().indexOf('secure origin') !== -1) {
                status.innerHTML = 'GPS requires HTTPS. Enable SSL on your server, or use manual altitude entry.';
                status.className = 'text-warning small';
            } else if (err.code === 1) {
                status.innerHTML = 'GPS permission denied. Allow location access in browser settings.';
            } else {
                status.innerHTML = 'GPS error: ' + err.message;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt"></i> Retry GPS';
            btn.className = 'btn btn-outline-info w-100';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function filterDistricts(provId, distId, munId, wardId) {
    const pid = document.getElementById(provId).value;
    const dSel = document.getElementById(distId);
    for (const opt of dSel.options) {
        if (!opt.value) { opt.style.display = ''; continue; }
        opt.style.display = (!pid || (provinceDistrictMap[pid] && provinceDistrictMap[pid].includes(opt.value))) ? '' : 'none';
    }
    dSel.value = '';
    loadMunicipalities(distId, munId, wardId);
}

(function() {
    const dSel = document.getElementById('pDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('pProvince').value = pid;
                break;
            }
        }
    }
})();

function reverseGeocodeFull(lat, lng) {
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&accept-language=en')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.address) {
                var addr = data.address;
                var districtNames = Object.keys(locationData);
                var matchedDistrict = '';
                var stateDist = (addr.state_district || addr.county || addr.state || '').toLowerCase();
                for (var d = 0; d < districtNames.length; d++) {
                    if (stateDist.indexOf(districtNames[d].toLowerCase()) !== -1 || districtNames[d].toLowerCase().indexOf(stateDist) !== -1) {
                        matchedDistrict = districtNames[d]; break;
                    }
                }
                if (matchedDistrict) {
                    document.getElementById('pDistrict').value = matchedDistrict;
                    loadMunicipalities('pDistrict', 'pMunicipality', 'pWard');
                    var munName = (addr.city || addr.town || addr.municipality || addr.village || '').toLowerCase();
                    var mun = document.getElementById('pMunicipality');
                    for (var i = 0; i < mun.options.length; i++) {
                        if (mun.options[i].value.toLowerCase().indexOf(munName) !== -1) {
                            mun.value = mun.options[i].value;
                            loadWards('pDistrict', 'pMunicipality', 'pWard');
                            break;
                        }
                    }
                }
            }
        })
        .catch(function() {});
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var active = document.activeElement;
        if (active && active.closest('.step-content')) {
            e.preventDefault();
            if (currentStep < 3) {
                goToStep(currentStep + 1);
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    <?php if (old('district')): ?>
    loadMunicipalities('pDistrict', 'pMunicipality', 'pWard');
    <?php endif; ?>
    toggleOtherType();
    toggleOtherRole();
    <?php
    $selectedType = old('participant_type');
    if ($selectedType && $selectedType !== 'Other'): ?>
    updateRoleOptions(<?php echo json_encode($selectedType); ?>);
    <?php endif; ?>
    <?php
    $hasStep2 = old('experience_years') || old('service_area') || old('main_work_area') || old('primary_role');
    $hasStep3 = old('district') || old('municipality') || old('ward') || old('tole') || old('altitude') || old('latitude');
    if ($hasStep3): ?>
    showStep(3);
    <?php elseif ($hasStep2): ?>
    showStep(2);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
