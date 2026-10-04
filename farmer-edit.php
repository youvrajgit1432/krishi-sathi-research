<?php
/**
 * Krishi Sathi Research System - Edit Farmer
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot edit farmers.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$farmer = $pdo->prepare("SELECT * FROM research_farmers WHERE id = ?");
$farmer->execute([$id]);
$farmer = $farmer->fetch();

if (!$farmer) {
    setFlash('error', 'Farmer not found.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

if (!canEditFarmer($farmer)) {
    setFlash('error', 'Access denied. You can only edit farmers you created.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $education_level      = trim($_POST['education_level'] ?? $farmer['education_level'] ?? '');
    $primary_occupation   = trim($_POST['primary_occupation'] ?? $farmer['primary_occupation'] ?? '');
    $land_ownership_type  = trim($_POST['land_ownership_type'] ?? $farmer['land_ownership_type'] ?? '');
    $irrigation_access    = trim($_POST['irrigation_access'] ?? $farmer['irrigation_access'] ?? '');
    $preferred_language   = trim($_POST['preferred_language'] ?? $farmer['preferred_language'] ?? '');
    $cooperative_membership = trim($_POST['cooperative_membership'] ?? $farmer['cooperative_membership'] ?? '');
    // Phase 2B enum check — normalise empty to '' (not '0')
    if ($cooperative_membership === '0') $cooperative_membership = '';

    if ($name === '') $errors[] = 'Farmer name is required.';

    // Phone validation (optional field, but must be valid Nepal mobile)
    if ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Phone number must be exactly 10 digits (e.g., 98XXXXXXXX).';
    } elseif ($phone !== '' && !preg_match('/^(98|97|96)[0-9]{8}$/', $phone)) {
        $errors[] = 'Phone number must start with 98, 97, or 96.';
    }

    // Check for duplicate phone (exclude self)
    if ($phone !== '' && empty($errors)) {
        $dupStmt = $pdo->prepare("SELECT id, name, farmer_id FROM research_farmers WHERE phone = ? AND id != ? AND " . activeWhere());
        $dupStmt->execute([$phone, $id]);
        $dup = $dupStmt->fetch();
        if ($dup) {
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
        try {
            $stmt = $pdo->prepare("UPDATE research_farmers SET name=?, phone=?, district=?, municipality=?, ward=?, tole=?, age_group=?, gender=?, latitude=?, longitude=?, altitude=?, gps_altitude=?, gps_accuracy=?, location_source=?, gps_timestamp=NOW(), education_level=?, primary_occupation=?, land_ownership_type=?, irrigation_access=?, preferred_language=?, cooperative_membership=? WHERE id=?");
            $stmt->execute([$name, $phone, $district, $municipality, $ward, $tole ?: null, $age_group ?: null, $gender ?: null, $latitude ?: null, $longitude ?: null, $altitude ?: null, $gps_altitude ?: null, $gps_accuracy ?: null, $location_source ?: null, $education_level ?: null, $primary_occupation ?: null, $land_ownership_type ?: null, $irrigation_access ?: null, $preferred_language ?: null, $cooperative_membership ?: null, $id]);
            logAudit($pdo, 'farmer_updated', 'farmer', $id, 'Updated farmer ' . $farmer['farmer_id']);
            setFlash('success', 'Farmer updated successfully.');
            header('Location: ' . BASE_URL . '/farmer-view.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$ageGroups = ['Under 18', '18-25', '26-35', '36-45', '46-55', '56-65', '65+'];
$pageTitle = 'Edit Farmer - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-pencil"></i> Edit Farmer</h5>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?>
                            <div><?php echo $e; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="editFarmerForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="latitude" id="eLatField" value="<?php echo htmlspecialchars($_POST['latitude'] ?? $farmer['latitude']); ?>">
                    <input type="hidden" name="longitude" id="eLngField" value="<?php echo htmlspecialchars($_POST['longitude'] ?? $farmer['longitude']); ?>">
                    <input type="hidden" name="gps_altitude" id="eGpsAltField" value="<?php echo htmlspecialchars($_POST['gps_altitude'] ?? $farmer['gps_altitude']); ?>">
                    <input type="hidden" name="gps_accuracy" id="eGpsAccuracyField" value="<?php echo htmlspecialchars($_POST['gps_accuracy'] ?? ($farmer['gps_accuracy'] ?? '')); ?>">
                    <input type="hidden" name="location_source" id="eLocationSourceField" value="<?php echo htmlspecialchars($_POST['location_source'] ?? ($farmer['location_source'] ?? '')); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? $farmer['name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? $farmer['phone']); ?>">
                        </div>
                        <div class="col-12"><hr class="my-1"><label class="form-label fw-medium">Location</label></div>
                        <div class="col-md-4">
                            <label class="form-label small">Province <span class="text-danger">*</span></label>
                            <select class="form-select" id="eProvince" onchange="filterDistricts('eProvince','eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select Province --</option>
                                <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                <?php foreach ($provinces as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">District <span class="text-danger">*</span></label>
                            <select name="district" id="eDistrict" class="form-select" onchange="loadEMMun('eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select District --</option>
                                <?php $edist = $_POST['district'] ?? $farmer['district']; ?>
                                <?php foreach (array_keys(getLocationData()) as $d): ?>
                                    <option value="<?php echo $d; ?>" <?php echo $edist === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Municipality</label>
                            <select name="municipality" id="eMunicipality" class="form-select" onchange="loadEMWard('eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select District First --</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Ward</label>
                            <select name="ward" id="eWard" class="form-select">
                                <option value="">--</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Tole</label>
                            <input type="text" name="tole" class="form-control" value="<?php echo htmlspecialchars($_POST['tole'] ?? $farmer['tole']); ?>" placeholder="Optional">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Age Group</label>
                            <select name="age_group" class="form-select">
                                <option value="">Select...</option>
                                <?php foreach ($ageGroups as $ag): ?>
                                    <option value="<?php echo $ag; ?>" <?php echo ($_POST['age_group'] ?? $farmer['age_group']) === $ag ? 'selected' : ''; ?>>
                                        <?php echo $ag; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select...</option>
                                <option value="Male" <?php echo ($_POST['gender'] ?? $farmer['gender']) === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($_POST['gender'] ?? $farmer['gender']) === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($_POST['gender'] ?? $farmer['gender']) === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <!-- ─── Research Fields ───────────────────────────── -->
                        <div class="col-12"><hr class="my-1"><label class="form-label fw-medium">Research Profile</label></div>
                        <div class="col-md-4">
                            <label class="form-label small">Education Level</label>
                            <select name="education_level" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $el = $_POST['education_level'] ?? $farmer['education_level'] ?? ''; ?>
                                <option value="none" <?php echo $el === 'none' ? 'selected' : ''; ?>>None</option>
                                <option value="primary" <?php echo $el === 'primary' ? 'selected' : ''; ?>>Primary</option>
                                <option value="secondary" <?php echo $el === 'secondary' ? 'selected' : ''; ?>>Secondary</option>
                                <option value="higher_secondary" <?php echo $el === 'higher_secondary' ? 'selected' : ''; ?>>Higher Secondary</option>
                                <option value="bachelor" <?php echo $el === 'bachelor' ? 'selected' : ''; ?>>Bachelor</option>
                                <option value="masters" <?php echo $el === 'masters' ? 'selected' : ''; ?>>Masters</option>
                                <option value="phd" <?php echo $el === 'phd' ? 'selected' : ''; ?>>PhD</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Primary Occupation</label>
                            <input type="text" name="primary_occupation" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_POST['primary_occupation'] ?? $farmer['primary_occupation'] ?? ''); ?>"
                                   placeholder="e.g., Farming, Teaching">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Land Ownership</label>
                            <select name="land_ownership_type" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $lot = $_POST['land_ownership_type'] ?? $farmer['land_ownership_type'] ?? ''; ?>
                                <option value="own" <?php echo $lot === 'own' ? 'selected' : ''; ?>>Own</option>
                                <option value="leased" <?php echo $lot === 'leased' ? 'selected' : ''; ?>>Leased</option>
                                <option value="shared" <?php echo $lot === 'shared' ? 'selected' : ''; ?>>Shared</option>
                                <option value="landless" <?php echo $lot === 'landless' ? 'selected' : ''; ?>>Landless</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Irrigation Access</label>
                            <select name="irrigation_access" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $ia = $_POST['irrigation_access'] ?? $farmer['irrigation_access'] ?? ''; ?>
                                <option value="yes" <?php echo $ia === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                <option value="no" <?php echo $ia === 'no' ? 'selected' : ''; ?>>No</option>
                                <option value="seasonal" <?php echo $ia === 'seasonal' ? 'selected' : ''; ?>>Seasonal</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Preferred Language</label>
                            <select name="preferred_language" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $pl = $_POST['preferred_language'] ?? $farmer['preferred_language'] ?? ''; ?>
                                <option value="Nepali" <?php echo $pl === 'Nepali' ? 'selected' : ''; ?>>Nepali</option>
                                <option value="English" <?php echo $pl === 'English' ? 'selected' : ''; ?>>English</option>
                                <option value="Maithili" <?php echo $pl === 'Maithili' ? 'selected' : ''; ?>>Maithili</option>
                                <option value="Bhojpuri" <?php echo $pl === 'Bhojpuri' ? 'selected' : ''; ?>>Bhojpuri</option>
                                <option value="Tharu" <?php echo $pl === 'Tharu' ? 'selected' : ''; ?>>Tharu</option>
                                <option value="Newar" <?php echo $pl === 'Newar' ? 'selected' : ''; ?>>Newar</option>
                                <option value="Tamang" <?php echo $pl === 'Tamang' ? 'selected' : ''; ?>>Tamang</option>
                                <option value="Other" <?php echo $pl === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Cooperative Member?</label>
                            <select name="cooperative_membership" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $cm = $_POST['cooperative_membership'] ?? $farmer['cooperative_membership'] ?? ''; ?>
                                <option value="yes" <?php echo $cm === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                <option value="no" <?php echo $cm === 'no' ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                    </div>

                    <!-- Altitude + GPS Section -->
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label small">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                            <input type="number" name="altitude" class="form-control form-control-sm" step="0.01"
                                   value="<?php echo htmlspecialchars($_POST['altitude'] ?? $farmer['altitude']); ?>"
                                   placeholder="e.g., 1350">
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="eGpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getEditGPS()">
                                <i class="bi bi-geo-alt"></i> 📍 Update GPS
                            </button>
                        </div>
                        <div class="col-md-6">
                            <small id="eGpsStatus" class="text-muted">
                                <?php if ($farmer['latitude'] && $farmer['longitude']): ?>
                                    ✅ GPS: <?php echo $farmer['latitude']; ?>, <?php echo $farmer['longitude']; ?>
                                    <?php if ($farmer['gps_altitude']): ?> (<?php echo $farmer['gps_altitude']; ?>m)<?php endif; ?>
                                <?php else: ?>
                                    📍 GPS not captured
                                <?php endif; ?>
                            </small>
                            <small id="eGpsCoords" class="text-muted d-block"></small>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Update Farmer
                        </button>
                        <a href="<?php echo BASE_URL; ?>/farmer-view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const locationData = <?php echo getLocationDataJson(); ?>;
const provinceDistrictMap = <?php echo json_encode($provMap, JSON_UNESCAPED_UNICODE); ?>;

function getEditGPS() {
    const btn = document.getElementById('eGpsBtn');
    const status = document.getElementById('eGpsStatus');
    const coords = document.getElementById('eGpsCoords');
    if (!navigator.geolocation) { status.innerHTML = '❌ GPS not supported'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = '📍 Getting GPS position...';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            document.getElementById('eLatField').value = lat;
            document.getElementById('eLngField').value = lng;
            if (alt) document.getElementById('eGpsAltField').value = alt;
            document.getElementById('eGpsAccuracyField').value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
            document.getElementById('eLocationSourceField').value = 'gps';
            coords.innerHTML = `${lat}, ${lng}${alt ? ' (' + alt + 'm)' : ''}${pos.coords.accuracy ? ' accuracy ' + pos.coords.accuracy.toFixed(0) + 'm' : ''}`;
            status.innerHTML = '✅ GPS captured';
            status.className = 'text-success small';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> GPS Updated';
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

function loadEMMun(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId);
    const ward = document.getElementById(wardId);
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });        <?php
        $emun = $_POST['municipality'] ?? $farmer['municipality'] ?? '';
        if ($emun): ?>
        mun.value = <?php echo json_encode($emun); ?>;
        loadEMWard(distId, munId, wardId);
        <?php endif; ?>
    }
}

function loadEMWard(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId).value;
    const ward = document.getElementById(wardId);
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += `<option value="${w}">Ward ${w}</option>`;
        });
        <?php
        $eward = htmlspecialchars($_POST['ward'] ?? $farmer['ward'] ?? '');
        if ($eward): ?>
        ward.value = <?php echo json_encode($eward); ?>;
        <?php endif; ?>
    }
}

function filterDistricts(provId, distId, munId, wardId) {
    const pid = document.getElementById(provId).value;
    const dSel = document.getElementById(distId);
    for (const opt of dSel.options) {
        if (!opt.value) { opt.style.display = ''; continue; }
        opt.style.display = (!pid || (provinceDistrictMap[pid] && provinceDistrictMap[pid].includes(opt.value))) ? '' : 'none';
    }
    dSel.value = '';
    loadEMMun(distId, munId, wardId);
}

(function() {
    const dSel = document.getElementById('eDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('eProvince').value = pid;
                break;
            }
        }
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($_POST['district']) || !empty($farmer['district'])): ?>
    loadEMMun('eDistrict', 'eMunicipality', 'eWard');
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
