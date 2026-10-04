<?php
/**
 * Krishi Sathi Research System - Media Upload API (Legacy Compatibility)
 * MEDIA-UPLOAD-01: Forwards to upload-handler.php with param mapping.
 *
 * This file is kept for backward compatibility with any code that
 * directly calls api/upload-media.php.
 *
 * POST params: same as upload-handler.php
 *   - file: uploaded file
 *   - interview_id / observation_id / farmer_id / participant_id
 *   - caption, consent
 *
 * Returns JSON.
 */

require_once __DIR__ . '/upload-handler.php';
