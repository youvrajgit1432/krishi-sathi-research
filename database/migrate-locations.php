<?php
$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    $pdo = new PDO("mysql:host=localhost;dbname=krishi_sathi_research_demo;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} else {
    require_once __DIR__ . '/../config.php';
    if (!IS_LOCALHOST && !isLead()) {
        die('Access denied. Run on localhost only.');
    }
    $pdo = getDB();
}

$output = [];

try {
    // ─── Step 1: Create location_provinces table ────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_provinces (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created location_provinces table';

    // ─── Step 2: Clear existing data with FK checks off ────────
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE location_wards");
    $pdo->exec("TRUNCATE TABLE location_municipalities");
    $pdo->exec("TRUNCATE TABLE location_districts");
    $pdo->exec("TRUNCATE TABLE location_provinces");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    $output[] = '✓ Cleared existing location data';

    // ─── Step 3: Add province_id to location_districts ──────────
    $hasProvinceId = $pdo->query("SHOW COLUMNS FROM location_districts LIKE 'province_id'")->fetch();
    if (!$hasProvinceId) {
        $pdo->exec("ALTER TABLE location_districts ADD COLUMN province_id INT NOT NULL AFTER id");
        $output[] = '✓ Added province_id to location_districts';
    } else {
        $output[] = 'ℹ province_id already exists in location_districts';
    }

    // ─── Step 4: Import from JSON ──────────────────────────────
    $jsonFile = __DIR__ . '/locations_master.json';
    if (!file_exists($jsonFile)) {
        throw new Exception("locations_master.json not found at: $jsonFile");
    }

    $jsonRaw = file_get_contents($jsonFile);
    $jsonData = json_decode($jsonRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON parse error: ' . json_last_error_msg());
    }

    $pdo->beginTransaction();

    $provStmt = $pdo->prepare("INSERT INTO location_provinces (name) VALUES (?)");
    $distStmt = $pdo->prepare("INSERT INTO location_districts (province_id, name) VALUES (?, ?)");
    $munStmt = $pdo->prepare("INSERT INTO location_municipalities (district_id, name, municipality_type) VALUES (?, ?, ?)");
    $wardStmt = $pdo->prepare("INSERT INTO location_wards (municipality_id, ward_number) VALUES (?, ?)");

    $provinceCount = 0;
    $districtCount = 0;
    $municipalityCount = 0;
    $wardCount = 0;

    foreach ($jsonData as $province) {
        $provStmt->execute([$province['province_name']]);
        $provinceId = $pdo->lastInsertId();
        $provinceCount++;

        foreach ($province['districts'] as $district) {
            $distStmt->execute([$provinceId, $district['district_name']]);
            $districtId = $pdo->lastInsertId();
            $districtCount++;

            foreach ($district['local_levels'] as $ll) {
                $munStmt->execute([$districtId, $ll['local_level_name'], $ll['local_level_type']]);
                $municipalityId = $pdo->lastInsertId();
                $municipalityCount++;

                foreach ($ll['wards'] as $ward) {
                    $wardStmt->execute([$municipalityId, $ward]);
                    $wardCount++;
                }
            }
        }
    }

    $pdo->commit();

    $output[] = "✓ Imported: $provinceCount provinces, $districtCount districts, $municipalityCount municipalities, $wardCount wards";

    // ─── Add FK for province_id (after data exists) ─────────────
    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='krishi_sathi_research_demo' AND TABLE_NAME='location_districts' AND COLUMN_NAME='province_id' AND REFERENCED_TABLE_NAME='location_provinces'")->fetchAll();
    if (count($fks) === 0) {
        $pdo->exec("ALTER TABLE location_districts ADD FOREIGN KEY (province_id) REFERENCES location_provinces(id)");
        $output[] = '✓ Added FK: location_districts.province_id → location_provinces.id';
    } else {
        $output[] = 'ℹ FK for province_id already exists';
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $output[] = '❌ Error: ' . $e->getMessage();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $output[] = '❌ Error: ' . $e->getMessage();
}

// verify
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM location_provinces")->fetchColumn();
    $output[] = "Provinces: $cnt";
    $cnt = $pdo->query("SELECT COUNT(*) FROM location_districts")->fetchColumn();
    $output[] = "Districts: $cnt";
    $cnt = $pdo->query("SELECT COUNT(*) FROM location_municipalities")->fetchColumn();
    $output[] = "Municipalities: $cnt";
    $cnt = $pdo->query("SELECT COUNT(*) FROM location_wards")->fetchColumn();
    $output[] = "Wards: $cnt";
} catch (Exception $e) {
    $output[] = 'Verify error: ' . $e->getMessage();
}

if ($isCLI) {
    echo implode("\n", $output) . "\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Location Migration - Krishi Sathi Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="card-title mb-4">Location Migration</h3>
                <pre class="mb-0" style="font-family: inherit;"><?php echo implode("\n", $output); ?></pre>
            </div>
        </div>
    </div>
</body>
</html>
