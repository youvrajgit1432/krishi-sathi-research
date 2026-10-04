<?php
/**
 * Krishi Sathi Research System - Schema Synchronization
 * Phase 2.2: Comprehensive schema synchronization script.
 * 
 * Adds ALL missing columns across ALL tables.
 * Safe to run multiple times.
 * Run: http://localhost/phool-delivery/survey/db-sync-schema.php
 */
require_once __DIR__ . '/config.php';

if (!IS_LOCALHOST && !isLead()) {
    die('Access denied. Run on localhost or as Research Lead.');
}

$pdo = getDB();
$output = [];

function showColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition, array &$output): void {
    try {
        if (showColumnExists($pdo, $table, $column)) {
            $output[] = "Already exists: {$table}.{$column}";
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $output[] = "ADDED: {$table}.{$column}";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $output[] = "Already exists: {$table}.{$column}";
        } else {
            $output[] = "ERROR: {$table}.{$column} - " . $e->getMessage();
        }
    }
}

function addConstraintIfMissing(PDO $pdo, string $table, string $constraintName, string $sql, array &$output): void {
    try {
        $stmt = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '{$table}' 
            AND CONSTRAINT_NAME = '{$constraintName}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        if ($stmt->fetch()) {
            $output[] = "Already exists: FK {$constraintName}";
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY {$sql}");
        $output[] = "ADDED: FK {$constraintName}";
    } catch (PDOException $e) {
        $output[] = "ERROR: FK {$constraintName} - " . $e->getMessage();
    }
}

$output[] = '=== PHASE 2.2 SCHEMA SYNCHRONIZATION ===';
$output[] = '';

