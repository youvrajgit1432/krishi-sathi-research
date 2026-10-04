<?php
/**
 * Krishi Sathi Research System — Other Stakeholder Research Module
 * Shared configuration, RBAC helpers, and option sets.
 *
 * This module is COMPLETELY ISOLATED from the farmer research module.
 * It does NOT modify any existing tables, files, or functionality.
 *
 * Reuses shared infrastructure from config.php:
 *   - getDB(), isLead(), isEditor(), isContributor(), isViewer()
 *   - currentUserId(), currentUserName()
 *   - activeWhere(), deletedWhere()
 *   - softDeleteRecord(), restoreRecord()
 *   - csrf_field(), csrf_token(), verify_csrf()
 *   - logAudit(), setFlash(), getFlash(), old(), saveOld(), clearOld()
 *   - paginationData(), renderPagination()
 *   - htmlspecialchars() for XSS prevention
 */

// ─── Participant ID Generation ───────────────────────────────────
// Format: PXXXX (e.g., P0001, P0002, ..., P0123)
// Uses MySQL advisory lock (GET_LOCK) to prevent race conditions
// when multiple users register stakeholders simultaneously.
function generateParticipantId(PDO $pdo): string {
    // Acquire an advisory lock to serialize ID generation
    $pdo->query("SELECT GET_LOCK('participant_id_generation', 5)")->fetchColumn();
    try {
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(participant_id, 2) AS UNSIGNED)) AS max_num
                               FROM research_participants
                               WHERE participant_id LIKE 'P%'");
        $stmt->execute();
        $maxNum = (int) $stmt->fetchColumn();
        return 'P' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('participant_id_generation')")->fetchColumn();
    }
}

// ─── Participant Types ───────────────────────────────────────────
function getParticipantTypes(): array {
    return [
        'Agrovet Owner',
        'Agriculture Technician',
        'Municipality Agriculture Officer',
        'Cooperative Leader',
        'Veterinarian',
        'Dairy Farmer',
        'Poultry Farmer',
        'Goat Farmer',
        'Trader',
        'Collection Center',
        'Researcher',
        'Other',
    ];
}

// ─── Experience Level Options ────────────────────────────────────
function getExperienceOptions(): array {
    return [
        'Less than 1 Year',
        '1–3 Years',
        '4–7 Years',
        '8–15 Years',
        'More than 15 Years',
    ];
}

// ─── Service Area Options ────────────────────────────────────────
function getServiceAreaOptions(): array {
    return [
        'Local Village',
        'Municipality',
        'District',
        'Province',
        'National',
    ];
}

// ─── Main Work Area Options ──────────────────────────────────────
function getMainWorkAreaOptions(): array {
    return [
        'Crops', 'Vegetables', 'Fruits', 'Livestock', 'Dairy',
        'Poultry', 'Goat Farming', 'Fisheries', 'Mixed Agriculture',
        'Research', 'Government Services', 'Market Services', 'Other',
    ];
}

// ─── Research Importance Options ─────────────────────────────────
function getResearchImportanceOptions(): array {
    return ['Low', 'Medium', 'High', 'Critical'];
}

// ─── Interview Quality Options ───────────────────────────────────
function getInterviewQualityOptions(): array {
    return ['Poor', 'Fair', 'Good', 'Excellent'];
}

// ─── Recommended Action Options ──────────────────────────────────
function getRecommendedActionOptions(): array {
    return [
        'Follow-up Required',
        'New Research Question Needed',
        'Product Opportunity',
        'Architecture Review Needed',
        'Validation Complete',
        'No Action',
    ];
}

// ─── Interview Status Helpers ────────────────────────────────────

/**
 * Normalize participant interview status to standard values.
 * Maps legacy statuses to current values.
 */
function normalizeParticipantInterviewStatus(?string $status): string {
    $status = strtolower((string) $status);
    $legacyMap = [
        'planned'     => 'draft',
        'interviewed' => 'submitted',
        'follow_up'   => 'submitted',
        'completed'   => 'approved',
    ];
    if (isset($legacyMap[$status])) return $legacyMap[$status];
    return in_array($status, ['draft', 'submitted', 'approved', 'rejected'], true) ? $status : 'draft';
}

