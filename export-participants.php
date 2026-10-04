<?php
/**
 * Krishi Sathi Research System - Participant CSV Export
 * Exports all stakeholder/participant data.
 * Access: http://localhost/phool-delivery/survey/export-participants.php
 * Or with filters: export-participants.php?district=Kavre&search=...
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-participants.php';
requireLogin();

if (!canExportParticipantData()) {
    setFlash('error', 'Only Research Lead and Editor can export stakeholder data.');
    header('Location: ' . BASE_URL . '/participants.php');
    exit;
}

$pdo = getDB();

// ─── Apply same filters as participants.php ────────────────────
$search = trim($_GET['search'] ?? '');
$districtF = trim($_GET['district'] ?? '');
$typeF = trim($_GET['participant_type'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$sql = "SELECT p.*,
               (SELECT COUNT(*) FROM research_participant_interviews WHERE participant_id = p.id) AS interview_count,
               (SELECT MAX(interview_date) FROM research_participant_interviews WHERE participant_id = p.id AND " . activeWhere() . ") AS last_interview_date,
               creator.full_name AS created_by_name
        FROM research_participants p
        LEFT JOIN research_users creator ON p.created_by = creator.id
        WHERE " . visibleParticipantWhere('p');
$params = [];

if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.phone LIKE ? OR p.participant_id LIKE ? OR p.district LIKE ? OR p.participant_type LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like];
}
if ($districtF !== '') {
    $sql .= " AND p.district = ?";
    $params[] = $districtF;
}
if ($typeF !== '') {
    $sql .= " AND p.participant_type = ?";
    $params[] = $typeF;
}
if ($dateFrom !== '') {
    $sql .= " AND p.created_at >= DATE(?)";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= " AND p.created_at <= DATE(?)";
    $params[] = $dateTo . ' 23:59:59';
}

$sql .= " ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$participants = $stmt->fetchAll();

// ─── CSV Column Headers ─────────────────────────────────────────
$headers = [
    'Participant ID',
    'Name',
    'Phone',
    'Participant Type',
    'Organization',
    'District',
    'Municipality',
    'Ward',
    'Tole',
    'Experience Years',
    'Service Area',
    'Main Work Area',
    'Primary Role',
    'Latitude',
    'Longitude',
    'Altitude',
    'GPS Altitude',
    'GPS Accuracy',
    'Location Source',
    'Interview Count',
    'Last Interview Date',
    'Approval Status',
    'Created At',
    'Created By',
];

// ─── Send CSV headers ───────────────────────────────────────────
$filename = 'stakeholder-participants-' . date('Y-m-d') . '.csv';

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
foreach ($participants as $p) {
    fputcsv($output, [
        csvEscapeParticipant($p['participant_id']),
        csvEscapeParticipant($p['name']),
        $p['phone'] ?? '',
        csvEscapeParticipant($p['participant_type']),
        csvEscapeParticipant($p['organization'] ?? ''),
        csvEscapeParticipant($p['district'] ?? ''),
        csvEscapeParticipant($p['municipality'] ?? ''),
        $p['ward'] ?? '',
        csvEscapeParticipant($p['tole'] ?? ''),
        $p['experience_years'] ?? '',
        csvEscapeParticipant($p['service_area'] ?? ''),
        csvEscapeParticipant($p['main_work_area'] ?? ''),
        csvEscapeParticipant($p['primary_role'] ?? ''),
        $p['latitude'] ?? '',
        $p['longitude'] ?? '',
        $p['altitude'] ?? '',
        $p['gps_altitude'] ?? '',
        $p['gps_accuracy'] ?? '',
        $p['location_source'] ?? '',
        (int) $p['interview_count'],
        $p['last_interview_date'] ?? '',
        $p['approval_status'] ?? '',
        $p['created_at'] ?? '',
        csvEscapeParticipant($p['created_by_name'] ?? ''),
    ]);
}

fclose($output);
exit;
