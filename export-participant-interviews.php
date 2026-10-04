<?php
/**
 * Krishi Sathi Research System - CSV Export for Stakeholder Interviews
 * Exports all participant interview data including participant details.
 * Access: http://localhost/phool-delivery/survey/export-participant-interviews.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

if (!canExportParticipantData()) {
    setFlash('error', 'Only Research Lead and Editor can export stakeholder interview data.');
    header('Location: ' . BASE_URL . '/participant-interviews.php');
    exit;
}

$pdo = getDB();

// Apply same filters as participant-interviews list page
$search = trim($_GET['search'] ?? '');
$participantId = (int) ($_GET['participant_id'] ?? 0);

$sql = "
    SELECT
        pi.id                          AS interview_id,
        pi.interview_date              AS interview_date,
        pi.duration_minutes            AS duration_minutes,
        pi.location                    AS location,
        p.participant_id               AS participant_code,
        p.name                         AS participant_name,
        p.phone                        AS participant_phone,
        p.participant_type             AS participant_type,
        p.organization                 AS participant_organization,
        p.district                     AS participant_district,
        p.municipality                 AS participant_municipality,
        p.main_work_area               AS main_work_area,
        p.primary_role                 AS primary_role,
        pi.interview_summary           AS researcher_notes,
        pi.major_findings              AS important_findings,
        pi.interview_status            AS interview_status,
        ru.full_name                   AS interviewer_name,
        pi.created_at                  AS recorded_at
    FROM research_participant_interviews pi
    JOIN research_participants p ON pi.participant_id = p.id
    JOIN research_users ru ON pi.interviewer_id = ru.id
    WHERE " . activeWhere('pi') . " AND " . visibleParticipantWhere('p');

$params = [];

if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.participant_id LIKE ? OR p.organization LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}
if ($participantId > 0) {
    $sql .= " AND pi.participant_id = ?";
    $params[] = $participantId;
}

$sql .= " ORDER BY pi.interview_date DESC, pi.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ─── CSV Column Headers ─────────────────────────────────────────
$headers = [
    'Interview ID',
    'Interview Date',
    'Duration (min)',
    'Location',
    'Participant Code',
    'Participant Name',
    'Phone',
    'Participant Type',
    'Organization',
    'District',
    'Municipality',
    'Main Work Area',
    'Primary Role',
    'Interview Summary',
    'Major Findings',
    'Interview Status',
    'Interviewer',
    'Recorded At',
];

// ─── Send CSV headers ───────────────────────────────────────────
$filename = 'stakeholder-interviews-' . date('Y-m-d') . '.csv';

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
        $row['duration_minutes'],
        csvEscapeParticipant($row['location'] ?? ''),
        $row['participant_code'],
        csvEscapeParticipant($row['participant_name']),
        $row['participant_phone'] ?? '',
        csvEscapeParticipant($row['participant_type'] ?? ''),
        csvEscapeParticipant($row['participant_organization'] ?? ''),
        csvEscapeParticipant($row['participant_district'] ?? ''),
        csvEscapeParticipant($row['participant_municipality'] ?? ''),
        csvEscapeParticipant($row['main_work_area'] ?? ''),
        csvEscapeParticipant($row['primary_role'] ?? ''),
        csvEscapeParticipant($row['researcher_notes'] ?? ''),
        csvEscapeParticipant($row['important_findings'] ?? ''),
        $row['interview_status'] ?? '',
        csvEscapeParticipant($row['interviewer_name']),
        $row['recorded_at'] ?? '',
    ]);
}

fclose($output);
exit;