/**
 * Render a Bootstrap badge for participant interview status.
 */
function participantInterviewStatusBadge(?string $status, bool $deleted = false): string {
    if ($deleted) {
        return '<span class="badge bg-danger"><i class="bi bi-trash"></i> Deleted</span>';
    }
    $status = normalizeParticipantInterviewStatus($status);
    $classes = [
        'draft'     => 'secondary',
        'submitted' => 'primary',
        'approved'  => 'success',
        'rejected'  => 'warning text-dark',
    ];
    $icons = [
        'draft'     => 'bi-pencil-square',
        'submitted' => 'bi-send',
        'approved'  => 'bi-shield-check',
        'rejected'  => 'bi-exclamation-triangle',
    ];
    $label = ucfirst($status);
    $cls = $classes[$status] ?? 'secondary';
    $ico = $icons[$status] ?? 'bi-question';
    return '<span class="badge bg-' . $cls . '"><i class="bi ' . $ico . '"></i> ' . $label . '</span>';
}

/**
 * Render a Bootstrap badge for participant type, color-coded.
 */
function participantTypeBadge(string $type): string {
    $colors = [
        'Agrovet Owner'                   => 'primary',
        'Agriculture Technician'          => 'success',
        'Municipality Agriculture Officer' => 'warning text-dark',
        'Cooperative Leader'              => 'info',
        'Veterinarian'                    => 'danger',
        'Dairy Farmer'                    => 'primary',
        'Poultry Farmer'                  => 'warning text-dark',
        'Goat Farmer'                     => 'success',
        'Trader'                          => 'secondary',
        'Collection Center'               => 'info',
        'Researcher'                      => 'dark',
        'Other'                           => 'light text-dark',
    ];
    $color = $colors[$type] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . htmlspecialchars($type) . '</span>';
}

// ─── RBAC: Participant Data Visibility Helpers ───────────────────

/**
 * SQL condition for participants visible to the current user.
 * - Lead/Editor: all active participants
 * - Contributor: only their own non-approved participants
 * - Viewer: none
 */
function visibleParticipantWhere(string $alias = 'p'): string {
    if (isLead() || isEditor()) {
        return activeWhere($alias);
    }
    if (isContributor()) {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "(({$prefix}approval_status IS NULL OR {$prefix}approval_status != 'approved')"
            . " OR (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = {$prefix}id) = 0)"
            . " AND " . activeWhere($alias);
    }
    return "1=0";
}

/**
 * SQL condition for APPROVED participants visible to the current user.
 * - Lead/Editor: returns 1=0 (they see everything in main view)
 * - Contributor: only their own approved participants (archived)
 * - Viewer: none
 */
function visibleArchivedParticipantWhere(string $alias = 'p'): string {
    if (isLead() || isEditor()) {
        return "1=0";
    }
    if (isContributor()) {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "({$prefix}created_by = " . (int) currentUserId()
            . " AND {$prefix}approval_status = 'approved'"
            . " AND " . activeWhere($alias) . ")";
    }
    return "1=0";
}

/**
 * SQL condition for participant interviews visible to the current user.
 * - Lead/Editor: all active interviews
 * - Contributor: only their own non-approved interviews
 * - Viewer: none
 */
function visibleParticipantInterviewWhere(string $alias = 'pi'): string {
    if (isLead() || isEditor()) {
        return activeWhere($alias);
    }
    if (isContributor()) {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "({$prefix}interviewer_id = " . (int) currentUserId()
            . " OR ({$prefix}interview_status IS NULL OR {$prefix}interview_status NOT IN ('approved', 'completed')))"
            . " AND " . activeWhere($alias);
    }
    return "1=0";
}

/**
 * SQL condition for APPROVED participant interviews visible to the current user.
 * - Lead/Editor: returns 1=0
 * - Contributor: only their own approved interviews
 * - Viewer: none
 */
function visibleArchivedParticipantInterviewWhere(string $alias = 'pi'): string {
    if (isLead() || isEditor()) {
        return "1=0";
    }
    if (isContributor()) {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "({$prefix}interviewer_id = " . (int) currentUserId()
            . " AND {$prefix}interview_status = 'approved'"
            . " AND " . activeWhere($alias) . ")";
    }
    return "1=0";
}

