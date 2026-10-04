<?php

function getLocationData(): array {
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT d.name AS district_name, m.id AS municipality_id,
               m.name AS municipality_name, m.municipality_type,
               w.ward_number
        FROM location_districts d
        JOIN location_municipalities m ON m.district_id = d.id
        JOIN location_wards w ON w.municipality_id = m.id
        ORDER BY d.id, m.id, w.ward_number
    ")->fetchAll();

    $data = [];
    $currentDistrict = null;
    $currentMun = null;
    $wardAccum = [];

    foreach ($rows as $r) {
        $dName = $r['district_name'];
        $mName = $r['municipality_name'];
        $ward = (int) $r['ward_number'];

        if ($currentDistrict !== $dName) {
            $currentDistrict = $dName;
            $currentMun = null;
            $wardAccum = [];
            $data[$dName] = [];
        }

        if ($currentMun !== $mName) {
            if ($currentMun !== null) {
                $data[$currentDistrict][$currentMun] = buildWardArray($wardAccum);
            }
            $currentMun = $mName;
            $wardAccum = [];
        }

        $wardAccum[] = $ward;
    }

    if ($currentMun !== null) {
        $data[$currentDistrict][$currentMun] = buildWardArray($wardAccum);
    }

    return $data;
}

function buildWardArray(array $wards): array {
    return $wards;
}

function getProvinces(): array {
    return getDB()->query("SELECT id, name FROM location_provinces ORDER BY id")->fetchAll();
}

function getDistrictsByProvince(int $provinceId): array {
    $stmt = getDB()->prepare("SELECT id, name FROM location_districts WHERE province_id = ? ORDER BY name");
    $stmt->execute([$provinceId]);
    return $stmt->fetchAll();
}

function getMunicipalitiesByDistrict(int $districtId): array {
    $stmt = getDB()->prepare("SELECT id, name, municipality_type FROM location_municipalities WHERE district_id = ? ORDER BY name");
    $stmt->execute([$districtId]);
    return $stmt->fetchAll();
}

function getWardsByMunicipality(int $municipalityId): array {
    $stmt = getDB()->prepare("SELECT ward_number FROM location_wards WHERE municipality_id = ? ORDER BY ward_number");
    $stmt->execute([$municipalityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getLocationDataJson(): string {
    $json = json_encode(getLocationData(), JSON_UNESCAPED_UNICODE);
    return $json ?: '{}';
}

function getProvincesJson(): string {
    return json_encode(getProvinces(), JSON_UNESCAPED_UNICODE) ?: '[]';
}

function getDistrictsByProvinceJson(int $provinceId): string {
    return json_encode(getDistrictsByProvince($provinceId), JSON_UNESCAPED_UNICODE) ?: '[]';
}

function getProvinceDistrictMap(): array {
    $rows = getDB()->query("
        SELECT p.id AS province_id, p.name AS province_name, d.name AS district_name
        FROM location_provinces p
        JOIN location_districts d ON d.province_id = p.id
        ORDER BY p.id, d.name
    ")->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $pid = (int) $r['province_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = $r['district_name'];
    }
    return $map;
}
