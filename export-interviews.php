<?php
/**
 * Krishi Sathi Research System - CSV Export for Interviews
 * Exports all interview data including farmer details, farm profiles,
 * problem rankings, and all refined Phase 1 fields.
 * Access: http://localhost/phool-delivery/survey/export-interviews.php
 * Or with search filter: export-interviews.php?search=Kathmandu
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!canExport()) {
    setFlash('error', 'Only Research Lead can export data.');
    header('Location: ' . BASE_URL . '/interviews.php');
    exit;
}

$pdo = getDB();

// ─── Build query with optional search filter ────────────────────
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        ri.id                          AS interview_id,
        ri.interview_date              AS interview_date,
        ri.location                    AS location,
        ri.duration_minutes            AS duration_minutes,
        rf.farmer_id                   AS farmer_code,
        rf.name                        AS farmer_name,
        rf.phone                       AS farmer_phone,
        rf.district                    AS farmer_district,
        rf.municipality                AS farmer_municipality,
        rf.ward                        AS farmer_ward,
        rf.age_group                   AS farmer_age_group,
        rf.gender                      AS farmer_gender,
        rf.education_level             AS farmer_education_level,
        rf.primary_occupation          AS farmer_primary_occupation,
        rf.land_ownership_type         AS farmer_land_ownership_type,
        rf.irrigation_access           AS farmer_irrigation_access,
        rf.preferred_language          AS farmer_preferred_language,
        rf.cooperative_membership      AS farmer_cooperative_membership,
        ru.full_name                   AS interviewer_name,
        fp.farm_type                   AS farm_type,
        fp.farm_size                   AS farm_size,
        fp.years_farming               AS years_farming,
        fp.is_commercial               AS is_commercial,
        ri.problems_selected           AS problems_selected,
        rp1.problem_description        AS top_problem_1,
        rp2.problem_description        AS top_problem_2,
        rp3.problem_description        AS top_problem_3,
        ri.loss_contributing_causes    AS loss_contributing_causes,
        ri.loss_cause                  AS loss_main_cause,
        ri.loss_description            AS loss_description,
        ri.loss_amount                 AS loss_amount,
        ri.loss_period                 AS loss_period,
        ri.record_keeping_method       AS record_keeping_method,
        ri.record_frequency            AS record_frequency,
        ri.technology_used             AS technology_used,
        ri.smartphone_independence     AS smartphone_independence,
        ri.interview_round             AS interview_round,
        ri.interview_mode               AS interview_mode,
        ri.interview_language           AS interview_language,
        ri.problem_severity             AS problem_severity,
        ri.loss_amount_npr              AS loss_amount_npr,
        ri.voice_interest              AS voice_interest,
        ri.voice_reason                AS voice_reason,
        ri.voice_reason_options        AS voice_reason_options,
        ri.voice_rejection_reasons     AS voice_rejection_reasons,
        ri.voice_use_cases             AS voice_use_cases,
        ri.assumption_reminders        AS reminder_useful,
        ri.reminder_types              AS reminder_types,
        ri.app_motivations             AS app_motivations,
        ri.recommendation_types        AS recommendation_types,
        ri.interview_status            AS interview_status,
        ri.follow_up_required          AS follow_up_required,
        ri.follow_up_date              AS follow_up_date,
        ri.follow_up_reason            AS follow_up_reason,
        ri.interview_summary           AS interview_summary,
        ri.important_findings          AS important_findings,
        ri.created_at                  AS recorded_at
    FROM research_interviews ri
    JOIN research_farmers rf ON ri.farmer_id = rf.id
    JOIN research_users ru ON ri.interviewer_id = ru.id
    LEFT JOIN research_farm_profiles fp ON fp.farmer_id = rf.id
    LEFT JOIN research_problem_rankings rp1 ON rp1.interview_id = ri.id AND rp1.problem_number = 1
    LEFT JOIN research_problem_rankings rp2 ON rp2.interview_id = ri.id AND rp2.problem_number = 2
    LEFT JOIN research_problem_rankings rp3 ON rp3.interview_id = ri.id AND rp3.problem_number = 3
";

$params = [];
$where = [activeWhere('ri')];
if ($search !== '') {
    $where[] = "(rf.name LIKE ? OR rf.farmer_id LIKE ? OR ri.location LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}

$sql .= " WHERE " . implode(' AND ', $where);

$sql .= " ORDER BY ri.interview_date DESC, ri.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ─── CSV Column Headers ─────────────────────────────────────────
$headers = [
    'Interview ID',
    'Interview Date',
    'Location',
    'Duration (min)',
    'Farmer Code',
    'Farmer Name',
    'Phone',
    'District',
    'Municipality',
    'Ward',
    'Age Group',
    'Gender',
    'Education Level',
    'Primary Occupation',
    'Land Ownership Type',
    'Irrigation Access',
    'Preferred Language',
    'Cooperative Member',
    'Interviewer',
    'Farm Type',
    'Farm Size',
    'Years Farming',
    'Commercial Farmer',
    'Problems Selected',
    'Top Problem #1',
    'Top Problem #2',
    'Top Problem #3',
    'Loss Contributing Causes',
    'Loss Main Cause',
    'Loss Description',
    'Loss Amount',
    'Loss Period',
    'Record Keeping Method',
    'Record Frequency',
    'Technology Used',
    'Smartphone Independence',
    'Interview Round',
    'Interview Mode',
    'Interview Language',
    'Problem Severity (1-5)',
    'Loss Amount NPR',
    'Voice Interest',
    'Voice Reason (free text)',
    'Voice Reason Options',
    'Voice Rejection Reasons',
    'Voice Use Cases',
    'Reminders Useful?',
    'Reminder Types',
    'App Motivation',
    'Recommendation Types',
    'Interview Summary',
    'Interview Status',
    'Follow-Up Required',
    'Follow-Up Date',
    'Follow-Up Reason',
    'Important Findings',
    'Recorded At',
];

// ─── Helper: escape CSV field ───────────────────────────────────
function csvEscape($value): string {
    $value = (string) $value;
    // Decode any HTML entities that might have been double-encoded
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Prevent CSV injection (Excel formula prefix)
    if (isset($value[0]) && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    // If contains comma, quote, or newline, wrap in quotes and escape double quotes
    if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// ─── Helper: format boolean ─────────────────────────────────────
function fmtBool($val): string {
    return $val ? 'Yes' : 'No';
}

// ─── Send CSV headers ───────────────────────────────────────────
$filename = 'krishi-sathi-interviews-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

// Open output stream
$output = fopen('php://output', 'w');

// ─── Write header row ───────────────────────────────────────────
fputcsv($output, $headers);

// ─── Write data rows ────────────────────────────────────────────
foreach ($rows as $row) {
    fputcsv($output, [
        $row['interview_id'],
        $row['interview_date'],
        $row['location'],
        $row['duration_minutes'],
        $row['farmer_code'],
        csvEscape($row['farmer_name']),
        $row['farmer_phone'],
        $row['farmer_district'],
        $row['farmer_municipality'],
        $row['farmer_ward'],
        $row['farmer_age_group'],
        $row['farmer_gender'],
        $row['farmer_education_level'],
        csvEscape($row['farmer_primary_occupation']),
        $row['farmer_land_ownership_type'],
        $row['farmer_irrigation_access'],
        $row['farmer_preferred_language'],
        $row['farmer_cooperative_membership'],
        csvEscape($row['interviewer_name']),
        $row['farm_type'],
        $row['farm_size'],
        $row['years_farming'],
        fmtBool($row['is_commercial']),
        $row['problems_selected'],
        csvEscape($row['top_problem_1']),
        csvEscape($row['top_problem_2']),
        csvEscape($row['top_problem_3']),
        $row['loss_contributing_causes'],
        $row['loss_main_cause'],
        csvEscape($row['loss_description']),
        $row['loss_amount'],
        $row['loss_period'],
        $row['record_keeping_method'],
        $row['record_frequency'],
        $row['technology_used'],
        $row['smartphone_independence'],
        $row['interview_round'],
        $row['interview_mode'],
        $row['interview_language'],
        $row['problem_severity'],
        $row['loss_amount_npr'],
        $row['voice_interest'],
        csvEscape($row['voice_reason']),
        $row['voice_reason_options'],
        $row['voice_rejection_reasons'],
        $row['voice_use_cases'],
        $row['reminder_useful'],
        $row['reminder_types'],
        $row['app_motivations'],
        $row['recommendation_types'],
        csvEscape($row['interview_summary']),
        $row['interview_status'],
        $row['follow_up_required'] ? 'Yes' : 'No',
        $row['follow_up_date'],
        csvEscape($row['follow_up_reason']),
        csvEscape($row['important_findings']),
        $row['recorded_at'],
    ]);
}

fclose($output);
exit;
