<?php
/**
 * Krishi Sathi Research System — Other Stakeholder: Edit Participant
 *
 * Phase 2: Participant CRUD
 * Complete isolated module — does NOT modify any farmer functionality.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
require_once __DIR__ . '/location-data.php';
requireLogin();

if (isViewer()) {
    setFlash('error', 'Viewers cannot edit stakeholders.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$participant = $pdo->prepare("SELECT * FROM research_participants WHERE id = ?");
$participant->execute([$id]);
$participant = $participant->fetch();

if (!$participant) {
    setFlash('error', 'Stakeholder not found.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

// RBAC: Contributor can only edit their own
if (!canViewParticipant($participant)) {
    setFlash('error', 'Access denied. You can only edit stakeholders you created.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

// Block editing approved participants for contributors
if (($participant['approval_status'] ?? '') === 'approved' && isContributor()) {
    setFlash('error', 'Approved stakeholders cannot be edited. The record has been reviewed by the Research Lead.');
    header('Location: ' . BASE_URL . '/participant-view.php?id=' . $id);
    exit;
}

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
    if ($altitude === '') $altitude = null;

    if ($participant_type === '') $errors[] = 'Participant type is required.';
    if ($participant_type === 'Other' && $other_type_text !== '') {
        $participant_type = 'Other: ' . $other_type_text;
    }
    if ($name === '') $errors[] = 'Name is required.';

    if ($phone !== '') {
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
            $errors[] = 'Phone number must be exactly 10 digits (e.g., 98XXXXXXXX).';
        } elseif (!preg_match('/^(98|97|96)[0-9]{8}$/', $phone)) {
            $errors[] = 'Phone number must start with 98, 97, or 96 (Nepal mobile number).';
        } else {
            // Check for duplicate phone number (excluding current record)
            $dupCheck = $pdo->prepare("SELECT id, name, participant_id FROM research_participants WHERE phone = ? AND id != ? AND deleted_at IS NULL");
            $dupCheck->execute([$phone, $id]);
            $dup = $dupCheck->fetch();
            if ($dup) {
                $errors[] = 'Phone number ' . htmlspecialchars($phone) . ' is already registered to ' . htmlspecialchars($dup['name']) . ' (' . htmlspecialchars($dup['participant_id']) . ').';
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE research_participants SET
                participant_type=?, name=?, phone=?, organization=?,
                district=?, municipality=?, ward=?, tole=?,
                experience_years=?, service_area=?, main_work_area=?, primary_role=?,
                latitude=?, longitude=?, altitude=?, gps_altitude=?, gps_accuracy=?,
                location_source=?, gps_timestamp=NOW()
                WHERE id=?");
            $stmt->execute([
                $participant_type, $name, $phone ?: null, $organization ?: null,
                $district ?: null, $municipality ?: null, $ward ?: null, $tole ?: null,
                $experience_years ?: null,
                !empty($service_area) ? implode(', ', $service_area) : null,
                !empty($main_work_area) ? implode(', ', $main_work_area) : null,
                $primary_role ?: null,
                $latitude ?: null, $longitude ?: null,
                $altitude ?: null, $gps_altitude ?: null, $gps_accuracy ?: null,
                $location_source ?: null,
                $id
            ]);
            logAudit($pdo, 'participant_updated', 'participant', $id, 'Updated stakeholder ' . $participant['participant_id']);
            setFlash('success', 'Stakeholder updated successfully.');
            header('Location: ' . BASE_URL . '/participant-view.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Stakeholder - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-pencil"></i> Edit Stakeholder</h5>
                <p class="text-muted small">ID: <code><?php echo htmlspecialchars($participant['participant_id']); ?></code></p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="editParticipantForm" onsubmit="return validatePhone('ePhone', 'ePhoneError')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="latitude" id="eLatField" value="<?php echo htmlspecialchars($_POST['latitude'] ?? $participant['latitude']); ?>">
                    <input type="hidden" name="longitude" id="eLngField" value="<?php echo htmlspecialchars($_POST['longitude'] ?? $participant['longitude']); ?>">
                    <input type="hidden" name="gps_altitude" id="eGpsAltField" value="<?php echo htmlspecialchars($_POST['gps_altitude'] ?? $participant['gps_altitude']); ?>">
                    <input type="hidden" name="gps_accuracy" id="eGpsAccuracyField" value="<?php echo htmlspecialchars($_POST['gps_accuracy'] ?? ($participant['gps_accuracy'] ?? '')); ?>">
                    <input type="hidden" name="location_source" id="eLocationSourceField" value="<?php echo htmlspecialchars($_POST['location_source'] ?? ($participant['location_source'] ?? '')); ?>">

                    <!-- Participant Type -->
                    <div class="mb-3">
                        <label class="form-label fw-medium" id="eparticipant_type_label">Participant Type <span class="text-danger">*</span></label>
                        <div class="row g-2" aria-labelledby="eparticipant_type_label">
                            <?php $pType = $_POST['participant_type'] ?? $participant['participant_type'];
                                  $isCustomOther = $pType !== '' && !in_array($pType, getParticipantTypes()); ?>
                            <?php foreach (getParticipantTypes() as $type): ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="participant_type"
                                               id="ept_<?php echo slugifyParticipant($type); ?>"
                                               value="<?php echo $type; ?>"
                                               onchange="toggleEditOtherType()"
                                               <?php echo ($isCustomOther && $type === 'Other') || (!$isCustomOther && $pType === $type) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="ept_<?php echo slugifyParticipant($type); ?>">
                                            <?php echo $type; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="eOtherTypeDiv" class="mt-2 <?php echo $isCustomOther ? '' : 'd-none'; ?>">
                            <label class="form-label small" for="eother_type_text">Specify Other Type:</label>
                            <input type="text" name="other_type_text" id="eother_type_text" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($isCustomOther ? substr($pType, 7) : ($_POST['other_type_text'] ?? '')); ?>"
                                   placeholder="Please specify...">
                        </div>
                    </div>

                    <hr class="my-3">

                    <!-- Basic Info -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="eparticipant_name">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="eparticipant_name" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? $participant['name']); ?>" required autocomplete="name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="ePhone">Phone (Nepal Mobile)</label>
                            <input type="tel" name="phone" id="ePhone" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? $participant['phone']); ?>"
                                   placeholder="e.g., 98XXXXXXXX" maxlength="10"
                                   oninput="validatePhoneInline('ePhone', 'ePhoneError')"
                                   autocomplete="tel">
                            <div id="ePhoneError" class="form-text text-danger" style="display:none;"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="eparticipant_org">Organization</label>
                            <input type="text" name="organization" id="eparticipant_org" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['organization'] ?? $participant['organization']); ?>"
                                   placeholder="Institution, business..."
                                   autocomplete="organization">
                        </div>
                    </div>

                    <!-- Experience & Profile -->
                    <div class="row g-3 mt-2">
                        <div class="col-12">
                            <span class="form-label fw-medium">Experience & Work Profile</span>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="eexperience_years_select">Years of Experience</label>
                            <select name="experience_years" id="eexperience_years_select" class="form-select">
                                <option value="">Select...</option>
                                <?php $expVal = $_POST['experience_years'] ?? $participant['experience_years']; ?>
                                <?php foreach (getExperienceOptions() as $exp): ?>
                                    <option value="<?php echo $exp; ?>" <?php echo $expVal === $exp ? 'selected' : ''; ?>>
                                        <?php echo $exp; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <span class="form-label small">Service Area</span>
                            <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;">
                                <?php $svcAreas = $_POST['service_area'] ?? explode(', ', $participant['service_area'] ?? ''); ?>
                                <?php foreach (getServiceAreaOptions() as $sa): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="service_area[]"
                                               value="<?php echo $sa; ?>" id="esa_<?php echo slugifyParticipant($sa); ?>"
                                               <?php echo in_array($sa, $svcAreas) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="esa_<?php echo slugifyParticipant($sa); ?>"><?php echo $sa; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <span class="form-label small">Main Work Area</span>
                            <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;">
                                <?php $workAreas = $_POST['main_work_area'] ?? explode(', ', $participant['main_work_area'] ?? ''); ?>
                                <?php foreach (getMainWorkAreaOptions() as $wa): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="main_work_area[]"
                                               value="<?php echo $wa; ?>" id="ewa_<?php echo slugifyParticipant($wa); ?>"
                                               <?php echo in_array($wa, $workAreas) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="ewa_<?php echo slugifyParticipant($wa); ?>"><?php echo $wa; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="eprimary_role_input">Primary Role (free text)</label>
                            <input type="text" name="primary_role" id="eprimary_role_input" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['primary_role'] ?? $participant['primary_role']); ?>"
                                   placeholder="e.g., Field extension officer">
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="row g-3 mt-2">
                        <div class="col-12"><hr class="my-1"><span class="form-label fw-medium">Location</span></div>
                        <div class="col-md-4">
                            <label class="form-label small">Province</label>
                            <select class="form-select" id="eProvince" onchange="filterDistricts('eProvince','eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select Province --</option>
                                <?php $provinces = getProvinces(); $provMap = getProvinceDistrictMap(); ?>
                                <?php foreach ($provinces as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="eDistrict">District</label>
                            <select name="district" id="eDistrict" class="form-select"
                                    onchange="loadEMunicipalities('eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select District --</option>
                                <?php $edist = $_POST['district'] ?? $participant['district']; ?>
                                <?php foreach (array_keys(getLocationData()) as $d): ?>
                                    <option value="<?php echo $d; ?>" <?php echo $edist === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="eMunicipality">Municipality</label>
                            <select name="municipality" id="eMunicipality" class="form-select"
                                    onchange="loadEWards('eDistrict','eMunicipality','eWard')">
                                <option value="">-- Select District First --</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="eWard">Ward</label>
                            <select name="ward" id="eWard" class="form-select">
                                <option value="">--</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="etole_input">Tole</label>
                            <input type="text" name="tole" id="etole_input" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['tole'] ?? $participant['tole']); ?>"
                                   placeholder="Optional">
                        </div>
                    </div>

                    <!-- Altitude + GPS -->
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label small" for="epaltitude_manual">Altitude (m) <span class="text-muted fw-normal">— manual</span></label>
                            <input type="number" name="altitude" id="epaltitude_manual" class="form-control form-control-sm" step="0.01"
                                   value="<?php echo htmlspecialchars($_POST['altitude'] ?? $participant['altitude']); ?>"
                                   placeholder="e.g., 1350">
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="eGpsBtn" class="btn btn-sm btn-outline-info w-100" onclick="getEditGPS()">
                                <i class="bi bi-geo-alt"></i> 📍 Update GPS
                            </button>
                        </div>
                        <div class="col-md-6">
                            <small id="eGpsStatus" class="text-muted">
                                <?php if ($participant['latitude'] && $participant['longitude']): ?>
                                    ✅ GPS: <?php echo $participant['latitude']; ?>, <?php echo $participant['longitude']; ?>
                                    <?php if ($participant['gps_altitude']): ?> (<?php echo $participant['gps_altitude']; ?>m)<?php endif; ?>
                                <?php else: ?>
                                    📍 GPS not captured
                                <?php endif; ?>
                            </small>
                            <small id="eGpsCoords" class="text-muted d-block"></small>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Update Stakeholder
                        </button>
                        <a href="<?php echo BASE_URL; ?>/participant-view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
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

function toggleEditOtherType() {
    const otherRadio = document.getElementById('ept_Other');
    const otherDiv = document.getElementById('eOtherTypeDiv');
    if (otherRadio && otherRadio.checked) {
        otherDiv.classList.remove('d-none');
    } else {
        otherDiv.classList.add('d-none');
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
    loadEMunicipalities(distId, munId, wardId);
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

function loadEMunicipalities(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId);
    const ward = document.getElementById(wardId);
    mun.innerHTML = '<option value="">-- Select Municipality --</option>';
    ward.innerHTML = '<option value="">--</option>';
    if (dist && locationData[dist]) {
        Object.keys(locationData[dist]).forEach(m => {
            mun.innerHTML += `<option value="${m}">${m}</option>`;
        });
        <?php
        $emun = $_POST['municipality'] ?? $participant['municipality'] ?? '';
        if ($emun): ?>
        mun.value = <?php echo json_encode($emun); ?>;
        loadEWards(distId, munId, wardId);
        <?php endif; ?>
    }
}

function loadEWards(distId, munId, wardId) {
    const dist = document.getElementById(distId).value;
    const mun = document.getElementById(munId).value;
    const ward = document.getElementById(wardId);
    ward.innerHTML = '<option value="">--</option>';
    if (dist && mun && locationData[dist] && locationData[dist][mun]) {
        locationData[dist][mun].forEach(w => {
            ward.innerHTML += `<option value="${w}">Ward ${w}</option>`;
        });
        <?php
        $eward = htmlspecialchars($_POST['ward'] ?? $participant['ward'] ?? '');
        if ($eward): ?>
        ward.value = <?php echo json_encode($eward); ?>;
        <?php endif; ?>
    }
}

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
            if (alt) {
                document.getElementById('eGpsAltField').value = alt;
                var visAlt = document.querySelector('[name="altitude"]');
                if (visAlt) visAlt.value = alt;
            }
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

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($_POST['district']) || !empty($participant['district'])): ?>
    loadEMunicipalities('eDistrict', 'eMunicipality', 'eWard');
    <?php endif; ?>
    toggleEditOtherType();
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
