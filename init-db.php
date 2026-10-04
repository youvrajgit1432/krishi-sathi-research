<?php
/**
 * Krishi Sathi Research System - Database Initialization
 * Run this once to create all Phase 1 tables and default admin user.
 * Access: http://localhost/phool-delivery/survey/init-db.php
 */

require_once __DIR__ . '/config.php';

// Only allow localhost or authenticated lead researchers
if (!IS_LOCALHOST && !isLead()) {
    die('Access denied. Run this script on localhost only.');
}

$output = [];

try {
    // ─── Create database if it doesn't exist ─────────────────────
    $tempPdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $tempPdo = null;
    $output[] = '✓ Database \'' . DB_NAME . '\' ready';

    // Now connect to the research database
    $pdo = getDB();

    // ─── research_users ──────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        role ENUM('lead', 'editor', 'contributor', 'viewer') NOT NULL DEFAULT 'contributor',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_users table';

    // ─── research_farmers ────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_farmers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        farmer_id VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        district VARCHAR(100),
        municipality VARCHAR(100),
        ward VARCHAR(20),
        age_group VARCHAR(20),
        gender VARCHAR(10),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES research_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Add GPS/location columns (with try/catch for idempotency)
    $farmerCols = [
        'tole'          => "ADD COLUMN tole VARCHAR(255) DEFAULT NULL AFTER ward",
        'latitude'      => "ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL AFTER tole",
        'longitude'     => "ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude",
        'altitude'      => "ADD COLUMN altitude DECIMAL(8,2) DEFAULT NULL AFTER longitude",
        'gps_altitude'  => "ADD COLUMN gps_altitude DECIMAL(8,2) DEFAULT NULL AFTER altitude",
        'gps_timestamp' => "ADD COLUMN gps_timestamp TIMESTAMP NULL DEFAULT NULL AFTER gps_altitude",
    ];
    foreach ($farmerCols as $colName => $colSql) {
        try {
            $pdo->exec("ALTER TABLE research_farmers $colSql");
            $output[] = "✓ Added farmer column: $colName";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                // Column already exists — safe to ignore
            } else { throw $e; }
        }
    }
    $output[] = '✓ Created research_farmers table (Phase 1.6: GPS + Tole columns)';

    // ─── districts, municipalities, wards (Phase 1.6) ──────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_districts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created location_districts table';

    $pdo->exec("CREATE TABLE IF NOT EXISTS location_municipalities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        district_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        municipality_type ENUM('Municipality','Rural Municipality','Sub-Metropolitan City','Metropolitan City') NOT NULL DEFAULT 'Municipality',
        FOREIGN KEY (district_id) REFERENCES location_districts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created location_municipalities table';

    $pdo->exec("CREATE TABLE IF NOT EXISTS location_wards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        municipality_id INT NOT NULL,
        ward_number INT NOT NULL,
        FOREIGN KEY (municipality_id) REFERENCES location_municipalities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created location_wards table';

    // ─── Seed location data ─────────────────────────────────────
    $locSeedNeeded = (int) $pdo->query("SELECT COUNT(*) FROM location_provinces")->fetchColumn() === 0;
    if ($locSeedNeeded) {
        require_once __DIR__ . '/location-data.php';
        $locData = getLocationData();
        $provinceStmt = $pdo->prepare("INSERT IGNORE INTO location_provinces (name) VALUES (?)");
        $districtStmt = $pdo->prepare("INSERT IGNORE INTO location_districts (province_id, name) VALUES (?, ?)");
        $munStmt = $pdo->prepare("INSERT IGNORE INTO location_municipalities (district_id, name, municipality_type) VALUES (?, ?, ?)");
        $wardStmt = $pdo->prepare("INSERT IGNORE INTO location_wards (municipality_id, ward_number) VALUES (?, ?)");

        $provinceStmt->execute(['Bagmati Province']);
        $bagmatiId = $pdo->lastInsertId();

        foreach ($locData as $districtName => $municipalities) {
            $districtStmt->execute([$bagmatiId, $districtName]);
            $districtId = $pdo->lastInsertId();
            if (!$districtId) {
                $check = $pdo->prepare("SELECT id FROM location_districts WHERE name = ?");
                $check->execute([$districtName]);
                $districtId = $check->fetchColumn();
            }

            foreach ($municipalities as $munName => $wards) {
                $type = 'Municipality';
                if (strpos($munName, 'Metropolitan') !== false) $type = 'Metropolitan City';
                elseif (strpos($munName, 'Sub-Metropolitan') !== false) $type = 'Sub-Metropolitan City';
                elseif (strpos($munName, 'Rural') !== false) $type = 'Rural Municipality';

                $munStmt->execute([$districtId, $munName, $type]);
                $munId = $pdo->lastInsertId();
                if (!$munId) {
                    $check = $pdo->prepare("SELECT id FROM location_municipalities WHERE district_id = ? AND name = ?");
                    $check->execute([$districtId, $munName]);
                    $munId = $check->fetchColumn();
                }

                foreach ($wards as $ward) {
                    $wardStmt->execute([$munId, $ward]);
                }
            }
        }
        $output[] = '✓ Seeded location data for ' . count($locData) . ' districts';
    } else {
        $output[] = 'ℹ Location data already populated, skipping seed';
    }

    // ─── Update existing users table role ENUM ───────────────────
    try {
        $pdo->exec("ALTER TABLE research_users MODIFY COLUMN role ENUM('lead','editor','contributor','viewer') NOT NULL DEFAULT 'contributor'");
        $output[] = '✓ Updated research_users role to include editor and viewer';
    } catch (PDOException $e) {
        $output[] = 'ℹ Users role: ' . $e->getMessage();
    }

    // ─── research_farm_profiles ──────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_farm_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        farmer_id INT NOT NULL,
        farm_type VARCHAR(100),
        farm_size VARCHAR(50),
        years_farming VARCHAR(20),
        is_commercial TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (farmer_id) REFERENCES research_farmers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_farm_profiles table';

    // ─── research_interviews ─────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_interviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        farmer_id INT NOT NULL,
        interview_date DATE NOT NULL,
        interviewer_id INT NOT NULL,
        location VARCHAR(255),
        duration_minutes INT,
        problems_selected TEXT,
        loss_contributing_causes TEXT,
        loss_cause VARCHAR(50),
        loss_description TEXT,
        loss_amount VARCHAR(50),
        loss_period VARCHAR(50),
        record_keeping_method VARCHAR(100),
        record_frequency VARCHAR(20),
        technology_used VARCHAR(255),
        smartphone_independence VARCHAR(30),
        voice_interest ENUM('yes', 'maybe', 'no'),
        voice_reason TEXT,
        voice_reason_options TEXT,
        voice_rejection_reasons TEXT,
        voice_use_cases VARCHAR(255),
        assumption_reminders VARCHAR(10),
        reminder_types TEXT,
        app_motivations TEXT,
        recommendation_types TEXT,
        interview_status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
        follow_up_required TINYINT(1) DEFAULT 0,
        follow_up_date DATE DEFAULT NULL,
        follow_up_reason VARCHAR(255),
        interview_summary TEXT,
        important_findings TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (farmer_id) REFERENCES research_farmers(id) ON DELETE CASCADE,
        FOREIGN KEY (interviewer_id) REFERENCES research_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_interviews table';

    // ─── research_problem_rankings ───────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_problem_rankings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        interview_id INT NOT NULL,
        problem_number INT NOT NULL,
        problem_description TEXT,
        category VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (interview_id) REFERENCES research_interviews(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_problem_rankings table';

    // ─── research_observations (Phase 2) ─────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_observations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        farmer_id INT NOT NULL,
        interview_id INT DEFAULT NULL,
        observer_id INT NOT NULL,
        observation_date DATE NOT NULL,
        observed_smartphone_usage TEXT,
        observed_record_books TEXT,
        observed_technology TEXT,
        farm_condition VARCHAR(30),
        farm_condition_details TEXT,
        researcher_notes TEXT,
        general_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (farmer_id) REFERENCES research_farmers(id) ON DELETE CASCADE,
        FOREIGN KEY (interview_id) REFERENCES research_interviews(id) ON DELETE SET NULL,
        FOREIGN KEY (observer_id) REFERENCES research_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_observations table (Phase 2)';

    // ─── research_photos (Phase 2) ───────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        observation_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255),
        caption TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (observation_id) REFERENCES research_observations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_photos table (Phase 2)';

    // ─── Default Admin User ──────────────────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM research_users WHERE username = ?");
    $stmt->execute(['admin']);
    $adminExists = $stmt->fetchColumn() > 0;

    if (!$adminExists) {
        $hashed = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO research_users (username, password, full_name, role) VALUES (?, ?, ?, ?)")
            ->execute(['admin', $hashed, 'Research Lead', 'lead']);
        $output[] = '✓ Created default admin user (username: admin, password: admin123)';
    } else {
        $output[] = 'ℹ Admin user already exists';
    }

    $output[] = '';
    // ─── research_settings (Phase 1.5) ────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value VARCHAR(255),
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES research_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_settings table';

    // Insert default target if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM research_settings WHERE setting_key = ?");
    $stmt->execute(['interview_target']);
    if ($stmt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO research_settings (setting_key, setting_value) VALUES (?, ?)")->execute(['interview_target', '100']);
    }
    $output[] = '✓ Default interview target: 100';

    $output[] = '';
    $output[] = '✅ Phase 1 database setup complete!';
    $phase2SoftTables = [
        'research_farmers' => 'updated_at',
        'research_interviews' => 'updated_at',
        'research_observations' => 'updated_at',
        'research_users' => 'updated_at',
    ];
    foreach ($phase2SoftTables as $table => $afterCol) {
        foreach ([
            "ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER {$afterCol}",
            "ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER is_deleted",
            "ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at",
        ] as $sql) {
            try { $pdo->exec("ALTER TABLE {$table} {$sql}"); }
            catch (PDOException $e) { if ($e->getCode() != '42S21' && strpos($e->getMessage(), 'Duplicate column') === false) throw $e; }
        }
    }
    foreach (['research_farmers', 'research_interviews'] as $table) {
        foreach ([
            "ADD COLUMN gps_accuracy DECIMAL(10,2) DEFAULT NULL AFTER gps_altitude",
            "ADD COLUMN location_source VARCHAR(50) DEFAULT NULL AFTER gps_accuracy",
        ] as $sql) {
            try { $pdo->exec("ALTER TABLE {$table} {$sql}"); }
            catch (PDOException $e) { if ($e->getCode() != '42S21' && strpos($e->getMessage(), 'Duplicate column') === false) throw $e; }
        }
    }
    foreach ([
        "ADD COLUMN submitted_at TIMESTAMP NULL DEFAULT NULL AFTER follow_up_reason",
        "ADD COLUMN submitted_by INT DEFAULT NULL AFTER submitted_at",
        "ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER submitted_by",
        "ADD COLUMN approved_by INT DEFAULT NULL AFTER approved_at",
        "ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by",
        "ADD COLUMN rejected_by INT DEFAULT NULL AFTER rejected_at",
        "ADD COLUMN rejection_reason TEXT AFTER rejected_by",
        "ADD COLUMN unlocked_at TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason",
        "ADD COLUMN unlocked_by INT DEFAULT NULL AFTER unlocked_at",
    ] as $sql) {
        try { $pdo->exec("ALTER TABLE research_interviews {$sql}"); }
        catch (PDOException $e) { if ($e->getCode() != '42S21' && strpos($e->getMessage(), 'Duplicate column') === false) throw $e; }
    }
    $output[] = 'Phase 2.0 governance columns ready';

    $output[] = '<a href="' . BASE_URL . '/index.php" class="btn btn-primary mt-3">Go to Login</a>';

} catch (PDOException $e) {
    $output[] = '❌ Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Setup - Krishi Sathi Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="card-title mb-4">📊 Krishi Sathi Research System</h3>
                <h5 class="text-muted mb-3">Database Initialization</h5>
                <pre class="mb-0" style="font-family: inherit;"><?php echo implode("\n", $output); ?></pre>
            </div>
        </div>
    </div>
</body>
</html>
