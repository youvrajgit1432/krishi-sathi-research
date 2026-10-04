<?php
/**
 * Krishi Sathi Research System - Database Configuration
 * Auto-detects localhost (XAMPP) vs production (research.phooldelivery.example)
 * Same codebase, no manual changes needed.
 */

// ─── Error Reporting ────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ─── Session (hardened) ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Use strict mode to prevent session fixation
    ini_set('session.use_strict_mode', '1');

    // Cookie params: lifetime 0 (until browser close), httponly, samesite lax
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Initialize session activity timestamp on first load
    if (empty($_SESSION['_last_activity']) && !empty($_SESSION['research_user_id'])) {
        $_SESSION['_last_activity'] = time();
    }
}

// ─── Environment Detection ──────────────────────────────────────
$http_host  = $_SERVER['HTTP_HOST'] ?? '';
$server_name = $_SERVER['SERVER_NAME'] ?? '';
$document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';

$is_localhost = (
    strpos($http_host, 'localhost') !== false ||
    strpos($http_host, '127.0.0.1') !== false ||
    strpos($server_name, 'localhost') !== false
);

define('IS_LOCALHOST', $is_localhost);

// ─── Database Credentials ───────────────────────────────────────
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'krishi_sathi_research_demo');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: ''));
$secretFile = __DIR__ . "/secret.php";
if (file_exists($secretFile)) { require $secretFile; }

// ─── Base URL (relative paths, works on localhost & online) ─────
define('BASE_PATH', '/krishi-sathi-research');
// On localhost: /phool-delivery/survey
// On online (survey.phooldelivery.example): /
define('BASE_URL', IS_LOCALHOST ? '/krishi-sathi-research' : '');
define('ASSETS_URL', BASE_URL . '/assets');
// Uploads root — stored in DB as 'uploads/research/photos/YYYY/MM/file'
// On localhost: /phool-delivery/uploads/...
// On online: /uploads/...
define('UPLOADS_ROOT', IS_LOCALHOST ? '/krishi-sathi-research' : '');

// ─── Database Connection Helper ─────────────────────────────────
// ─── Farmer ID Generation ────────────────────────────────────
// Format: FRM-XXXXX (e.g., FRM-00001, FRM-00002, ...)
// Uses MySQL advisory lock (GET_LOCK) to prevent race conditions
// when multiple users register farmers simultaneously.
function generateFarmerId(PDO $pdo): string {
    // Acquire an advisory lock to serialize ID generation
    $pdo->query("SELECT GET_LOCK('farmer_id_generation', 5)")->fetchColumn();
    try {
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(farmer_id, 5) AS UNSIGNED)) AS max_num
                               FROM research_farmers
                               WHERE farmer_id LIKE 'FRM-%'");
        $stmt->execute();
        $maxNum = (int) $stmt->fetchColumn();
        return 'FRM-' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('farmer_id_generation')")->fetchColumn();
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            error_log("Research DB Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check config.php");
        }
    }
    return $pdo;
}

// ─── Output escaping helper (XSS protection) ──────────────────────
function html_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Large Upload Detection ─────────────────────────────────────
/**
 * Detect if the POST request was rejected by PHP because the
 * Content-Length exceeds post_max_size.
 *
 * When post_max_size is exceeded, PHP discards $_POST and $_FILES
 * but leaves $_SERVER['CONTENT_LENGTH'] intact. Downstream CSRF
 * and entity-id checks then fail with misleading errors.
 *
 * Call this BEFORE any CSRF / entity-id check in POST handlers
 * that accept file uploads.
 *
 * @param string $redirectUrl  Base URL to redirect back to.
 * @param array  $preserveGet  Query-string params to preserve (e.g. ['farmer_id', 'interview_id']).
 * @return bool  TRUE if oversized, FALSE if request is normal.
 */
function checkOversizedPost(string $redirectUrl, array $preserveGet = []): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) return false;

    $postEmpty = empty($_POST) && empty($_FILES);
    if (!$postEmpty) return false;

    $postMax = return_bytes(ini_get('post_max_size'));
    $uploadMax = return_bytes(ini_get('upload_max_filesize'));
    $limit = max($postMax, $uploadMax);

    $sizeHuman = $contentLength > 0 ? round($contentLength / 1024 / 1024, 1) . ' MB' : 'unknown';
    $limitHuman = $limit > 0 ? round($limit / 1024 / 1024, 1) . ' MB' : 'unknown';

    $msg = 'The uploaded file (' . $sizeHuman . ') exceeds the server upload limit (' . $limitHuman . '). '
         . 'Please reduce the file size or ask your administrator to increase upload_max_filesize and post_max_size in php.ini.';

    setFlash('error', $msg);

    // Preserve specified GET params in the redirect
    $qs = [];
    foreach ($preserveGet as $key) {
        $val = $_GET[$key] ?? '';
        if ($val !== '') {
            $qs[] = urlencode($key) . '=' . urlencode($val);
        }
    }
    $sep = strpos($redirectUrl, '?') === false ? '?' : '&';
    header('Location: ' . $redirectUrl . (count($qs) > 0 ? $sep . implode('&', $qs) : ''));
    exit;
}