/**
 * Check if the current user can view a specific participant.
 * ROLE-ACCESS-01.3: Contributors cannot view approved participants.
 */
function canViewParticipant(array $participant): bool {
    if (isLead() || isEditor()) return true;
    if (isContributor()) {
        // Contributors cannot view approved participants
        if (($participant['approval_status'] ?? '') === 'approved') return false;
        return (int) ($participant['created_by'] ?? 0) === (int) currentUserId()
            && !isDeletedRow($participant);
    }
    return false;
}

/**
 * Check if the current user can view a specific participant interview.
 * ROLE-ACCESS-01.3: Contributors cannot view approved participant interviews.
 */
function canViewParticipantInterview(array $interview): bool {
    if (isLead() || isEditor()) return true;
    if (isContributor()) {
        // Contributors cannot view approved interviews
        if (normalizeParticipantInterviewStatus($interview['interview_status'] ?? null) === 'approved') return false;
        return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId()
            && !isDeletedRow($interview);
    }
    return false;
}

/**
 * Check if the current user can edit a specific participant interview.
 */
function canEditParticipantInterview(array $interview): bool {
    if (isViewer() || isDeletedRow($interview)) return false;
    $status = normalizeParticipantInterviewStatus($interview['interview_status'] ?? null);
    if (isLead()) return true;
    if (isEditor()) return $status !== 'approved';
    if (isContributor()) {
        return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId()
            && in_array($status, ['draft', 'rejected'], true);
    }
    return false;
}

/**
 * Check if the current user can delete a specific participant interview.
 */
function canDeleteParticipantInterview(array $interview): bool {
    if (isViewer() || isDeletedRow($interview)) return false;
    $status = normalizeParticipantInterviewStatus($interview['interview_status'] ?? null);
    if (isLead()) return true;
    if (isEditor()) return $status !== 'approved';
    return false;
}

/**
 * Check if the current user can submit a participant interview for approval.
 */
function canSubmitParticipantInterview(array $interview): bool {
    if (isViewer() || isDeletedRow($interview)) return false;
    $status = normalizeParticipantInterviewStatus($interview['interview_status'] ?? null);
    return (int) ($interview['interviewer_id'] ?? 0) === (int) currentUserId()
        && in_array($status, ['draft', 'rejected'], true);
}

/**
 * Check if the current user can approve participant interviews (Lead only).
 */
function canApproveParticipantInterviews(): bool {
    return isLead();
}

/**
 * Check if the current user can manage the participant recycle bin (Lead only).
 */
function canManageParticipantRecycleBin(): bool {
    return isLead();
}

/**
 * Check if the current user can export participant data.
 */
function canExportParticipantData(): bool {
    return isLead() || isEditor();
}

/**
 * Check if a row is soft-deleted (works with any table using is_deleted/deleted_at).
 */
function isDeletedRow(array $row): bool {
    return !empty($row['is_deleted']) || !empty($row['deleted_at']);
}

// ─── CSV Export Helper ───────────────────────────────────────────

/**
 * Escape a CSV field value with injection protection.
 * Follows the same pattern as export-interviews.php.
 */
function csvEscapeParticipant($value): string {
    $value = (string) $value;
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Prevent CSV injection (Excel formula prefix)
    if (isset($value[0]) && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    // If contains comma, quote, or newline, wrap in quotes and escape double quotes
    if (strpos($value, ',') !== false || strpos($value, '"') !== false
        || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// ─── Form Helper for "Other" text inputs ─────────────────────────

/**
 * Check if a saved comma-separated value contains an "Other:" entry and extract the text.
 * Returns ['hasOther' => bool, 'otherText' => string].
 */
function parseOtherFromList(array $items): array {
    $hasOther = false;
    $otherText = '';
    foreach ($items as $item) {
        if (strpos($item, 'Other: ') === 0) {
            $hasOther = true;
            $otherText = substr($item, 7);
            break;
        }
        if ($item === 'Other') {
            $hasOther = true;
        }
    }
    return ['hasOther' => $hasOther, 'otherText' => $otherText];
}

/**
 * Slugify a string for use as an HTML element ID.
 */
function slugifyParticipant(string $text): string {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $text);
}
