<?php
/**
 * Krishi Sathi Research System - Add Farmer
 * Phase 1.6: Cascading location dropdowns, GPS, Tole.
 * Optimized: Multi-step form, smart defaults, searchable dropdowns, auto-focus.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot add farmers.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

$pdo = getDB();
$errors = [];
$autoFarmerId = generateFarmerId($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $farmer_id     = trim($_POST['farmer_id'] ?? '');
    $name          = trim($_POST['name'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $district      = trim($_POST['district'] ?? '');
    $municipality  = trim($_POST['municipality'] ?? '');
    $ward          = trim($_POST['ward'] ?? '');
    $tole          = trim($_POST['tole'] ?? '');
    $age_group     = $_POST['age_group'] ?? '';
    $gender        = $_POST['gender'] ?? '';
    $latitude      = $_POST['latitude'] ?? null;
    $longitude     = $_POST['longitude'] ?? null;
    $altitude      = trim($_POST['altitude'] ?? '');
    $gps_altitude  = $_POST['gps_altitude'] ?? null;
    $gps_accuracy  = $_POST['gps_accuracy'] ?? null;
    $location_source = trim($_POST['location_source'] ?? '');
    if ($altitude === '') $altitude = null;
    $education_level      = trim($_POST['education_level'] ?? '');
    $primary_occupation   = trim($_POST['primary_occupation'] ?? '');
    $land_ownership_type  = trim($_POST['land_ownership_type'] ?? '');
    $irrigation_access    = trim($_POST['irrigation_access'] ?? '');
    $preferred_language   = trim($_POST['preferred_language'] ?? '');
    $cooperative_membership = trim($_POST['cooperative_membership'] ?? '');

    // Phase 2B enum check — normalise empty to '' (not '0')
    if ($cooperative_membership === '0') $cooperative_membership = '';

    // Auto-generate Farmer ID if not provided
    if ($farmer_id === '') {
        $farmer_id = generateFarmerId($pdo);
    }
    if ($name === '') $errors[] = 'Farmer name is required.';

    // Phone validation (optional field, but must be valid Nepal mobile)
    if ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Phone number must be exactly 10 digits (e.g., 98XXXXXXXX).';
    } elseif ($phone !== '' && !preg_match('/^(98|97|96)[0-9]{8}$/', $phone)) {
        $errors[] = 'Phone number must start with 98, 97, or 96.';
    }

    // Check for duplicate phone
    if ($phone !== '' && empty($errors)) {
        $dupStmt = $pdo->prepare("SELECT id, name, farmer_id FROM research_farmers WHERE phone = ? AND " . activeWhere());
        $dupStmt->execute([$phone]);
        $dup = $dupStmt->fetch();
        if ($dup && (int) $dup['id'] !== ($_POST['edit_id'] ?? 0)) {
            $errors[] = 'Phone ' . htmlspecialchars($phone) . ' is already registered to ' . htmlspecialchars($dup['name']) . ' (' . htmlspecialchars($dup['farmer_id']) . ').';
        }
    }

    // Education Level enum check (Phase 2B)
    $validEducationLevels = ['none', 'primary', 'secondary', 'higher_secondary', 'bachelor', 'masters', 'phd'];
    if ($education_level !== '' && !in_array($education_level, $validEducationLevels)) {
        $errors[] = 'Invalid education level selected.';
    }

    // Primary Occupation max length check (Phase 2B)
    if ($primary_occupation !== '' && strlen($primary_occupation) > 100) {
        $errors[] = 'Primary occupation must not exceed 100 characters.';
    }

    if (empty($errors)) {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Regenerate ID on each retry to pick up the next available number
            if ($attempt > 1 || $farmer_id === '') {
                $farmer_id = generateFarmerId($pdo);
            }
            try {
                $stmt = $pdo->prepare("INSERT INTO research_farmers
                    (farmer_id, name, phone, district, municipality, ward, tole,
                     age_group, gender, latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source, gps_timestamp, created_by,
                     education_level, primary_occupation, land_ownership_type, irrigation_access, preferred_language, cooperative_membership)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?,
                            ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $farmer_id, $name, $phone ?: null,
                    $district ?: null, $municipality ?: null, $ward ?: null, $tole ?: null,
                    $age_group ?: null, $gender ?: null,
                    $latitude ?: null, $longitude ?: null,
                    $altitude ?: null,
                    $gps_altitude ?: null,
                    $gps_accuracy ?: null,
                    $location_source ?: null,
                    currentUserId(),
                    $education_level ?: null,
                    $primary_occupation ?: null,
                    $land_ownership_type ?: null,
                    $irrigation_access ?: null,
                    $preferred_language ?: null,
                    $cooperative_membership ?: null,
                ]);
                $newId = $pdo->lastInsertId();
                logAudit($pdo, 'farmer_created', 'farmer', $newId,
                         'Created farmer ' . $farmer_id . ' - ' . $name);
                $redirectTo = $_POST['redirect_to'] ?? '';
                if ($redirectTo === 'interview') {
                    setFlash('success', 'Farmer "' . htmlspecialchars($name) . '" created successfully!');
                    header('Location: ' . BASE_URL . '/interview-add.php?farmer_id=' . $newId);
                    exit;
                }
                setFlash('success', 'Farmer "' . htmlspecialchars($name) . '" created successfully!');
                header('Location: ' . BASE_URL . '/farmer-view.php?id=' . $newId);
                exit;
            } catch (PDOException $e) {
                // Duplicate key (23000) on farmer_id UNIQUE constraint — retry with new ID
                if ($e->getCode() == 23000 && strpos($e->getMessage(), 'farmer_id') !== false && $attempt < $maxRetries) {
                    continue;
                }
                // Non-duplicate or exhausted retries — show error
                $errors[] = 'Database error: ' . $e->getMessage();
                break;
            }
        }
    }
    if (!empty($errors)) saveOld($_POST);
}

$ageGroups = ['Under 18', '18-25', '26-35', '36-45', '46-55', '56-65', '65+'];
$pageTitle = 'Add Farmer - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start">
                    <h5 class="card-title mb-3"><i class="bi bi-person-plus"></i> Add New Farmer</h5>
                    <a href="<?php echo BASE_URL; ?>/farmer-quick-add.php" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-lightning"></i> Quick Add
                    </a>
                </div>

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
                            <div class="step-label">Research</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step-indicator" data-step="3">
                            <div class="step-circle">3</div>
                            <div class="step-label">Location</div>
                        </div>
                    </div>
                    <div class="text-center text-muted small mt-1" id="stepCounter">Step 1 of 3</div>
                </div>

                <form method="post" id="farmerForm" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="latitude" id="latField" value="<?php echo htmlspecialchars(old('latitude')); ?>">
                    <input type="hidden" name="longitude" id="lngField" value="<?php echo htmlspecialchars(old('longitude')); ?>">
                    <input type="hidden" name="gps_altitude" id="gpsAltField" value="<?php echo htmlspecialchars(old('gps_altitude')); ?>">
                    <input type="hidden" name="gps_accuracy" id="gpsAccuracyField" value="<?php echo htmlspecialchars(old('gps_accuracy')); ?>">
                    <input type="hidden" name="location_source" id="locationSourceField" value="<?php echo htmlspecialchars(old('location_source')); ?>">
                    <input type="hidden" name="redirect_to" id="redirectToField" value="">
                    <input type="hidden" name="farmer_id" value="<?php echo htmlspecialchars(old('farmer_id') ?: $autoFarmerId); ?>">

                    <!-- ═══ STEP 1: Basic Information ═══ -->
                    <div class="step-content" id="step1">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('name')); ?>" required placeholder="Enter farmer name" autocomplete="name" inputmode="text">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('phone')); ?>" placeholder="98XXXXXXXX" autocomplete="tel" inputmode="numeric" maxlength="10">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Age Group</label>
                                <select name="age_group" class="form-select form-select-lg">
                                    <option value="">Select...</option>
                                    <?php $agDefault = old('age_group') ?: '36-45'; ?>
                                    <?php foreach ($ageGroups as $ag): ?>
                                        <option value="<?php echo $ag; ?>" <?php echo $agDefault === $ag ? 'selected' : ''; ?>><?php echo $ag; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-select form-select-lg">
                                    <option value="Male" <?php echo old('gender') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo old('gender') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo old('gender') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 2: Research Profile ═══ -->
                    <div class="step-content d-none" id="step2">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Education Level</label>
                                <select name="education_level" class="form-select form-select-lg">
                                    <option value="">Select...</option>
                                    <?php $el = old('education_level') ?: 'secondary'; ?>
                                    <option value="none" <?php echo $el === 'none' ? 'selected' : ''; ?>>None</option>
                                    <option value="primary" <?php echo $el === 'primary' ? 'selected' : ''; ?>>Primary</option>
                                    <option value="secondary" <?php echo $el === 'secondary' ? 'selected' : ''; ?>>Secondary</option>
                                    <option value="higher_secondary" <?php echo $el === 'higher_secondary' ? 'selected' : ''; ?>>Higher Secondary</option>
                                    <option value="bachelor" <?php echo $el === 'bachelor' ? 'selected' : ''; ?>>Bachelor</option>
                                    <option value="masters" <?php echo $el === 'masters' ? 'selected' : ''; ?>>Masters</option>
                                    <option value="phd" <?php echo $el === 'phd' ? 'selected' : ''; ?>>PhD</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Primary Occupation</label>
                                <input type="text" name="primary_occupation" list="occupationList" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('primary_occupation') ?: 'Agriculture'); ?>" placeholder="Type or select occupation" autocomplete="off">
                                <datalist id="occupationList">
                                    <option value="Agriculture">
                                    <option value="Livestock">
                                    <option value="Poultry">
                                    <option value="Business">
                                    <option value="Government Service">
                                    <option value="Private Job">
                                    <option value="Teaching">
                                    <option value="Student">
                                    <option value="Homemaker">
                                    <option value="Daily Wage">
                                    <option value="Retired">
                                    <option value="Other">
                                </datalist>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label">Land Ownership</label>
                                <select name="land_ownership_type" class="form-select form-select-lg">
                                    <option value="">Select...</option>
                                    <?php $lot = old('land_ownership_type') ?: 'own'; ?>
                                    <option value="own" <?php echo $lot === 'own' ? 'selected' : ''; ?>>Own</option>
                                    <option value="leased" <?php echo $lot === 'leased' ? 'selected' : ''; ?>>Leased</option>
                                    <option value="shared" <?php echo $lot === 'shared' ? 'selected' : ''; ?>>Shared</option>
                                    <option value="landless" <?php echo $lot === 'landless' ? 'selected' : ''; ?>>Landless</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label">Irrigation Access</label>
                                <select name="irrigation_access" class="form-select form-select-lg">
                                    <option value="">Select...</option>
                                    <?php $ia = old('irrigation_access') ?: 'yes'; ?>
                                    <option value="yes" <?php echo $ia === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="no" <?php echo $ia === 'no' ? 'selected' : ''; ?>>No</option>
                                    <option value="seasonal" <?php echo $ia === 'seasonal' ? 'selected' : ''; ?>>Seasonal</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label">Preferred Language</label>
                                <select name="preferred_language" class="form-select form-select-lg">
                                    <?php $pl = old('preferred_language'); ?>
                                    <option value="Nepali" <?php echo $pl === 'Nepali' || $pl === '' ? 'selected' : ''; ?>>Nepali</option>
                                    <option value="English" <?php echo $pl === 'English' ? 'selected' : ''; ?>>English</option>
                                    <option value="Maithili" <?php echo $pl === 'Maithili' ? 'selected' : ''; ?>>Maithili</option>
                                    <option value="Bhojpuri" <?php echo $pl === 'Bhojpuri' ? 'selected' : ''; ?>>Bhojpuri</option>
                                    <option value="Tharu" <?php echo $pl === 'Tharu' ? 'selected' : ''; ?>>Tharu</option>
                                    <option value="Newar" <?php echo $pl === 'Newar' ? 'selected' : ''; ?>>Newar</option>
                                    <option value="Tamang" <?php echo $pl === 'Tamang' ? 'selected' : ''; ?>>Tamang</option>
                                    <option value="Other" <?php echo $pl === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-6">
                                <label class="form-label">Cooperative Member?</label>
                                <select name="cooperative_membership" class="form-select form-select-lg">
                                    <?php $cmDefault = old('cooperative_membership') ?: 'yes'; ?>
                                    <option value="no" <?php echo $cmDefault === 'no' ? 'selected' : ''; ?>>No</option>
                                    <option value="yes" <?php echo $cmDefault === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ STEP 3: Location ═══ -->
                    <div class="step-content d-none" id="step3">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Province</label>
                                <select class="form-select form-select-lg" id="fProvince">
                                    <option value="">-- Select Province --</option>
                                    <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                    <?php foreach ($provinces as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">District <span class="text-danger">*</span></label>
                                <select name="district" id="fDistrict" class="form-select form-select-lg" required onchange="loadMunicipalities('fDistrict','fMunicipality','fWard')">
                                    <option value="">-- Select District --</option>
                                    <?php $selDist = old('district'); ?>
                                    <?php foreach (array_keys(getLocationData()) as $d): ?>
                                        <option value="<?php echo $d; ?>" <?php echo $selDist === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Municipality <span class="text-danger">*</span></label>
                                <select name="municipality" id="fMunicipality" class="form-select form-select-lg" required onchange="loadWards('fDistrict','fMunicipality','fWard')">
                                    <option value="">-- Select District First --</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Ward</label>
                                <select name="ward" id="fWard" class="form-select form-select-lg">
                                    <option value="">--</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Tole</label>
                                <input type="text" name="tole" class="form-control form-control-lg" value="<?php echo htmlspecialchars(old('tole')); ?>" placeholder="Optional">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Altitude (m)</label>
                                <input type="number" name="altitude" class="form-control form-control-lg" step="0.01" value="<?php echo htmlspecialchars(old('altitude')); ?>" placeholder="e.g., 1350">
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
                            <a href="<?php echo BASE_URL; ?>/farmers.php" class="btn btn-outline-secondary">Cancel</a>
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

<script>
const locationData = <?php echo getLocationDataJson(); ?>;
const provinceDistrictMap = <?php echo json_encode($provMap, JSON_UNESCAPED_UNICODE); ?>;
let currentStep = 1;

function goToStep(step) {
    if (step === currentStep) return;
    if (step > currentStep && !validateStep(currentStep)) return;
    showStep(step);
}

function showStep(step) {
    document.querySelectorAll('.step-content').forEach(el => el.classList.add('d-none'));
    document.getElementById('step' + step).classList.remove('d-none');
    document.querySelectorAll('.step-actions > div').forEach(el => el.classList.add('d-none'));
    document.getElementById('step' + step + 'Actions').classList.remove('d-none');
    document.querySelectorAll('.step-indicator').forEach(el => {
        el.classList.remove('active', 'completed');
        var s = parseInt(el.dataset.step);
        if (s === step) el.classList.add('active');
        else if (s < step) el.classList.add('completed');
    });
    document.querySelectorAll('.step-connector').forEach((el, i) => {
        if (i + 1 < step) el.classList.add('completed');
        else el.classList.remove('completed');
    });
    document.getElementById('stepCounter').textContent = 'Step ' + step + ' of 3';
    currentStep = step;
    var firstInput = document.querySelector('#step' + step + ' input:not([type=hidden]):not([list]), #step' + step + ' select');
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
    if (step === 1) {
        var phone = container.querySelector('[name="phone"]');
        if (phone && phone.value) {
            if (!/^[0-9]{10}$/.test(phone.value)) {
                phone.classList.add('is-invalid');
                valid = false;
            } else if (!/^(98|97|96)[0-9]{8}$/.test(phone.value)) {
                phone.classList.add('is-invalid');
                valid = false;
            } else {
                phone.classList.remove('is-invalid');
            }
        }
    }
    if (!valid) {
        var firstInvalid = container.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.focus();
    }
    return valid;
}

function loadMunicipalities(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId);
    const ward = document.getElementById(wardId);
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += '<option value="' + m + '">' + m + '</option>';
        });
        <?php if (old('municipality')): ?>
        mun.value = <?php echo json_encode(old('municipality')); ?>;
        loadWards(distId, munId, wardId);
        <?php endif; ?>
    }
}

function loadWards(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId).value;
    const ward = document.getElementById(wardId);
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += '<option value="' + w + '">Ward ' + w + '</option>';
        });
        <?php if (old('ward')): ?>
        ward.value = <?php echo json_encode(old('ward')); ?>;
        <?php else: ?>
        if (ward.options.length > 1) ward.value = '1';
        <?php endif; ?>
    }
}

document.getElementById('fProvince').addEventListener('change', function() {
    const pid = this.value;
    const dSel = document.getElementById('fDistrict');
    for (const opt of dSel.options) {
        if (!opt.value) { opt.style.display = ''; continue; }
        opt.style.display = (!pid || (provinceDistrictMap[pid] && provinceDistrictMap[pid].includes(opt.value))) ? '' : 'none';
    }
    dSel.value = '';
    loadMunicipalities('fDistrict', 'fMunicipality', 'fWard');
});

(function() {
    const dSel = document.getElementById('fDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('fProvince').value = pid;
                break;
            }
        }
    }
})();

function getGPSFullLocation() {
    const btn = document.getElementById('gpsBtn');
    const status = document.getElementById('gpsStatus');
    const coords = document.getElementById('gpsCoords');
    if (!navigator.geolocation) { status.innerHTML = 'GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = 'Getting GPS position...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('latField').value = lat;
            document.getElementById('lngField').value = lng;
            if (alt) document.getElementById('gpsAltField').value = alt;
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
                    document.getElementById('fDistrict').value = matchedDistrict;
                    loadMunicipalities('fDistrict', 'fMunicipality', 'fWard');
                    var munName = (addr.city || addr.town || addr.municipality || addr.village || '').toLowerCase();
                    var mun = document.getElementById('fMunicipality');
                    for (var i = 0; i < mun.options.length; i++) {
                        if (mun.options[i].value.toLowerCase().indexOf(munName) !== -1) {
                            mun.value = mun.options[i].value;
                            loadWards('fDistrict', 'fMunicipality', 'fWard');
                            break;
                        }
                    }
                }
            }
        })
        .catch(function() {});
}

// Enter key navigation
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
    loadMunicipalities('fDistrict', 'fMunicipality', 'fWard');
    <?php endif; ?>
    // Show correct step if there are old values in step 2 or 3
    <?php
    $hasStep2 = old('education_level') || old('primary_occupation') || old('land_ownership_type') || old('irrigation_access') || old('preferred_language') || old('cooperative_membership');
    $hasStep3 = old('district') || old('municipality') || old('ward') || old('tole') || old('altitude') || old('latitude');
    if ($hasStep3): ?>
    showStep(3);
    <?php elseif ($hasStep2): ?>
    showStep(2);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
