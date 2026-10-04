<?php
/**
 * Krishi Sathi Research System - Media Upload DB Migration
 * Creates the research_media table for storing media file metadata.
 * Safe to run multiple times (IF NOT EXISTS).
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!isLead()) {
    http_response_code(403);
    die('Only Research Lead can run migrations.');
}

$pdo = getDB();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `research_media` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `interview_id` INT DEFAULT NULL,
        `observation_id` INT DEFAULT NULL,
        `farmer_id` INT DEFAULT NULL,
        `participant_id` INT DEFAULT NULL,
        `media_type` ENUM('image','audio','video') NOT NULL,
        `file_path` VARCHAR(500) NOT NULL,
        `original_name` VARCHAR(255) DEFAULT NULL,
        `stored_name` VARCHAR(255) DEFAULT NULL,
        `file_size` BIGINT DEFAULT NULL,
        `mime_type` VARCHAR(100) DEFAULT NULL,
        `duration` VARCHAR(20) DEFAULT NULL,
        `caption` TEXT DEFAULT NULL,
        `consent` VARCHAR(20) DEFAULT 'research',
        `thumbnail_path` VARCHAR(500) DEFAULT NULL,
        `uploaded_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_media_interview` (`interview_id`),
        INDEX `idx_media_observation` (`observation_id`),
        INDEX `idx_media_farmer` (`farmer_id`),
        INDEX `idx_media_participant` (`participant_id`),
        INDEX `idx_media_type` (`media_type`),
        INDEX `idx_media_uploaded_by` (`uploaded_by`),
        INDEX `idx_media_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Migration successful: research_media table created/verified.\n";
echo "Table structure:\n";

$cols = $pdo->query("SHOW COLUMNS FROM research_media")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