/**
 * Convert php.ini size string (e.g., '8M', '1G') to bytes.
 * (Duplicated in config-media.php for standalone use.)
 */
if (!function_exists('return_bytes')) {
    function return_bytes(string $val): int {
        $val = trim($val);
        if ($val === '' || $val === '-1') return 0;
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }
}

// ─── CSRF token helpers (protect POST forms) ──────────────────────
function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="_csrf_token" value="' . html_escape($token) . '">';
}

function verify_csrf(string $token): bool {
    if (empty($_SESSION['_csrf_token'])) return false;
    $valid = hash_equals($_SESSION['_csrf_token'], $token);
    // Optionally rotate token on successful use
    if ($valid) {
        unset($_SESSION['_csrf_token']);
    }
    return $valid;
}

// ─── Research Contributor Data Protection (ROLE-ACCESS-01.3) ────

/**
 * Check if the current user can view approved/protected research content.
 * - Research Lead: always yes
 * - Research Editor: always yes
 * - Research Contributor: NO — blocked from viewing approved records
 * - Research Viewer: NO
 */
function canViewApprovedContent(): bool {
    return isLead() || isEditor();
}

/**
 * Log an unauthorized access attempt to the audit log.
 * Includes user, role, requested record, requested action, and timestamp.
 */
function logUnauthorizedAccess(PDO $pdo, string $action, string $entityType, ?int $entityId = null, string $details = ''): void {
    $roleLabel = currentUserRole();
    $roleLabels = ['lead' => 'Research Lead', 'editor' => 'Research Editor', 'contributor' => 'Research Contributor', 'viewer' => 'Research Viewer'];
    $roleLabel = $roleLabels[$roleLabel] ?? ucfirst($roleLabel);
    $details = sprintf(
        'UNAUTHORIZED ACCESS - User: %s (ID: %d) | Role: %s | Action: %s | Entity: %s (ID: %s) | %s',
        currentUserName(),
        currentUserId() ?: 0,
        $roleLabel,
        $action,
        $entityType,
        $entityId ? (string) $entityId : 'N/A',
        $details
    );
    logAudit($pdo, 'unauthorized_' . $action, $entityType, $entityId, $details);
}

/**
 * Check if the current user can view a specific farmer.
 * ROLE-ACCESS-01.3: Contributors cannot view any approved farmers (including their own).
 */
function canViewFarmer(array $farmer): bool {
    if (isViewer() || isDeleted($farmer)) return false;
    if (isLead() || isEditor()) return true;
    if (isContributor()) {
        // Contributors cannot view approved farmers at all
        if (($farmer['approval_status'] ?? '') === 'approved') return false;
        // Can only view own farmers
        return (int) ($farmer['created_by'] ?? 0) === (int) currentUserId();
    }
    return false;
}

/**
 * Check if the current user can view a specific interview.
 * ROLE-ACCESS-01.3: Contributors cannot view approved interviews.
 */
function canViewInterview(array $interview): bool {
    if (isViewer() || isDeleted($interview)) return false;
    if (isLead() || isEditor()) return true;
    if (isContributor()) {
        // Block approved interviews
        if (normalizeInterviewStatus($interview['interview_status'] ?? null) === 'approved') return false;
        // Can only view own interviews
        return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId();
    }
    return false;
}

/**
 * Check if the current user can view a specific observation.
 * ROLE-ACCESS-01.3: Contributors cannot view observations linked to approved interviews.
 */
function canViewObservation(array $observation): bool {
    if (isViewer() || isDeleted($observation)) return false;
    if (isLead() || isEditor()) return true;
    if (isContributor()) {
        // If the observation's linked interview is approved, block
        if (isRecordLocked($observation)) return false;
        // Can view any non-approved observation
        return true;
    }
    return false;
}

/**
 * Check if the current user can view consent records.
 * Only Lead and Editor can view consent details.
 */
function canViewConsent(): bool {
    return isLead() || isEditor();
}

/**
 * Check if the current user can print research data.
 * Only Lead and Editor can print.
 */
function canPrint(): bool {
    return isLead() || isEditor();
}

/**
 * Check if the current user can download research files.
 * Only Lead and Editor can download.
 */
function canDownload(): bool {
    return isLead() || isEditor();
}

// ─── Date Filter Presets ─────────────────────────────────────────

/**
 * Get the list of available date filter presets.
 */
function getDatePresets(): array {
    return [
        'today'          => 'Today',
        'yesterday'      => 'Yesterday',
        'last_7_days'    => 'Last 7 Days',
        'last_30_days'   => 'Last 30 Days',
        'this_month'     => 'This Month',
        'previous_month' => 'Previous Month',
        'custom'         => 'Custom Range',
    ];
}

/**
 * Compute date_from and date_to for a given preset.
 * Returns [date_from, date_to].
 */
