<?php
/**
 * Krishi Sathi Research System - Record Consent
 * Phase 2C: Evidence & Ethics Infrastructure
 *
 * Records informed consent for a farmer or stakeholder participant.
 * Supports consent form file upload (PDF/image), IRB reference, witness.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!canRecordConsent()) {
    setFlash('error', 'Viewers cannot record consent.');
    header('Location: ' . BASE_URL . '/consent.php');
    exit;
}

$pdo = getDB();
$errors = [];

// Pre-select farmer or participant from query params
$preselectedFarmerId = (int) ($_GET['farmer_id'] ?? 0);
$preselectedParticipantId = (int) ($_GET['participant_id'] ?? 0);

// Fetch dropdown data
$farmers = $pdo->query("SELECT id, name, farmer_id FROM research_farmers WHERE " . activeWhere() . " ORDER BY name ASC")->fetchAll();
$participants = $pdo->query("SELECT id, name, participant_id FROM research_participants WHERE " . activeWhere() . " ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Early oversized-post detection (must be BEFORE any POST data access)
    checkOversizedPost(BASE_URL . '/consent-add.php', ['farmer_id', 'participant_id']);

    $consentType = $_POST['consent_type'] ?? '';
    $farmerId = (int) ($_POST['farmer_id'] ?? 0);
    $participantId = (int) ($_POST['participant_id'] ?? 0);
    $consentDate = trim($_POST['consent_date'] ?? '');
    $consentMethod = $_POST['consent_method'] ?? '';
    $consentStatus = $_POST['consent_status'] ?? 'granted';
    $irbReference = trim($_POST['irb_reference'] ?? '');
    $witnessName = trim($_POST['witness_name'] ?? '');
    $witnessRelationship = trim($_POST['witness_relationship'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $formFile = $_FILES['consent_form_file'] ?? null;

    // Validation
    if (!in_array($consentType, ['farmer', 'stakeholder', 'photo', 'interview', 'audio'])) {
        $errors[] = 'Valid consent type is required.';
    }
    if ($consentType === 'farmer' && $farmerId <= 0) {
        $errors[] = 'Please select a farmer.';
    }
    if ($consentType === 'stakeholder' && $participantId <= 0) {
        $errors[] = 'Please select a stakeholder.';
    }
    if ($consentDate === '') {
        $errors[] = 'Consent date is required.';
    }
    if (!in_array($consentMethod, ['verbal', 'written_digital', 'written_physical', 'implied'])) {
        $errors[] = 'Valid consent method is required.';
    }
    if (!in_array($consentStatus, ['granted', 'withdrawn', 'expired'])) {
        $consentStatus = 'granted';
    }
    if ($irbReference !== '' && strlen($irbReference) > 100) {
        $errors[] = 'IRB reference must not exceed 100 characters.';
    }
    if ($witnessName !== '' && strlen($witnessName) > 100) {
        $errors[] = 'Witness name must not exceed 100 characters.';
    }

    // Handle consent form file upload
    $formFilename = null;
    $formOriginalName = null;

    if ($formFile && $formFile['error'] === UPLOAD_ERR_OK) {
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $formFile['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMime)) {
            $errors[] = 'Consent form must be a PDF, JPEG, PNG, GIF, or WebP image.';
        } elseif ($formFile['size'] > 10 * 1024 * 1024) {
            $errors[] = 'Consent form file must be under 10 MB.';
        } else {
            // Ensure upload directory
            $baseDir = __DIR__ . '/../uploads/research/consent';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0755, true);
            }
            $ext = pathinfo($formFile['name'], PATHINFO_EXTENSION);
            $base = time() . '_' . bin2hex(random_bytes(4));
            $formFilename = 'uploads/research/consent/' . $base . '.' . ($ext === 'pdf' ? 'pdf' : 'webp');
            $targetPath = $baseDir . '/' . $base . '.' . ($ext === 'pdf' ? 'pdf' : 'webp');

            // Convert images to WebP, keep PDF as-is
            if ($mime === 'application/pdf') {
                if (!move_uploaded_file($formFile['tmp_name'], $targetPath)) {
                    $errors[] = 'Failed to save consent form.';
                }
            } else {
                $image = null;
                switch ($mime) {
                    case 'image/jpeg': $image = @imagecreatefromjpeg($formFile['tmp_name']); break;
                    case 'image/png': $image = @imagecreatefrompng($formFile['tmp_name']); if ($image) { imagepalettetotruecolor($image); } break;
                    case 'image/gif': $image = @imagecreatefromgif($formFile['tmp_name']); break;
                    case 'image/webp': $image = @imagecreatefromwebp($formFile['tmp_name']); break;
                }
                if ($image) {
                    $converted = imagewebp($image, $targetPath, 80);
                    imagedestroy($image);
                    if (!$converted) $errors[] = 'Failed to convert consent form to WebP.';
                } else {
                    // Fallback: copy as original
                    $formFilename = 'uploads/research/consent/' . $base . '.' . $ext;
                    $targetPath = $baseDir . '/' . $base . '.' . $ext;
                    if (!move_uploaded_file($formFile['tmp_name'], $targetPath)) {
                        $errors[] = 'Failed to save consent form.';
                    }
                }
            }
            $formOriginalName = $formFile['name'];
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO research_consent_records
                (farmer_id, participant_id, consent_date, consent_type, consent_status,
                 consent_method, consent_form_filename, consent_form_original_name,
                 irb_reference, researcher_id, witness_name, witness_relationship, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $consentType === 'farmer' ? $farmerId : null,
                $consentType === 'stakeholder' ? $participantId : null,
                $consentDate,
                $consentType,
                $consentStatus,
                $consentMethod,
                $formFilename,
                $formOriginalName,
                $irbReference ?: null,
                currentUserId(),
                $witnessName ?: null,
                $witnessRelationship ?: null,
                $notes ?: null,
            ]);
            logAudit($pdo, 'consent_recorded', $consentType === 'farmer' ? 'farmer' : 'participant',
                $consentType === 'farmer' ? $farmerId : $participantId,
                'Consent recorded (' . $consentMethod . ') for ' . ($consentType === 'farmer' ? 'farmer' : 'stakeholder'));
            $fromPage = $_GET['from'] ?? '';
            $redirectUrl = BASE_URL . '/consent.php';
            if ($fromPage === 'interview' && $consentType === 'farmer') {
                $redirectUrl = BASE_URL . '/farmers.php';
            } elseif ($fromPage === 'interview' && $consentType === 'stakeholder') {
                $redirectUrl = BASE_URL . '/participants.php';
            }
            setFlash('success', 'Consent recorded successfully. Research workflow complete for this participant.');
            header('Location: ' . $redirectUrl);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Record Consent - Krishi Sathi Research';
include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><i class="bi bi-shield-check text-success"></i> Record Informed Consent</h5>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?php echo $e; ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . (isset($_GET['from']) ? '?from=' . urlencode($_GET['from']) : '')); ?>">
                    <?php echo csrf_field(); ?>

                    <!-- Consent Type -->
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Consent Type <span class="text-danger">*</span></label>
                            <select name="consent_type" id="consentTypeSelect" class="form-select" onchange="toggleEntitySelect()">
                                <option value="">Select...</option>
                                <option value="farmer" <?php echo ($_POST['consent_type'] ?? ($preselectedFarmerId > 0 ? 'farmer' : '')) === 'farmer' ? 'selected' : ''; ?>>Farmer Participant</option>
                                <option value="stakeholder" <?php echo ($_POST['consent_type'] ?? ($preselectedParticipantId > 0 ? 'stakeholder' : '')) === 'stakeholder' ? 'selected' : ''; ?>>Stakeholder</option>
                                <option value="photo" <?php echo ($_POST['consent_type'] ?? '') === 'photo' ? 'selected' : ''; ?>>Photo Consent</option>
                                <option value="interview" <?php echo ($_POST['consent_type'] ?? '') === 'interview' ? 'selected' : ''; ?>>Interview Consent</option>
                                <option value="audio" <?php echo ($_POST['consent_type'] ?? '') === 'audio' ? 'selected' : ''; ?>>Audio Recording</option>
                            </select>
                        </div>
                    </div>

                    <!-- Entity Select (farmer/stakeholder) -->
                    <div id="farmerSelectDiv" class="mt-3" style="display:none;">
                        <label class="form-label">Farmer <span class="text-danger">*</span></label>
                        <select name="farmer_id" class="form-select">
                            <option value="">Select Farmer...</option>
                            <?php foreach ($farmers as $f): ?>
                                <option value="<?php echo $f['id']; ?>"
                                    <?php echo ((int) ($_POST['farmer_id'] ?? $preselectedFarmerId)) === (int) $f['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($f['name'] . ' (' . $f['farmer_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="participantSelectDiv" class="mt-3" style="display:none;">
                        <label class="form-label">Stakeholder <span class="text-danger">*</span></label>
                        <select name="participant_id" class="form-select">
                            <option value="">Select Stakeholder...</option>
                            <?php foreach ($participants as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                    <?php echo ((int) ($_POST['participant_id'] ?? $preselectedParticipantId)) === (int) $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name'] . ' (' . $p['participant_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Consent Date <span class="text-danger">*</span></label>
                            <input type="date" name="consent_date" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['consent_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Consent Method <span class="text-danger">*</span></label>
                            <select name="consent_method" class="form-select">
                                <option value="">Select...</option>
                                <option value="verbal" <?php echo ($_POST['consent_method'] ?? '') === 'verbal' ? 'selected' : ''; ?>>🫂 Verbal Consent</option>
                                <option value="written_digital" <?php echo ($_POST['consent_method'] ?? '') === 'written_digital' ? 'selected' : ''; ?>>📄 Written (Digital Form)</option>
                                <option value="written_physical" <?php echo ($_POST['consent_method'] ?? '') === 'written_physical' ? 'selected' : ''; ?>>📝 Written (Physical Form)</option>
                                <option value="implied" <?php echo ($_POST['consent_method'] ?? '') === 'implied' ? 'selected' : ''; ?>>🔍 Implied Consent</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Consent Form (PDF/Image)</label>
                            <input type="file" name="consent_form_file" class="form-control form-control-sm"
                                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp">
                            <small class="text-muted">Optional. Max 10 MB. PDF, JPEG, PNG, GIF, WebP.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="consent_status" class="form-select">
                                <option value="granted" selected>✅ Granted</option>
                                <option value="withdrawn">↩️ Withdrawn</option>
                                <option value="expired">⏰ Expired</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">
                    <label class="form-label fw-medium">IRB & Witness (Optional)</label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small">IRB Reference</label>
                            <input type="text" name="irb_reference" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_POST['irb_reference'] ?? ''); ?>"
                                   placeholder="e.g., IRB-2026-001" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Witness Name</label>
                            <input type="text" name="witness_name" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_POST['witness_name'] ?? ''); ?>"
                                   placeholder="e.g., Sita Sharma" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Witness Relationship</label>
                            <input type="text" name="witness_relationship" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_POST['witness_relationship'] ?? ''); ?>"
                                   placeholder="e.g., Village elder, Neighbor">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label small">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Any additional notes about the consent process..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Record Consent</button>
                        <a href="<?php echo BASE_URL; ?>/consent.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleEntitySelect() {
    var type = document.getElementById('consentTypeSelect').value;
    document.getElementById('farmerSelectDiv').style.display = type === 'farmer' ? 'block' : 'none';
    document.getElementById('participantSelectDiv').style.display = type === 'stakeholder' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    toggleEntitySelect();
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
