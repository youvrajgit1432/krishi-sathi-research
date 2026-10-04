<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../location-data.php';
requireLogin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_provinces':
        echo getProvincesJson();
        break;

    case 'get_districts':
        $provinceId = (int) ($_GET['province_id'] ?? 0);
        if ($provinceId > 0) {
            echo getDistrictsByProvinceJson($provinceId);
        } else {
            echo json_encode(getDB()->query("SELECT id, name FROM location_districts ORDER BY name")->fetchAll());
        }
        break;

    case 'get_municipalities':
        $districtId = (int) ($_GET['district_id'] ?? 0);
        if ($districtId > 0) {
            echo json_encode(getMunicipalitiesByDistrict($districtId), JSON_UNESCAPED_UNICODE);
        } else {
            // Legacy: filter by district name
            $district = $_GET['district'] ?? '';
            if ($district !== '') {
                $stmt = getDB()->prepare("SELECT id FROM location_districts WHERE name = ?");
                $stmt->execute([$district]);
                $dId = (int) $stmt->fetchColumn();
                if ($dId > 0) {
                    echo json_encode(getMunicipalitiesByDistrict($dId), JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([]);
                }
            } else {
                echo json_encode([]);
            }
        }
        break;

    case 'get_wards':
        $municipalityId = (int) ($_GET['municipality_id'] ?? 0);
        if ($municipalityId > 0) {
            echo json_encode(getWardsByMunicipality($municipalityId));
        } else {
            // Legacy: filter by district name + municipality name
            $district = $_GET['district'] ?? '';
            $municipality = $_GET['municipality'] ?? '';
            if ($district !== '' && $municipality !== '') {
                $stmt = getDB()->prepare("SELECT w.ward_number FROM location_wards w
                    JOIN location_municipalities m ON w.municipality_id = m.id
                    JOIN location_districts d ON m.district_id = d.id
                    WHERE d.name = ? AND m.name = ?
                    ORDER BY w.ward_number");
                $stmt->execute([$district, $municipality]);
                $wards = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode($wards ?: []);
            } else {
                echo json_encode([]);
            }
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action. Use: get_provinces, get_districts, get_municipalities, get_wards']);
        break;
}
