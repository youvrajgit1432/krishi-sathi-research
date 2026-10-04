<?php
/**
 * Krishi Sathi Research System - CSV Export for Consent Records
 * Phase 2C: Evidence & Ethics Infrastructure
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!canExport()) {
    setFlash('error', 'Only Research Lead and Editor can export consent data.');
    header('Location: ' . BASE_URL . '/consent.php');
    exit;
}

$pdo = getDB();

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT cr.id, cr.consent_date, cr.consent_type, cr.consent_status, cr.consent_method,
               cr.irb_reference, cr.witness_name, cr.witness_relationship, cr.notes,
               cr.created_at, cr.withdrawn_at,
               rf.name AS farmer_name, rf.farmer_id AS farmer_code, rf.district AS farmer_district,
               p.name AS participant_name, p.participant_id AS participant_code, p.district AS participant_district,
               ru.full_name AS researcher_name
        FROM research_consent_records cr
        LEFT JOIN research_farmers rf ON cr.farmer_id = rf.id
        LEFT JOIN research_participants p ON cr.participant_id = p.id
        JOIN research_users ru ON cr.researcher_id = ru.id
        WHERE 1=1";

$params = [];
if ($search !== '') {
    $sql .= " AND (rf.name LIKE ? OR p.name LIKE ? OR rf.farmer_id LIKE ? OR p.participant_id LIKE ? OR cr.irb_reference LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like];
}
if ($typeFilter !== '') {
    $sql .= " AND cr.consent_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND cr.consent_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY cr.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Consent ID', 'Consent Date', 'Type', 'Status', 'Method',
    'Participant Type', 'Participant Name', 'Participant Code', 'District',
    'IRB Reference', 'Witness Name', 'Witness Relationship',
    'Researcher', 'Notes', 'Recorded At', 'Withdrawn At',
];

$filename = 'consent-records-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');
fputcsv($output, $headers);

foreach ($rows as $r) {
    $participantType = $r['farmer_name'] ? 'Farmer' : ($r['participant_name'] ? 'Stakeholder' : '-');
    $participantName = $r['farmer_name'] ?: $r['participant_name'] ?: '-';
    $participantCode = $r['farmer_code'] ?: $r['participant_code'] ?: '-';
    $district = $r['farmer_district'] ?: $r['participant_district'] ?: '-';

    $methodLabels = ['verbal' => 'Verbal', 'written_digital' => 'Written (Digital)', 'written_physical' => 'Written (Physical)', 'implied' => 'Implied'];

    fputcsv($output, [
        $r['id'],
        $r['consent_date'],
        ucfirst($r['consent_type']),
        ucfirst($r['consent_status']),
        $methodLabels[$r['consent_method']] ?? ucfirst($r['consent_method'] ?? ''),
        $participantType,
        $participantName,
        $participantCode,
        $district,
        $r['irb_reference'] ?? '',
        $r['witness_name'] ?? '',
        $r['witness_relationship'] ?? '',
        $r['researcher_name'],
        $r['notes'] ?? '',
        $r['created_at'],
        $r['withdrawn_at'] ?? '',
    ]);
}

fclose($output);
exit;