function computePresetDates(string $preset): array {
    switch ($preset) {
        case 'today':
            return [date('Y-m-d'), date('Y-m-d')];
        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day'));
            return [$y, $y];
        case 'last_7_days':
            return [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')];
        case 'last_30_days':
            return [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')];
        case 'this_month':
            return [date('Y-m-01'), date('Y-m-d')];
        case 'previous_month':
            $prev = strtotime('first day of last month');
            $end = strtotime('last day of last month');
            return [date('Y-m-d', $prev), date('Y-m-d', $end)];
        default:
            return ['', ''];
    }
}

/**
 * Resolve the effective date filter from GET params and session.
 *
 * Priority:
 * 1. If date_preset is in GET → save to session, use it.
 * 2. If date_from/date_to in GET → use them (custom range), set session.
 * 3. If session has a stored preset → use it.
 * 4. Default to last_30_days.
 *
 * Returns [preset, date_from, date_to] where preset is the resolved preset key.
 */
function resolveDateFilter(): array {
    $sessionKey = 'date_filter_preset';

    // Priority 1: preset in GET params
    if (isset($_GET['date_preset'])) {
        $preset = $_GET['date_preset'];
        if ($preset === 'custom' && isset($_GET['date_from'], $_GET['date_to'])) {
            $_SESSION[$sessionKey] = 'custom';
            return ['custom', $_GET['date_from'], $_GET['date_to']];
        }
        if (array_key_exists($preset, getDatePresets())) {
            $_SESSION[$sessionKey] = $preset;
            [$df, $dt] = computePresetDates($preset);
            return [$preset, $df, $dt];
        }
    }

    // Priority 2: date_from/date_to in GET (no preset) → custom
    if (isset($_GET['date_from']) || isset($_GET['date_to'])) {
        $df = $_GET['date_from'] ?? '';
        $dt = $_GET['date_to'] ?? '';
        $_SESSION[$sessionKey] = 'custom';
        $_SESSION['date_filter_from'] = $df;
        $_SESSION['date_filter_to'] = $dt;
        return ['custom', $df, $dt];
    }

    // Priority 3: session stored preset
    $stored = $_SESSION[$sessionKey] ?? null;
    if ($stored !== null && $stored !== 'custom' && array_key_exists($stored, getDatePresets())) {
        [$df, $dt] = computePresetDates($stored);
        return [$stored, $df, $dt];
    }

    // Priority 4: custom range dates stored in session
    if ($stored === 'custom' && isset($_SESSION['date_filter_from'], $_SESSION['date_filter_to'])) {
        return ['custom', $_SESSION['date_filter_from'], $_SESSION['date_filter_to']];
    }

    // Default: last 30 days
    $_SESSION[$sessionKey] = 'last_30_days';
    [$df, $dt] = computePresetDates('last_30_days');
    return ['last_30_days', $df, $dt];
}

/**
 * Render the quick date preset buttons/select.
 * $currentPreset: the currently selected preset key.
 */
function renderDatePresets(string $currentPreset): string {
    $presets = getDatePresets();
    $html = '<div class="d-flex align-items-center gap-1 flex-wrap">';
    foreach ($presets as $key => $label) {
        $active = $key === $currentPreset ? ' btn-primary' : ' btn-outline-secondary';
        $html .= '<button type="button" class="btn btn-sm' . $active . ' date-preset-btn" data-preset="' . $key . '">' . htmlspecialchars($label) . '</button>';
    }
    $html .= '</div>';
    return $html;
}

// ─── Auth Check Helper ──────────────────────────────────────────
// ─── Session Timeout ────────────────────────────────────────────
// Automatically log out inactive users after SESSION_TIMEOUT seconds.
define('SESSION_TIMEOUT', 3600); // 1 hour (can be overridden)

/**
 * Check if the current session has timed out.
 * If timed out, destroy the session and redirect to login.
 */
function checkSessionTimeout(): void {
    if (!isset($_SESSION['research_user_id'])) return;
    
    $now = time();
    $lastActivity = $_SESSION['_last_activity'] ?? $now;
    
    if (($now - $lastActivity) > SESSION_TIMEOUT) {
        // Session expired — log out and inform user
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
        session_destroy();
        
        // Redirect with a flash message in the query string
        header('Location: ' . BASE_URL . '/index.php?expired=1');
        exit;
    }
    
    // Update last activity timestamp on every request
    $_SESSION['_last_activity'] = $now;
}

// ─── Auth Check Helper ──────────────────────────────────────────
function requireLogin(): void {
    // Check session timeout before any other auth check
    checkSessionTimeout();
    
    if (!isset($_SESSION['research_user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    // Enforce password change for users with temporary password
    requirePasswordChange();
}

function requirePasswordChange(): void {
    if (!empty($_SESSION['research_user_force_change'])) {
        // Allow access to the force password change page itself
        $current = basename($_SERVER['PHP_SELF']);
        if ($current !== 'force-password-change.php') {
            header('Location: ' . BASE_URL . '/force-password-change.php');
            exit;
        }
    }
}

function currentUserId(): ?int {
    return $_SESSION['research_user_id'] ?? null;
}

function currentUserName(): string {
    return $_SESSION['research_user_name'] ?? 'Researcher';
}

function currentUserRole(): string {
    return $_SESSION['research_user_role'] ?? 'contributor';
}

function isActive(): bool {
    return !isset($_SESSION['research_user_inactive']) || $_SESSION['research_user_inactive'] !== true;
}

function isLead(): bool {
    return currentUserRole() === 'lead';
}

function isEditor(): bool {
    return currentUserRole() === 'editor';
}

function isContributor(): bool {
    return currentUserRole() === 'contributor';
}

function isViewer(): bool {
    return currentUserRole() === 'viewer';
}

function canEdit(): bool {
    return !isViewer();
}

function canDelete(): bool {
    return isLead() || isEditor();
}

function canManageUsers(): bool {
    return isLead();
}

function canExport(): bool {
    return isLead() || isEditor();
}

function isDeleted(array $row): bool {
    return !empty($row['is_deleted']) || !empty($row['deleted_at']);
}

/**
 * Check if a record is in an approved/locked state.
 * Returns true if the record has been approved by the Research Lead.
 */
function isRecordLocked(array $row): bool {
    $status = $row['interview_status'] ?? null;
    $approval = $row['approval_status'] ?? '';
    // Interviews: 'approved' status means locked
    if ($status !== null) {
        return normalizeInterviewStatus($status) === 'approved';
    }
    // Participants/farmers: 'approved' approval_status means locked
    return $approval === 'approved';
}

function activeWhere(string $alias = ''): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "(COALESCE({$prefix}is_deleted, 0) = 0 AND {$prefix}deleted_at IS NULL)";
}

function deletedWhere(string $alias = ''): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "(COALESCE({$prefix}is_deleted, 0) = 1 OR {$prefix}deleted_at IS NOT NULL)";
}

function normalizeInterviewStatus(?string $status): string {
    $status = strtolower((string) $status);
    $legacyMap = [
        'planned' => 'draft',
        'interviewed' => 'submitted',
        'follow_up' => 'submitted',
        'completed' => 'approved',
    ];
    if (isset($legacyMap[$status])) return $legacyMap[$status];
    return in_array($status, ['draft', 'submitted', 'approved', 'rejected'], true) ? $status : 'draft';
}

function interviewStatusBadge(?string $status, bool $deleted = false): string {
    if ($deleted) {
        return '<span class="badge bg-danger"><i class="bi bi-trash"></i> Deleted</span>';
    }
    $status = normalizeInterviewStatus($status);
    $classes = [
        'draft' => 'secondary',
        'submitted' => 'primary',
        'approved' => 'success',
        'rejected' => 'warning text-dark',
    ];
    $icons = [
        'draft' => 'bi-pencil-square',
        'submitted' => 'bi-send',
        'approved' => 'bi-shield-check',
        'rejected' => 'bi-exclamation-triangle',
    ];
    $label = ucfirst($status);
    return '<span class="badge bg-' . $classes[$status] . '"><i class="bi ' . $icons[$status] . '"></i> ' . $label . '</span>';
}

function canEditInterview(array $interview): bool {
    if (isViewer() || isDeleted($interview)) return false;
    $status = normalizeInterviewStatus($interview['interview_status'] ?? null);
    // Leads can always edit (including unlocking/approving)
    if (isLead()) return true;
    // Editors cannot edit approved records
    if (isEditor()) return $status !== 'approved';
    // Contributors can only edit their own draft/rejected records
    if (isContributor()) {
        return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId()
            && in_array($status, ['draft', 'rejected'], true);
    }
    return false;
}

function canDeleteInterview(array $interview): bool {
    if (isViewer() || isDeleted($interview)) return false;
    $status = normalizeInterviewStatus($interview['interview_status'] ?? null);
    if (isLead()) return true;
    if (isEditor()) return $status !== 'approved';
    return false;
}

function canSubmitInterview(array $interview): bool {
    if (isViewer() || isDeleted($interview)) return false;
    $status = normalizeInterviewStatus($interview['interview_status'] ?? null);
    return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId()
        && in_array($status, ['draft', 'rejected'], true);
}

function canEditFarmer(array $farmer): bool {
    if (isViewer() || isDeleted($farmer)) return false;
    // Leads can always edit
    if (isLead()) return true;
    // Editors can always edit
    if (isEditor()) return !isRecordLocked($farmer);
    // Contributors can only edit their own un-approved records
    if (isContributor()) {
        return (int) ($farmer['created_by'] ?? 0) === (int) currentUserId()
            && !isRecordLocked($farmer);
    }
    return false;
}

function canEditObservation(array $observation): bool {
    if (isViewer() || isDeleted($observation)) return false;
    // Leads can always edit
    if (isLead()) return true;
    // Editors can edit unless the linked interview is approved
    if (isEditor()) return !isRecordLocked($observation);
    // Contributors can edit any un-locked observation
    if (isContributor()) {
        return !isRecordLocked($observation);
    }
    return false;
}

function canApproveInterviews(): bool {
    return isLead();
}

function canReviewInterviews(): bool {
    return isLead() || isEditor();
}

/**
 * Check if the current user can unlock an approved record.
 * Only Research Lead can unlock.
 */
function canUnlockRecord(): bool {
    return isLead();
}

/**
 * Check if the current user can upload media to a given entity.
 * Blocks uploads to approved/locked interviews.
 */
function canUploadMedia(array $entity): bool {
    if (isViewer() || isDeleted($entity)) return false;
    if (isLead()) return true;
    return !isRecordLocked($entity);
}

function canManageRecycleBin(): bool {
    return isLead();
}

function canDeleteUserRecord(array $user): bool {
    if (!isLead()) return false;
    // Cannot delete self
    if ((int) ($user['id'] ?? 0) === (int) currentUserId()) return false;
    // Cannot delete another Research Lead
    if (($user['role'] ?? '') === 'lead') return false;
    // Check that at least one active lead remains after this deletion
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM research_users WHERE role = 'lead' AND status = 'active' AND " . activeWhere());
        $stmt->execute();
        $activeLeadCount = (int) $stmt->fetchColumn();
        if ($activeLeadCount <= 1) {
            return false;
        }
    } catch (PDOException $e) {
        // If we can't check, prevent deletion to be safe
        return false;
    }
    return true;
}

function softDeleteRecord(PDO $pdo, string $table, int $id): void {
    $stmt = $pdo->prepare("UPDATE {$table} SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?");
    $stmt->execute([currentUserId(), $id]);
}

function restoreRecord(PDO $pdo, string $table, int $id): void {
    $stmt = $pdo->prepare("UPDATE {$table} SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE id = ?");
    $stmt->execute([$id]);
}



// ─── Audit Logging ────────────────────────────────────────────
/**
 * Create the audit_log table if it doesn't exist.
 */
function ensureAuditTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `research_audit_log` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `action` VARCHAR(50) NOT NULL,
            `entity_type` VARCHAR(50) NOT NULL,
            `entity_id` INT DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_audit_action` (`action`),
            INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
            INDEX `idx_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log("Failed to ensure audit table: " . $e->getMessage());
    }
}