try {
    // 1. research_farmers
    $output[] = '--- research_farmers ---';
    addColumnIfMissing($pdo, 'research_farmers', 'gps_accuracy', 'DECIMAL(10,2) DEFAULT NULL AFTER gps_altitude', $output);
    addColumnIfMissing($pdo, 'research_farmers', 'location_source', 'VARCHAR(50) DEFAULT NULL AFTER gps_accuracy', $output);
    addConstraintIfMissing($pdo, 'research_farmers', 'research_farmers_deleted_by_fk',
        '(deleted_by) REFERENCES research_users(id) ON DELETE SET NULL', $output);

    // 2. research_interviews
    $output[] = '';
    $output[] = '--- research_interviews ---';
    addColumnIfMissing($pdo, 'research_interviews', 'gps_accuracy', 'DECIMAL(10,2) DEFAULT NULL AFTER gps_altitude', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'location_source', 'VARCHAR(50) DEFAULT NULL AFTER gps_accuracy', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'submitted_at', 'TIMESTAMP NULL DEFAULT NULL AFTER follow_up_reason', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'submitted_by', 'INT DEFAULT NULL AFTER submitted_at', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'approved_at', 'TIMESTAMP NULL DEFAULT NULL AFTER submitted_by', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'approved_by', 'INT DEFAULT NULL AFTER approved_at', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'rejected_at', 'TIMESTAMP NULL DEFAULT NULL AFTER approved_by', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'rejected_by', 'INT DEFAULT NULL AFTER rejected_at', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'rejection_reason', 'TEXT AFTER rejected_by', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'unlocked_at', 'TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason', $output);
    addColumnIfMissing($pdo, 'research_interviews', 'unlocked_by', 'INT DEFAULT NULL AFTER unlocked_at', $output);

    // Update interview_status ENUM
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM research_interviews LIKE 'interview_status'");
        $colDef = $stmt->fetch();
        if ($colDef && strpos($colDef['Type'], 'draft') === false) {
            $pdo->exec("ALTER TABLE research_interviews MODIFY COLUMN interview_status 
                ENUM('planned','interviewed','follow_up','completed','draft','submitted','approved','rejected') 
                NOT NULL DEFAULT 'draft'");
            $pdo->exec("UPDATE research_interviews SET interview_status = CASE
                WHEN interview_status = 'planned' THEN 'draft'
                WHEN interview_status = 'interviewed' THEN 'submitted'
                WHEN interview_status = 'follow_up' THEN 'submitted'
                WHEN interview_status = 'completed' THEN 'approved'
                ELSE 'draft'
            END");
            $pdo->exec("ALTER TABLE research_interviews MODIFY COLUMN interview_status 
                ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft'");
            $output[] = 'UPDATED: interview_status ENUM to lifecycle statuses';
        } else {
            $output[] = 'Already exists: interview_status lifecycle ENUM';
        }
    } catch (PDOException $e) {
        $output[] = 'ERROR: interview_status - ' . $e->getMessage();
    }

    // 3. research_users
    $output[] = '';
    $output[] = '--- research_users ---';
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM research_users LIKE 'role'");
        $colDef = $stmt->fetch();
        if ($colDef && strpos($colDef['Type'], 'editor') === false) {
            $pdo->exec("ALTER TABLE research_users MODIFY COLUMN role 
                ENUM('lead','editor','contributor','viewer') NOT NULL DEFAULT 'contributor'");
            $output[] = 'UPDATED: users role ENUM with editor';
        } else {
            $output[] = 'Already exists: editor role in ENUM';
        }
    } catch (PDOException $e) {
        $output[] = 'ERROR: role - ' . $e->getMessage();
    }

    addConstraintIfMissing($pdo, 'research_users', 'research_users_deleted_by_fk',
        '(deleted_by) REFERENCES research_users(id) ON DELETE SET NULL', $output);
    addConstraintIfMissing($pdo, 'research_users', 'research_users_created_by_fk',
        '(created_by) REFERENCES research_users(id) ON DELETE SET NULL', $output);

    // 4. research_observations
    $output[] = '';
    $output[] = '--- research_observations ---';
    addConstraintIfMissing($pdo, 'research_observations', 'research_observations_deleted_by_fk',
        '(deleted_by) REFERENCES research_users(id) ON DELETE SET NULL', $output);

    // 5. research_problem_rankings
    $output[] = '';
    $output[] = '--- research_problem_rankings ---';
    try {
        $pdo->query("SELECT 1 FROM research_problem_rankings LIMIT 1");
        $output[] = 'Table exists: research_problem_rankings';
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS research_problem_rankings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            interview_id INT NOT NULL,
            problem_number INT NOT NULL,
            problem_description TEXT,
            category VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (interview_id) REFERENCES research_interviews(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'CREATED: research_problem_rankings table';
    }

    // 6. Data consistency
    $output[] = '';
    $output[] = '--- Data Consistency ---';
    $tables = ['research_farmers', 'research_interviews', 'research_observations', 'research_users'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("UPDATE {$table} SET is_deleted = 1 WHERE deleted_at IS NOT NULL AND is_deleted = 0");
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE is_deleted = 1");
            $count = $stmt->fetchColumn();
            $output[] = "Synced: {$table} - {$count} soft-deleted record(s)";
        } catch (PDOException $e) {
            $output[] = "ERROR: {$table} - " . $e->getMessage();
        }
    }

    $output[] = '';
    $output[] = '=======================================';
    $output[] = 'PHASE 2.2 SCHEMA SYNCHRONIZATION COMPLETE';

} catch (PDOException $e) {
    $output[] = 'FATAL ERROR: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schema Sync - Krishi Sathi Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h4 class="card-title mb-1">Krishi Sathi Research - Schema Sync</h4>
                <p class="text-muted small mb-3">Phase 2.2 - Schema synchronization report</p>
                <div class="bg-dark text-light p-3 rounded" style="font-family: monospace; font-size: 0.85rem; max-height: 600px; overflow-y: auto;">
                    <?php echo implode("<br>", array_map('htmlspecialchars', $output)); ?>
                </div>
                <div class="mt-3">
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                    <a href="<?php echo BASE_URL; ?>/interviews.php" class="btn btn-outline-secondary">Interviews</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
