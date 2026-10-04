<?php
/**
 * Krishi Sathi Research System - Database Migration
 * Adds new columns for refined interview form fields.
 * Run once AFTER init-db.php if the database already exists.
 * Access: http://localhost/phool-delivery/survey/db-migrate.php
 */
require_once __DIR__ . '/config.php';

if (!IS_LOCALHOST && !isLead()) {
    die('Access denied. Run on localhost only.');
}

$pdo = getDB();
$output = [];

try {
    $columns = [
        'problems_selected'         => "ADD COLUMN problems_selected TEXT AFTER duration_minutes",
        'loss_contributing_causes' => "ADD COLUMN loss_contributing_causes TEXT AFTER problems_selected",
        'loss_amount'               => "ADD COLUMN loss_amount VARCHAR(50) AFTER loss_description",
        'loss_period'               => "ADD COLUMN loss_period VARCHAR(50) AFTER loss_amount",
        'record_frequency'          => "ADD COLUMN record_frequency VARCHAR(20) AFTER record_keeping_method",
        'smartphone_independence'   => "ADD COLUMN smartphone_independence VARCHAR(30) AFTER technology_used",
        'voice_reason_options'      => "ADD COLUMN voice_reason_options TEXT AFTER voice_reason",
        'voice_rejection_reasons'   => "ADD COLUMN voice_rejection_reasons TEXT AFTER voice_reason_options",
        'assumption_reminders'      => "ADD COLUMN assumption_reminders VARCHAR(10) AFTER voice_use_cases",
        'reminder_types'            => "ADD COLUMN reminder_types TEXT AFTER assumption_reminders",
        'app_motivations'           => "ADD COLUMN app_motivations TEXT AFTER reminder_types",
        'recommendation_types'      => "ADD COLUMN recommendation_types TEXT AFTER app_motivations",
    ];

    foreach ($columns as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_interviews $sql");
            $output[] = "✓ Added column: $name";
        } catch (PDOException $e) {
            // Column may already exist (duplicate column error 1060)
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Column already exists: $name";
            } else {
                throw $e;
            }
        }
    }

    // ─── Phase 2: New tables ────────────────────────────────────
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

    $output[] = '';
    // ─── Phase 1.5: New columns ──────────────────────────────────
    $phase15Cols = [
        'interview_status'    => "ADD COLUMN interview_status ENUM('planned','interviewed','follow_up','completed') NOT NULL DEFAULT 'interviewed' AFTER recommendation_types",
        'follow_up_required'  => "ADD COLUMN follow_up_required TINYINT(1) DEFAULT 0 AFTER interview_status",
        'follow_up_date'      => "ADD COLUMN follow_up_date DATE DEFAULT NULL AFTER follow_up_required",
        'follow_up_reason'    => "ADD COLUMN follow_up_reason VARCHAR(255) AFTER follow_up_date",
    ];
    foreach ($phase15Cols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_interviews $sql");
            $output[] = "✓ Added column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Column already exists: $name";
            } else { throw $e; }
        }
    }

    // research_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS research_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value VARCHAR(255),
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES research_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $output[] = '✓ Created research_settings table';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM research_settings WHERE setting_key = ?");
    $stmt->execute(['interview_target']);
    if ($stmt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO research_settings (setting_key, setting_value) VALUES (?, ?)")->execute(['interview_target', '100']);
        $output[] = '✓ Default interview target: 100';
    } else {
        $output[] = 'ℹ Interview target already set';
    }

    $output[] = '';
    $output[] = '✅ Phase 1.5 migration complete!';

    // ════════════════════════════════════════════════════════════
    // Phase 1.6: Location tables + GPS columns + Viewer role
    // ════════════════════════════════════════════════════════════
    $output[] = '';
    $output[] = '── Phase 1.6 ──';

    // ─── Add GPS columns to research_farmers ────────────────────
    $farmerColumns = [
        'tole'            => "ADD COLUMN tole VARCHAR(255) DEFAULT NULL AFTER ward",
        'latitude'        => "ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL AFTER tole",
        'longitude'       => "ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude",
        'altitude'        => "ADD COLUMN altitude DECIMAL(8,2) DEFAULT NULL AFTER longitude",
        'gps_altitude'    => "ADD COLUMN gps_altitude DECIMAL(8,2) DEFAULT NULL AFTER altitude",
        'gps_timestamp'   => "ADD COLUMN gps_timestamp TIMESTAMP NULL DEFAULT NULL AFTER gps_altitude",
    ];
    foreach ($farmerColumns as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_farmers $sql");
            $output[] = "✓ Added farmer column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Column already exists: $name";
            } else { throw $e; }
        }
    }

    // ─── Update research_users role ENUM ────────────────────────
    try {
        $pdo->exec("ALTER TABLE research_users MODIFY COLUMN role ENUM('lead','editor','contributor','viewer') NOT NULL DEFAULT 'contributor'");
        $output[] = '✓ Updated users role: added editor and viewer';
    } catch (PDOException $e) {
        $output[] = 'ℹ Users role: ' . $e->getMessage();
    }

    // ─── Location tables ─────────────────────────────────────────
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

    // ─── Seed data ──────────────────────────────────────────────
    $locSeed = (int) $pdo->query("SELECT COUNT(*) FROM location_provinces")->fetchColumn() === 0;
    if ($locSeed) {
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
            $dId = $pdo->lastInsertId();
            if (!$dId) {
                $sel = $pdo->prepare("SELECT id FROM location_districts WHERE name = ?");
                $sel->execute([$districtName]);
                $dId = $sel->fetchColumn();
            }

            foreach ($municipalities as $munName => $wards) {
                $type = 'Municipality';
                if (strpos($munName, 'Metropolitan') !== false) $type = 'Metropolitan City';
                elseif (strpos($munName, 'Sub-Metropolitan') !== false) $type = 'Sub-Metropolitan City';
                elseif (strpos($munName, 'Rural') !== false) $type = 'Rural Municipality';

                $munStmt->execute([$dId, $munName, $type]);
                $mId = $pdo->lastInsertId();
                if (!$mId) {
                    $selm = $pdo->prepare("SELECT id FROM location_municipalities WHERE district_id = ? AND name = ?");
                    $selm->execute([$dId, $munName]);
                    $mId = $selm->fetchColumn();
                }

                foreach ($wards as $ward) {
                    $wardStmt->execute([$mId, $ward]);
                }
            }
        }
        $output[] = '✓ Seeded location data';
    } else {
        $output[] = 'ℹ Location data already populated, skipping seed';
    }

    // ─── Phase 1.6b: Interview GPS columns ──────────────────────
    $interviewGpsCols = [
        'latitude'      => "ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL AFTER location",
        'longitude'     => "ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude",
        'altitude'      => "ADD COLUMN altitude DECIMAL(8,2) DEFAULT NULL AFTER longitude",
        'gps_altitude'  => "ADD COLUMN gps_altitude DECIMAL(8,2) DEFAULT NULL AFTER altitude",
    ];
    foreach ($interviewGpsCols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_interviews $sql");
            $output[] = "✓ Added interview column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Interview column already exists: $name";
            } else { throw $e; }
        }
    }

    // ════════════════════════════════════════════════════════════
    // Phase 1.7: Photo support + Interview photos table
    // ════════════════════════════════════════════════════════════
    $output[] = '';
    $output[] = '── Phase 1.7 ──';

    // Alter research_photos to support both observations and interviews
    $photoCols = [
        'interview_id'  => "ADD COLUMN interview_id INT DEFAULT NULL AFTER observation_id",
        'consent'       => "ADD COLUMN consent ENUM('research','internal','none') DEFAULT 'research' AFTER caption",
        'uploaded_by'   => "ADD COLUMN uploaded_by INT DEFAULT NULL AFTER consent",
    ];
    foreach ($photoCols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_photos $sql");
            $output[] = "✓ Added photo column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Photo column already exists: $name";
            } else { throw $e; }
        }
    }

    // Make observation_id nullable (so it can be NULL for interview photos)
    try {
        $pdo->exec("ALTER TABLE research_photos MODIFY COLUMN observation_id INT DEFAULT NULL");
        $output[] = '✓ Made observation_id nullable';
    } catch (PDOException $e) {
        $output[] = 'ℹ observation_id nullable: ' . $e->getMessage();
    }

    // Add foreign key for interview_id
    try {
        $pdo->exec("ALTER TABLE research_photos ADD FOREIGN KEY (interview_id) REFERENCES research_interviews(id) ON DELETE CASCADE");
        $output[] = '✓ Added FK: research_photos.interview_id → research_interviews.id';
    } catch (PDOException $e) {
        $output[] = 'ℹ FK interview_id: ' . $e->getMessage();
    }

    // Add FK for uploaded_by
    try {
        $pdo->exec("ALTER TABLE research_photos ADD FOREIGN KEY (uploaded_by) REFERENCES research_users(id) ON DELETE SET NULL");
        $output[] = '✓ Added FK: research_photos.uploaded_by → research_users.id';
    } catch (PDOException $e) {
        $output[] = 'ℹ FK uploaded_by: ' . $e->getMessage();
    }

    $output[] = '';
    $output[] = '✅ Phase 1.7 migration complete!';

    // ════════════════════════════════════════════════════════════
    // Phase 1.9: User management enhancements
    // ════════════════════════════════════════════════════════════
    $output[] = '';
    $output[] = '── Phase 1.9 ──';

    $userCols = [
        'phone'                 => "ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER full_name",
        'email'                 => "ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER phone",
        'status'                => "ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role",
        'force_password_change' => "ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        'last_login'            => "ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER force_password_change",
        'created_by'            => "ADD COLUMN created_by INT DEFAULT NULL AFTER last_login",
        'updated_at'            => "ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($userCols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_users $sql");
            $output[] = "✓ Added user column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Column already exists: $name";
            } else { throw $e; }
        }
    }

    // Add FK for created_by
    try {
        $pdo->exec("ALTER TABLE research_users ADD FOREIGN KEY (created_by) REFERENCES research_users(id) ON DELETE SET NULL");
        $output[] = '✓ Added FK: research_users.created_by → research_users.id';
    } catch (PDOException $e) {
        $output[] = 'ℹ FK created_by: ' . $e->getMessage();
    }

    // Set existing users (non-lead) to force_password_change = 0 so existing accounts aren't disrupted
    try {
        $pdo->exec("UPDATE research_users SET force_password_change = 0 WHERE force_password_change IS NULL");
        $output[] = '✓ Set force_password_change = 0 for existing users';
    } catch (PDOException $e) {
        $output[] = 'ℹ force_password_change update: ' . $e->getMessage();
    }

    $output[] = '';
    $output[] = '✅ Phase 1.9 migration complete!';

    // ════════════════════════════════════════════════════════════
    // Phase 1.10: Farmer recycle bin (soft delete)
    // ════════════════════════════════════════════════════════════
    $output[] = '';
    $output[] = '── Phase 1.10 ──';

    $recycleCols = [
        'deleted_at'  => "ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER gps_timestamp",
        'deleted_by'  => "ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at",
    ];
    foreach ($recycleCols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_farmers $sql");
            $output[] = "✓ Added farmer column: $name";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "ℹ Column already exists: $name";
            } else { throw $e; }
        }
    }

    // Add FK for deleted_by
    try {
        $pdo->exec("ALTER TABLE research_farmers ADD FOREIGN KEY (deleted_by) REFERENCES research_users(id) ON DELETE SET NULL");
        $output[] = '✓ Added FK: research_farmers.deleted_by → research_users.id';
    } catch (PDOException $e) {
        $output[] = 'ℹ FK deleted_by: ' . $e->getMessage();
    }

    $output[] = '';
    $output[] = '✅ Phase 1.10 migration complete!';

    // Phase 2.0: Research workflow governance, approvals, and recycle bin.
    $output[] = '';
    $output[] = '--- Phase 2.0 ---';

    $softDeleteTables = [
        'research_farmers' => 'updated_at',
        'research_interviews' => 'updated_at',
        'research_observations' => 'updated_at',
        'research_users' => 'updated_at',
    ];
    foreach ($softDeleteTables as $table => $afterCol) {
        $cols = [
            'is_deleted' => "ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER {$afterCol}",
            'deleted_at' => "ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER is_deleted",
            'deleted_by' => "ADD COLUMN deleted_by INT DEFAULT NULL AFTER deleted_at",
        ];
        foreach ($cols as $name => $sql) {
            try {
                $pdo->exec("ALTER TABLE {$table} {$sql}");
                $output[] = "Added {$table}.{$name}";
            } catch (PDOException $e) {
                if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                    $output[] = "Already exists: {$table}.{$name}";
                } else { throw $e; }
            }
        }
        try {
            $pdo->exec("UPDATE {$table} SET is_deleted = 1 WHERE deleted_at IS NOT NULL");
        } catch (PDOException $e) {
            $output[] = "Soft-delete backfill note for {$table}: " . $e->getMessage();
        }
    }

    $locationCols = [
        'gps_accuracy' => "ADD COLUMN gps_accuracy DECIMAL(10,2) DEFAULT NULL AFTER gps_altitude",
        'location_source' => "ADD COLUMN location_source VARCHAR(50) DEFAULT NULL AFTER gps_accuracy",
    ];
    foreach (['research_farmers', 'research_interviews'] as $table) {
        foreach ($locationCols as $name => $sql) {
            try {
                $pdo->exec("ALTER TABLE {$table} {$sql}");
                $output[] = "Added {$table}.{$name}";
            } catch (PDOException $e) {
                if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                    $output[] = "Already exists: {$table}.{$name}";
                } else { throw $e; }
            }
        }
    }

    $approvalCols = [
        'submitted_at' => "ADD COLUMN submitted_at TIMESTAMP NULL DEFAULT NULL AFTER follow_up_reason",
        'submitted_by' => "ADD COLUMN submitted_by INT DEFAULT NULL AFTER submitted_at",
        'approved_at' => "ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER submitted_by",
        'approved_by' => "ADD COLUMN approved_by INT DEFAULT NULL AFTER approved_at",
        'rejected_at' => "ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by",
        'rejected_by' => "ADD COLUMN rejected_by INT DEFAULT NULL AFTER rejected_at",
        'rejection_reason' => "ADD COLUMN rejection_reason TEXT AFTER rejected_by",
        'unlocked_at' => "ADD COLUMN unlocked_at TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason",
        'unlocked_by' => "ADD COLUMN unlocked_by INT DEFAULT NULL AFTER unlocked_at",
    ];
    foreach ($approvalCols as $name => $sql) {
        try {
            $pdo->exec("ALTER TABLE research_interviews {$sql}");
            $output[] = "Added interview approval column: {$name}";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                $output[] = "Already exists: research_interviews.{$name}";
            } else { throw $e; }
        }
    }

    try {
        $pdo->exec("ALTER TABLE research_interviews MODIFY COLUMN interview_status ENUM('planned','interviewed','follow_up','completed','draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft'");
        $output[] = 'Expanded interview_status enum for safe lifecycle mapping';
    } catch (PDOException $e) {
        $output[] = 'Expanded status enum note: ' . $e->getMessage();
    }

    try {
        $pdo->exec("UPDATE research_interviews SET interview_status = CASE
            WHEN interview_status = 'planned' THEN 'draft'
            WHEN interview_status = 'interviewed' THEN 'submitted'
            WHEN interview_status = 'follow_up' THEN 'submitted'
            WHEN interview_status = 'completed' THEN 'approved'
            WHEN interview_status IN ('draft','submitted','approved','rejected') THEN interview_status
            ELSE 'draft'
        END");
        $output[] = 'Pre-mapped legacy interview statuses into lifecycle statuses';
    } catch (PDOException $e) {
        $output[] = 'Pre-map status note: ' . $e->getMessage();
    }

    try {
        $pdo->exec("ALTER TABLE research_interviews MODIFY COLUMN interview_status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft'");
        $output[] = 'Updated interview_status lifecycle enum';
    } catch (PDOException $e) {
        $output[] = 'Interview status enum note: ' . $e->getMessage();
    }

    try {
        $pdo->exec("UPDATE research_interviews SET interview_status = CASE
            WHEN interview_status = 'planned' THEN 'draft'
            WHEN interview_status = 'interviewed' THEN 'submitted'
            WHEN interview_status = 'follow_up' THEN 'submitted'
            WHEN interview_status = 'completed' THEN 'approved'
            WHEN interview_status IN ('draft','submitted','approved','rejected') THEN interview_status
            ELSE 'draft'
        END");
        $output[] = 'Mapped legacy interview statuses into lifecycle statuses';
    } catch (PDOException $e) {
        $output[] = 'Legacy status mapping note: ' . $e->getMessage();
    }

    $output[] = 'Phase 2.0 migration complete.';

} catch (PDOException $e) {
    $output[] = '❌ Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Migration - Krishi Sathi Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="card-title mb-4">📊 Krishi Sathi Research System</h3>
                <h5 class="text-muted mb-3">Database Migration - New Interview Fields</h5>
                <pre class="mb-0" style="font-family: inherit;"><?php echo implode("\n", $output); ?></pre>
            </div>
        </div>
    </div>
</body>
</html>
