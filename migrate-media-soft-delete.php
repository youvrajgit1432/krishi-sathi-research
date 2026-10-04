<?php
/**
 * Krishi Sathi Research System - Media Soft Delete DB Migration
 * DEV-CHANGE-PACKAGE-07: Adds soft delete columns to research_media and research_photos.
 * Safe to run multiple times (IF NOT EXISTS / IF COLUMN NOT EXISTS).
 */
require_once __DIR__ . '/config.php';
requireLogin();

if (!isLead()) {
    http_response_code(403);
    die('Only Research Lead can run migrations.');
}

$pdo = getDB();

// ─── Add soft delete columns to research_media ─────────────────
$mediaCols = $pdo->query("SHOW COLUMNS FROM research_media")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_deleted', $mediaCols)) {
    $pdo->exec("ALTER TABLE research_media ADD COLUMN `is_deleted` TINYINT(1) DEFAULT 0 AFTER `uploaded_by`");
    echo "Added is_deleted to research_media.\n";
}
if (!in_array('deleted_at', $mediaCols)) {
    $pdo->exec("ALTER TABLE research_media ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `is_deleted`");
    echo "Added deleted_at to research_media.\n";
}
if (!in_array('deleted_by', $mediaCols)) {
    $pdo->exec("ALTER TABLE research_media ADD COLUMN `deleted_by` INT DEFAULT NULL AFTER `deleted_at`");
    echo "Added deleted_by to research_media.\n";
}

// ─── Add soft delete columns to research_photos ────────────────
$photoCols = $pdo->query("SHOW COLUMNS FROM research_photos")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_deleted', $photoCols)) {
    $pdo->exec("ALTER TABLE research_photos ADD COLUMN `is_deleted` TINYINT(1) DEFAULT 0 AFTER `uploaded_by`");
    echo "Added is_deleted to research_photos.\n";
}
if (!in_array('deleted_at', $photoCols)) {
    $pdo->exec("ALTER TABLE research_photos ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `is_deleted`");
    echo "Added deleted_at to research_photos.\n";
}
if (!in_array('deleted_by', $photoCols)) {
    $pdo->exec("ALTER TABLE research_photos ADD COLUMN `deleted_by` INT DEFAULT NULL AFTER `deleted_at`");
    echo "Added deleted_by to research_photos.\n";
}

echo "\nMigration complete. Soft delete columns added to research_media and research_photos.\n";
