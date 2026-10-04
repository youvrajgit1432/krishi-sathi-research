<?php
/**
 * Krishi Sathi Research System - Farmer CSV Export
 * Phase 2: Farmer Module Enhancement
 *
 * Exports all active farmers with 18 columns.
 * Lead and Editor only.
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!canExport()) {
    setFlash('error', 'You do not have permission to export data.');
    header('Location: ' . BASE_URL . '/farmers.php');
    exit;
}

$pdo = getDB();

// ─── Filters (same as farmers.php) ──────────────────────────────
$search     = trim($_GET['search'] ?? '');
$districtF  = trim($_GET['district'] ?? '');
$genderF    = trim($_GET['gender'] ?? '');
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to'] ?? '';

$sql = "SELECT rf.*, u.full_name AS created_by_name,
               (SELECT COUNT(*) FROM research_interviews WHERE farmer_id = rf.id) AS interview_count
        FROM research_farmers rf
        LEFT JOIN research_users u ON rf.created_by = u.id";
$conditions = [activeWhere('rf')];
$params = [];

if ($search !== '') {
    $conditions[] = "(rf.name LIKE ? OR rf.phone LIKE ? OR rf.farmer_id LIKE ? OR rf.district LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($districtF !== '') {
    $conditions[] = "rf.district = ?";
    $params[] = $districtF;
}
if ($genderF !== '') {
    $conditions[] = "rf.gender = ?";
    $params[] = $genderF;
}
if ($dateFrom !== '') {
    $conditions[] = "rf.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = "rf.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$sql .= " WHERE " . implode(' AND ', $conditions);
$sql .= " ORDER BY rf.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$farmers = $stmt->fetchAll();

// ─── CSV Output ─────────────────────────────────────────────────
$filename = 'farmers-export-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// CSV injection protection
function csvEscape($value): string {
    $value = (string) $value;
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (isset($value[0]) && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    if (strpos($value, ',') !== false || strpos($value, '"') !== false
        || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// Header row
$headers = [
    'Farmer ID', 'Name', 'Phone', 'District', 'Municipality', 'Ward', 'Tole',
    'Age Group', 'Gender',
    'Latitude', 'Longitude', 'Altitude (m)', 'GPS Altitude (m)', 'GPS Accuracy (m)',
    'Location Source', 'Interview Count',
    'Created Date', 'Created By'
];
fputcsv($output, $headers);

// Data rows
foreach ($farmers as $f) {
    $row = [
        csvEscape($f['farmer_id']),
        csvEscape($f['name']),
        csvEscape($f['phone']),
        csvEscape($f['district']),
        csvEscape($f['municipality']),
        csvEscape($f['ward']),
        csvEscape($f['tole']),
        csvEscape($f['age_group']),
        csvEscape($f['gender']),
        csvEscape($f['latitude']),
        csvEscape($f['longitude']),
        csvEscape($f['altitude']),
        csvEscape($f['gps_altitude']),
        csvEscape($f['gps_accuracy']),
        csvEscape($f['location_source']),
        csvEscape($f['interview_count']),
        csvEscape($f['created_at']),
        csvEscape($f['created_by_name']),
    ];
    fputcsv($output, $row);
}

fclose($output);
exit;
