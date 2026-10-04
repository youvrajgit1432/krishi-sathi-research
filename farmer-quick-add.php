<?php
/**
 * Krishi Sathi Research System - Quick Farmer Registration
 * Phase 1.6: Cascading location dropdowns, GPS, Tole, Ward.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    $token = $_POST['_csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        $errors[] = 'Invalid form submission (CSRF).';
    }
    $name         = trim($_POST['name'] ?? '');
    $district     = trim($_POST['district'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $ward         = trim($_POST['ward'] ?? '');
    $tole         = trim($_POST['tole'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $age_group    = $_POST['age_group'] ?? '';
    $gender       = $_POST['gender'] ?? '';
    $latitude     = $_POST['latitude'] ?? null;
    $longitude    = $_POST['longitude'] ?? null;
    $altitude     = trim($_POST['altitude'] ?? '');
    $gps_altitude = $_POST['gps_altitude'] ?? null;
    if ($altitude === '') $altitude = null;
    $redirect_to  = trim($_POST['redirect_to'] ?? '');

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
        if ($dup) {
            $errors[] = 'Phone ' . htmlspecialchars($phone) . ' is already registered to ' . htmlspecialchars($dup['name']) . ' (' . htmlspecialchars($dup['farmer_id']) . ').';
        }
    }

    if (empty($errors)) {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $farmer_id = generateFarmerId($pdo);
            try {
                $stmt = $pdo->prepare("INSERT INTO research_farmers
                    (farmer_id, name, phone, district, municipality, ward, tole,
                     age_group, gender,
                     latitude, longitude, altitude, gps_altitude, gps_accuracy, location_source, gps_timestamp, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([
                    $farmer_id, $name, $phone ?: null,
                    $district ?: null, $municipality ?: null, $ward ?: null, $tole ?: null,
                    $age_group ?: null, $gender ?: null,
                    $latitude ?: null, $longitude ?: null,
                    $altitude ?: null,
                    $gps_altitude ?: null,
                    ($_POST['gps_accuracy'] ?? '') ?: null,
                    ($_POST['location_source'] ?? '') ?: null,
                    currentUserId()
                ]);
                $newId = $pdo->lastInsertId();

                setFlash('success', 'Farmer "' . htmlspecialchars($name) . '" registered (ID: ' . $farmer_id . ')');

                if ($redirect_to === 'interview') {
                    header('Location: ' . BASE_URL . '/interview-add.php?farmer_id=' . $newId);
                } else {
                    header('Location: ' . BASE_URL . '/farmer-view.php?id=' . $newId);
                }
                exit;
            } catch (PDOException $e) {
                // Duplicate key (23000) on farmer_id — retry with next ID
                if ($e->getCode() == 23000 && strpos($e->getMessage(), 'farmer_id') !== false && $attempt < $maxRetries) {
                    continue;
                }
                $errors[] = 'Database error: ' . $e->getMessage();
                break;
            }
        }
    }
    if (!empty($errors)) saveOld($_POST);
}

$redirectTo = $_GET['redirect'] ?? '';
$pageTitle = 'Quick Add Farmer - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-1"><i class="bi bi-person-plus"></i> Quick Farmer Registration</h5>
                <p class="text-muted small mb-3">Essential fields — cascading location, optional GPS.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" id="quickFarmerForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTo); ?>">
                    <input type="hidden" name="latitude" id="latField" value="<?php echo htmlspecialchars(old('latitude')); ?>">
                    <input type="hidden" name="longitude" id="lngField" value="<?php echo htmlspecialchars(old('longitude')); ?>">
                    <input type="hidden" name="gps_altitude" id="gpsAltField" value="<?php echo htmlspecialchars(old('gps_altitude')); ?>">

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Farmer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('name')); ?>" required placeholder="e.g., Ram Bahadur">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('phone')); ?>" placeholder="e.g., 9876543210">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Age Group</label>
                            <select name="age_group" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <?php $ageGroups = ['Under 18', '18-25', '26-35', '36-45', '46-55', '56-65', '65+']; ?>
                                <?php foreach ($ageGroups as $ag): ?>
                                    <option value="<?php echo $ag; ?>" <?php echo old('age_group') === $ag ? 'selected' : ''; ?>><?php echo $ag; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Gender</label>
                            <select name="gender" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <option value="Male" <?php echo old('gender') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo old('gender') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo old('gender') === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end pb-1">
                            <button type="button" id="gpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getGPSLocation()">
                                <i class="bi bi-geo-alt"></i> GPS
                            </button>
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label small">Province <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="qProvince" onchange="filterDistricts('qProvince','qDistrict','qMunicipality','qWard')">
                                <option value="">-- Select Province --</option>
                                <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                <?php foreach ($provinces as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">District <span class="text-danger">*</span></label>
                            <select name="district" id="qDistrict" class="form-select form-select-sm" required onchange="loadMunicipalities('qDistrict','qMunicipality','qWard')">
                                <option value="">-- Select District --</option>
                                <?php $selDist = old('district'); ?>
                                <?php foreach (array_keys(getLocationData()) as $d): ?>
                                    <option value="<?php echo $d; ?>" <?php echo $selDist === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Municipality <span class="text-danger">*</span></label>
                            <select name="municipality" id="qMunicipality" class="form-select form-select-sm" required onchange="loadWards('qDistrict','qMunicipality','qWard')">
                                <option value="">-- Select District First --</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Ward</label>
                            <select name="ward" id="qWard" class="form-select form-select-sm">
                                <option value="">--</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Tole</label>
                            <input type="text" name="tole" class="form-control form-control-sm" value="<?php echo htmlspecialchars(old('tole')); ?>" placeholder="Optional">
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label small">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                            <input type="number" name="altitude" class="form-control form-control-sm" step="0.01"
                                   value="<?php echo htmlspecialchars(old('altitude')); ?>" placeholder="e.g., 1350">
                        </div>
                        <div class="col-md-4">
                            <small id="gpsStatus" class="text-muted">📍 GPS not captured</small>
                        </div>
                        <div class="col-md-4 text-end">
                            <small id="gpsCoords" class="text-muted"></small>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save Farmer</button>
                        <a href="<?php echo BASE_URL; ?>/farmer-add.php" class="btn btn-outline-secondary btn-sm">Full Registration</a>
                        <a href="<?php echo BASE_URL; ?>/farmers.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ─── Cascading Location Data ───────────────────────────────────
const locationData = <?php echo getLocationDataJson(); ?>;
const provinceDistrictMap = <?php echo json_encode($provMap, JSON_UNESCAPED_UNICODE); ?>;

function loadMunicipalities(districtId, munId, wardId) {
    const dist = document.getElementById(districtId).value;
    const mun = document.getElementById(munId);
    const ward = document.getElementById(wardId);
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });
        <?php if (old('municipality')): ?>
        mun.value = <?php echo json_encode(old('municipality')); ?>;
        loadWards(districtId, munId, wardId);
        <?php endif; ?>
    }
}

function loadWards(districtId, munId, wardId) {
    const dist = document.getElementById(districtId).value;
    const mun = document.getElementById(munId).value;
    const ward = document.getElementById(wardId);
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += `<option value="${w}">Ward ${w}</option>`;
        });
        <?php if (old('ward')): ?>
        ward.value = <?php echo json_encode(old('ward')); ?>;
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
    loadMunicipalities(distId, munId, wardId);
}

(function() {
    const dSel = document.getElementById('qDistrict');
    const sel = dSel.value;
    if (sel) {
        for (const [pid, dists] of Object.entries(provinceDistrictMap)) {
            if (dists.includes(sel)) {
                document.getElementById('qProvince').value = pid;
                break;
            }
        }
    }
})();

// ─── GPS Location ──────────────────────────────────────────────
function getGPSLocation() {
    const btn = document.getElementById('gpsBtn');
    const status = document.getElementById('gpsStatus');
    const coords = document.getElementById('gpsCoords');
    
    if (!navigator.geolocation) {
        status.innerHTML = '❌ GPS not supported by browser';
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    status.innerHTML = '📍 Getting GPS position...';
    
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const alt = pos.coords.altitude ? pos.coords.altitude.toFixed(2) : '';
            
            document.getElementById('latField').value = lat;
            document.getElementById('lngField').value = lng;
            if (alt) document.getElementById('gpsAltField').value = alt;
            
            coords.innerHTML = `${lat}, ${lng}${alt ? ' (' + alt + 'm)' : ''}`;
            status.innerHTML = '✅ GPS captured';
            status.className = 'text-success small';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> GPS OK';
            btn.className = 'btn btn-sm btn-success w-100';
            
            // Attempt reverse geocoding
            if (lat && lng) {
                reverseGeocode(lat, lng);
            }
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

function reverseGeocode(lat, lng) {
    const status = document.getElementById('gpsStatus');
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&accept-language=en`)
        .then(r => r.json())
        .then(data => {
            if (data && data.address) {
                const addr = data.address;
                // Try to auto-select district
                const districtNames = Object.keys(locationData);
                let matchedDistrict = '';
                const stateDistrict = (addr.state_district || addr.county || addr.state || '').toLowerCase();
                for (const d of districtNames) {
                    if (stateDistrict.includes(d.toLowerCase()) || d.toLowerCase().includes(stateDistrict)) {
                        matchedDistrict = d;
                        break;
                    }
                }
                if (matchedDistrict) {
                    document.getElementById('qDistrict').value = matchedDistrict;
                    loadMunicipalities('qDistrict', 'qMunicipality', 'qWard');
                    // Try municipality
                    const munName = (addr.city || addr.town || addr.municipality || addr.village || '').toLowerCase();
                    const mun = document.getElementById('qMunicipality');
                    for (let i = 0; i < mun.options.length; i++) {
                        if (mun.options[i].value.toLowerCase().includes(munName)) {
                            mun.value = mun.options[i].value;
                            loadWards('qDistrict', 'qMunicipality', 'qWard');
                            break;
                        }
                    }
                }
                status.innerHTML = '✅ GPS + location filled';
            }
        })
        .catch(() => {
            // Silent fail - manual selection always available
        });
}

// ─── Initialize locations on page load ─────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    <?php if (old('district')): ?>
    loadMunicipalities('qDistrict', 'qMunicipality', 'qWard');
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