/**
 * Get client IP address for audit logging.
 */
function getClientIP(): string {
    $sources = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($sources as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Log an auditable action to the database.
 * Safe to call from anywhere — silently fails if table doesn't exist.
 */
function logAudit(PDO $pdo, string $action, string $entityType, ?int $entityId = null, string $details = ''): void {
    try {
        $ip = getClientIP();
        $stmt = $pdo->prepare("INSERT INTO research_audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([currentUserId(), $action, $entityType, $entityId, $details, $ip]);
    } catch (PDOException $e) {
        static $retried = false;
        if (!$retried) {
            $retried = true;
            ensureAuditTable($pdo);
            try {
                $stmt = $pdo->prepare("INSERT INTO research_audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([currentUserId(), $action, $entityType, $entityId, $details, $ip]);
            } catch (PDOException $e2) {
                error_log("Audit log failed: " . $e2->getMessage());
            }
        }
    }
}

// ─── Pagination Helper ─────────────────────────────────────────
define('PER_PAGE', 50);

/**
 * Build pagination data: total pages, current page, offset.
 * Returns [page, totalPages, offset].
 */
function paginationData(int $totalRecords): array {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $totalPages = max(1, (int) ceil($totalRecords / PER_PAGE));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * PER_PAGE;
    return [$page, $totalPages, $offset];
}

/**
 * Build query string for pagination links, excluding the 'page' param.
 */
function paginationQueryString(): string {
    $params = $_GET;
    unset($params['page']);
    return http_build_query($params);
}

/**
 * Render Bootstrap 5 pagination navigation.
 */
function renderPagination(int $currentPage, int $totalPages, string $baseUrl): string {
    if ($totalPages <= 1) return '';
    
    $qs = paginationQueryString();
    $qsPrefix = $qs !== '' ? '&' : '';
    
    $html = '<nav aria-label="Page navigation" class="mt-3">';
    $html .= '<ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">';
    
    // Previous
    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $prevUrl = $baseUrl . '?page=' . ($currentPage - 1) . $qsPrefix . $qs;
    $html .= '<li class="page-item' . $prevDisabled . '">';
    $html .= '<a class="page-link" href="' . $prevUrl . '" tabindex="-1">&laquo; Prev</a></li>';
    
    // Page numbers - show window around current page
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $url = $baseUrl . '?page=1' . $qsPrefix . $qs;
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $url = $baseUrl . '?page=' . $i . $qsPrefix . $qs;
        $html .= '<li class="page-item' . $active . '">';
        $html .= '<a class="page-link" href="' . $url . '">' . $i . '</a></li>';
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $url = $baseUrl . '?page=' . $totalPages . $qsPrefix . $qs;
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">' . $totalPages . '</a></li>';
    }
    
    // Next
    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $nextUrl = $baseUrl . '?page=' . ($currentPage + 1) . $qsPrefix . $qs;
    $html .= '<li class="page-item' . $nextDisabled . '">';
    $html .= '<a class="page-link" href="' . $nextUrl . '">Next &raquo;</a></li>';
    
    $html .= '</ul>';
    $html .= '<p class="text-center text-muted small mt-1 mb-0">Page ' . $currentPage . ' of ' . $totalPages . '</p>';
    $html .= '</nav>';
    
    return $html;
}

// ─── Error/Success Helpers ──────────────────────────────────────
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function old(string $key, $default = '') {
    return $_SESSION['_old'][$key] ?? $default;
}

function saveOld(array $data): void {
    $_SESSION['_old'] = $data;
}

function clearOld(): void {
    unset($_SESSION['_old']);
}

// ─── Approval Summary Card (ROLE-ACCESS-01.1 Package B) ────────
/**
 * Render an Approval Summary Card showing approval status, approver, date, and current state.
 * @param array  $record     The record array (must contain approval_status or interview_status, approved_by, etc.)
 * @param string $entityType 'interview', 'farmer', 'participant', 'participant_interview'
 * @param string $approvedByName Display name of the approver (optional)
 */
function renderApprovalSummaryCard(array $record, string $entityType, string $approvedByName = ''): string {
    $isApproved = false;
    $approvedBy = '';
    $approvedAt = '';
    $status = '';

    if ($entityType === 'interview' || $entityType === 'participant_interview') {
        $status = normalizeInterviewStatus($record['interview_status'] ?? '');
        $isApproved = $status === 'approved';
        $approvedBy = $approvedByName ?: ($record['approved_by_name'] ?? '');
        $approvedAt = $record['approved_at'] ?? '';
    } elseif ($entityType === 'farmer') {
        $status = $record['approval_status'] ?? '';
        $isApproved = $status === 'approved';
        $approvedBy = $approvedByName ?: ($record['approved_by_name'] ?? '');
        $approvedAt = $record['approved_at'] ?? '';
    } elseif ($entityType === 'participant') {
        $status = $record['approval_status'] ?? '';
        $isApproved = $status === 'approved';
        $approvedBy = $approvedByName ?: ($record['approved_by_name'] ?? '');
        $approvedAt = $record['approved_at'] ?? '';
    }

    if (!$isApproved) {
        return '<div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark fs-6"><i class="bi bi-clock"></i> Pending Approval</span>
                    <small class="text-muted">Awaiting Research Lead review</small>
                </div>
            </div>
        </div>';
    }

    $html = '<div class="card shadow-sm border-0 mb-3 bg-success-subtle">';
    $html .= '<div class="card-body py-2">';
    $html .= '<div class="row g-2 align-items-center">';
    $html .= '<div class="col-auto">';
    $html .= '<span class="badge bg-success fs-6"><i class="bi bi-shield-check"></i> Approved</span>';
    $html .= '</div>';
    if ($approvedBy) {
        $html .= '<div class="col-auto small"><strong>Approved By:</strong> ' . htmlspecialchars($approvedBy) . '</div>';
    }
    if ($approvedAt) {
        $html .= '<div class="col-auto small"><strong>Approved On:</strong> ' . date('M j, Y', strtotime($approvedAt)) . '</div>';
    }
    $html .= '<div class="col-auto small"><strong>Current State:</strong> <span class="badge bg-success">Locked</span></div>';
    $html .= '<div class="col-auto ms-auto"><span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-shield"></i> Official Research Record</span></div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// ─── Record Timeline (ROLE-ACCESS-01.1 Package C) ────────────────
/**
 * Render a record lifecycle timeline showing key stages with dates.
 * Only shows stages that have been completed (have data).
 */
function renderRecordTimeline(array $record, string $entityType, array $extra = []): string {
    $stages = [];

    if ($entityType === 'interview' || $entityType === 'participant_interview') {
        // Created
        if (!empty($record['created_at'])) {
            $stages[] = ['Created', date('M j, Y g:i A', strtotime($record['created_at'])), 'bi-plus-circle', 'primary'];
        }
        // Interview Started — use interview_date
        if (!empty($record['interview_date'])) {
            $stages[] = ['Interview Conducted', $record['interview_date'], 'bi-chat-dots', 'info'];
        }
        // Submitted
        if (!empty($record['submitted_at'])) {
            $stages[] = ['Submitted for Approval', date('M j, Y g:i A', strtotime($record['submitted_at'])), 'bi-send', 'primary'];
        }
        // Rejected (if applicable)
        if (!empty($record['rejected_at'])) {
            $stages[] = ['Rejected', date('M j, Y g:i A', strtotime($record['rejected_at'])), 'bi-exclamation-triangle', 'warning'];
        }
        // Approved
        if (!empty($record['approved_at'])) {
            $stages[] = ['Approved & Locked', date('M j, Y g:i A', strtotime($record['approved_at'])), 'bi-shield-check', 'success'];
        }
        // Unlocked (if applicable)
        if (!empty($record['unlocked_at'])) {
            $stages[] = ['Unlocked', date('M j, Y g:i A', strtotime($record['unlocked_at'])), 'bi-unlock', 'warning'];
        }
    } elseif ($entityType === 'farmer' || $entityType === 'participant') {
        if (!empty($record['created_at'])) {
            $stages[] = ['Registered', date('M j, Y g:i A', strtotime($record['created_at'])), 'bi-person-plus', 'success'];
        }
        if (!empty($record['approved_at'])) {
            $stages[] = ['Approved', date('M j, Y g:i A', strtotime($record['approved_at'])), 'bi-shield-check', 'success'];
        }
        if (!empty($record['updated_at']) && $record['updated_at'] !== $record['created_at']) {
            $stages[] = ['Updated', date('M j, Y g:i A', strtotime($record['updated_at'])), 'bi-pencil-square', 'info'];
        }
    }

    // Add evidence uploads if provided in extra
    if (!empty($extra['media_count'])) {
        $stages[] = ['Evidence Uploaded (' . $extra['media_count'] . ' files)', '', 'bi-images', 'secondary'];
    }
    // Observation added
    if (!empty($extra['observation_count'])) {
        $stages[] = ['Observations (' . $extra['observation_count'] . ' recorded)', '', 'bi-binoculars', 'dark'];
    }
    // Consent recorded
    if (!empty($extra['consent_status'])) {
        $icon = $extra['consent_status'] === 'granted' ? 'bi-shield-check' : 'bi-x-circle';
        $color = $extra['consent_status'] === 'granted' ? 'success' : 'danger';
        $stages[] = ['Consent: ' . ucfirst($extra['consent_status']), '', $icon, $color];
    }

    if (empty($stages)) return '';

    $html = '<div class="card shadow-sm border-0 mb-3">';
    $html .= '<div class="card-header bg-white py-2 fw-semibold small"><i class="bi bi-clock-history"></i> Record Timeline</div>';
    $html .= '<div class="card-body py-2">';
    $html .= '<ul class="timeline-list mb-0" style="list-style:none;padding:0;margin:0;">';
    foreach ($stages as $i => $stage) {
        $isLast = $i === count($stages) - 1;
        $html .= '<li class="d-flex align-items-start gap-2 pb-1 position-relative">';
        // Connector line
        if (!$isLast) {
            $html .= '<div class="position-absolute" style="left:10px;top:22px;bottom:0;width:2px;background:#e9ecef;"></div>';
        }
        $html .= '<span class="badge bg-' . $stage[3] . ' rounded-circle p-1 mt-1 flex-shrink-0" style="width:22px;height:22px;">';
        $html .= '<i class="bi ' . $stage[2] . ' text-white small"></i>';
        $html .= '</span>';
        $html .= '<div class="small">';
        $html .= '<div class="fw-medium">' . htmlspecialchars($stage[0]) . '</div>';
        if ($stage[1]) {
            $html .= '<div class="text-muted" style="font-size:0.75rem;">' . htmlspecialchars($stage[1]) . '</div>';
        }
        $html .= '</div>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// ─── Unlock Categories (ROLE-ACCESS-01.2 Package B) ─────────────
define('UNLOCK_CATEGORIES', [
    'Data Entry Error',
    'Missing Evidence',
    'Incorrect Farmer Information',
    'Incorrect Stakeholder Information',
    'Research Review',
    'Duplicate Record',
    'Farmer Requested Correction',
    'Media Upload Error',
    'Observation Correction',
    'Consent Correction',
    'Other',
]);

/**
 * Render an Unlock History card showing all unlock events for a record.
 * Queries the audit log for 'interview_unlocked' or 'participant_interview_unlocked' actions.
 */
function renderUnlockHistory(PDO $pdo, string $entityType, int $entityId): string {
    $stmt = $pdo->prepare("
        SELECT al.*, ru.full_name AS unlocked_by_name
        FROM research_audit_log al
        LEFT JOIN research_users ru ON al.user_id = ru.id
        WHERE al.entity_type = ? AND al.entity_id = ?
          AND al.action IN ('interview_unlocked', 'participant_interview_unlocked')
        ORDER BY al.created_at DESC
    ");
    $stmt->execute([$entityType, $entityId]);
    $unlocks = $stmt->fetchAll();

    if (empty($unlocks)) return '';

    $html = '<div class="card shadow-sm border-0 mb-3">';
    $html .= '<div class="card-header bg-white py-2 fw-semibold small">';
    $html .= '<i class="bi bi-unlock text-warning"></i> Unlock History</div>';
    $html .= '<div class="card-body py-2">';

    foreach ($unlocks as $ul) {
        $details = $ul['details'] ?? '';
        // Parse structured details: 'Unlocked: {reason}' or 'Category: {cat} | Reason: {reason} | Notes: {notes}'
        $category = '';
        $reason = '';
        $notes = '';

        // Format with Role (current)
        if (preg_match('/^Category: (.+?) \| Reason: (.+?) \| Role: (.+?)(?: \| Notes: (.+))?$/s', $details, $m)) {
            $category = $m[1];
            $reason = $m[2];
            $role = $m[3];
            $notes = $m[4] ?? '';
        }
        // Format without Role (entries created during development before Role was added)
        elseif (preg_match('/^Category: (.+?) \| Reason: (.+?)(?: \| Notes: (.+))?$/s', $details, $m)) {
            $category = $m[1];
            $reason = $m[2];
            $notes = $m[3] ?? '';
        }
        // Old format (pre-buildUnlockDetails)
        elseif (preg_match('/^Unlocked: (.+)$/s', $details, $m)) {
            $reason = $m[1];
        }

        $time = strtotime($ul['created_at']);
        $dateFormatted = $time ? date('M j, Y', $time) : '';
        $timeFormatted = $time ? date('g:i A', $time) : '';
        $unlockerName = $ul['unlocked_by_name'] ?: ('User #' . $ul['user_id']);

        $html .= '<div class="mb-2 pb-2 border-bottom border-light">';
        $html .= '<div class="d-flex justify-content-between align-items-center small">';
        $html .= '<span><i class="bi bi-person"></i> <strong>' . htmlspecialchars($unlockerName) . '</strong>';
        if ($dateFormatted) {
            $html .= ' &mdash; ' . $dateFormatted . ' at ' . $timeFormatted;
        }
        $html .= '</span>';
        $html .= '</div>';
        if ($category) {
            $html .= '<div class="small mt-1"><span class="badge bg-warning text-dark">' . htmlspecialchars($category) . '</span></div>';
        }
        if ($reason) {
            $html .= '<div class="small text-muted mt-1">' . htmlspecialchars($reason) . '</div>';
        }
        if ($notes) {
            $html .= '<div class="small text-muted" style="font-size:0.7rem;"><em>Notes: ' . htmlspecialchars($notes) . '</em></div>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Build structured unlock details string for audit logging.
 */
function buildUnlockDetails(string $category, string $reason, string $notes = ''): string {
    $role = currentUserRole();
    $roleLabels = ['lead' => 'Research Lead', 'editor' => 'Editor', 'contributor' => 'Contributor', 'viewer' => 'Viewer'];
    $roleLabel = $roleLabels[$role] ?? ucfirst($role);
    $details = 'Category: ' . $category . ' | Reason: ' . mb_substr(str_replace('|', '/', $reason), 0, 480) . ' | Role: ' . $roleLabel;
    if ($notes !== '') {
        $details .= ' | Notes: ' . mb_substr(str_replace('|', '/', $notes), 0, 480);
    }
    return $details;
}

// ─── Consent System Helpers (Phase 2C) ──────────────────────────

/**
 * Get the latest consent record for a farmer.
 */
function getFarmerConsent(PDO $pdo, int $farmerId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM research_consent_records WHERE farmer_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$farmerId]);
    $consent = $stmt->fetch();
    return $consent ?: null;
}

/**
 * Get the latest consent record for a stakeholder.
 */
function getParticipantConsent(PDO $pdo, int $participantId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM research_consent_records WHERE participant_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$participantId]);
    $consent = $stmt->fetch();
    return $consent ?: null;
}

/**
 * Check if a consent record is active (granted + not withdrawn/expired).
 */
function hasActiveConsent(?array $consent): bool {
    if (!$consent) return false;
    return $consent['consent_status'] === 'granted';
}

/**
 * Render a consent status badge.
 */
function consentBadge(?array $consent): string {
    if (!$consent) {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> No Consent</span>';
    }
    if ($consent['consent_status'] === 'granted') {
        $method = $consent['consent_method'] ?? 'unknown';
        $date = date('M j, Y', strtotime($consent['consent_date']));
        $methodLabels = ['verbal' => 'Verbal', 'written_digital' => 'Digital', 'written_physical' => 'Physical', 'implied' => 'Implied'];
        $methodLabel = $methodLabels[$method] ?? ucfirst($method);
        return '<span class="badge bg-success" title="Consented ' . $date . ' (' . $methodLabel . ')"><i class="bi bi-check-circle"></i> Consent Granted</span>';
    }
    if ($consent['consent_status'] === 'withdrawn') {
        return '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-counterclockwise"></i> Withdrawn</span>';
    }
    return '<span class="badge bg-secondary"><i class="bi bi-clock"></i> Expired</span>';
}

/**
 * Get consent compliance stats for dashboard.
 */
function canRecordConsent(): bool {
    return !isViewer();
}

function getConsentStats(PDO $pdo): array {
    $totalFarmers = (int) $pdo->query("SELECT COUNT(*) FROM research_farmers WHERE " . activeWhere())->fetchColumn();
    $totalParticipants = (int) $pdo->query("SELECT COUNT(*) FROM research_participants WHERE " . activeWhere())->fetchColumn();
    $consentedFarmers = (int) $pdo->query("SELECT COUNT(DISTINCT farmer_id) FROM research_consent_records WHERE consent_status = 'granted' AND farmer_id IS NOT NULL")->fetchColumn();
    $consentedParticipants = (int) $pdo->query("SELECT COUNT(DISTINCT participant_id) FROM research_consent_records WHERE consent_status = 'granted' AND participant_id IS NOT NULL")->fetchColumn();
    $withdrawn = (int) $pdo->query("SELECT COUNT(*) FROM research_consent_records WHERE consent_status = 'withdrawn'")->fetchColumn();
    $totalEntities = $totalFarmers + $totalParticipants;
    $totalConsented = $consentedFarmers + $consentedParticipants;
    $pct = $totalEntities > 0 ? round(($totalConsented / $totalEntities) * 100) : 0;
    return [
        'total_farmers' => $totalFarmers,
        'total_participants' => $totalParticipants,
        'consented_farmers' => $consentedFarmers,
        'consented_participants' => $consentedParticipants,
        'total_consented' => $totalConsented,
        'total_entities' => $totalEntities,
        'percentage' => $pct,
        'withdrawn' => $withdrawn,
        'no_consent_farmers' => $totalFarmers - $consentedFarmers,
        'no_consent_participants' => $totalParticipants - $consentedParticipants,
    ];
}
