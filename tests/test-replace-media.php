<?php
/**
 * Krishi Sathi Research System - Replace Media API Integration Tests
 * DEV-CHANGE-PACKAGE-07: Comprehensive test suite for api/replace-media.php
 *
 * Tests the actual HTTP endpoint via curl against the running Apache server.
 *
 * Covers:
 *   1. GET request → 405 Method Not Allowed
 *   2. media_id=0  → 400 Media ID required
 *   3. Non-existent ID → 404 Media not found
 *   4. Viewer → 403 cannot replace
 *   5. No file → 400 No file uploaded
 *   6. Wrong MIME type → 400 File type does not match
 *   7. Contributor B → 403 (not their media)
 *   8. Contributor A → 200 (their own media)
 *   9. Editor → 200 (any media)
 *   10. Lead  → 200 (any media)
 *
 * Usage: http://localhost/phool-delivery/survey/tests/test-replace-media.php
 *        Requires XAMPP Apache + MySQL running.
 *
 * Security: Only runs on localhost. Creates and destroys test data.
 */

$isLocalhost = (
    strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false ||
    strpos($_SERVER['SERVER_NAME'] ?? 'localhost', 'localhost') !== false
);
if (!$isLocalhost) {
    http_response_code(403);
    die("This test script can only run on localhost.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-media.php';

$pdo = getDB();
$baseUrl = 'http://localhost/phool-delivery/survey';
$apiUrl  = $baseUrl . '/api/replace-media.php';
$loginUrl = $baseUrl . '/index.php';

// ─── TEST FRAMEWORK ──────────────────────────────────────────
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ✅ PASS: $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ❌ FAIL: $name — " . $e->getMessage() . "\n";
    }
}

function assertEq(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) throw new RuntimeException($msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
}
function assertContains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) throw new RuntimeException($msg ?: "Expected '$needle' in:\n$haystack");
}
function assertNotEmpty(mixed $value, string $msg = ''): void {
    if (empty($value)) throw new RuntimeException($msg ?: "Expected non-empty");
}

/**
 * Two-step login: GET the login page (to get session cookie + CSRF token),
 * then POST with credentials. Returns the cookie string.
 */
function loginAs(string $username): string {
    global $loginUrl;

    // Step 1: GET login page to establish session + extract CSRF token
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => '',
    ]);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    // Extract session cookie
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $response, $matches);
    $cookie = implode('; ', $matches[1]);

    // Extract CSRF token from hidden input
    preg_match('/<input[^>]*name="_csrf_token"[^>]*value="([^"]+)"/', $response, $tokenMatch);
    $csrfToken = $tokenMatch[1] ?? '';

    // Step 2: POST login with credentials
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'password' => 'test',
            '_csrf_token' => $csrfToken,
        ]),
        CURLOPT_HEADER => true,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_COOKIEJAR => '',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    // Return the updated cookie
    // The session ID may have been regenerated on successful login
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $response, $matches);
    $updatedCookie = implode('; ', $matches[1]);

    // If session was regenerated, use the new cookie; otherwise use the original
    $finalCookie = $updatedCookie ?: $cookie;
    return $finalCookie;
}

/**
 * Call replace-media API via curl.
 */
function curlCallReplaceApi(int $mediaId, ?string $uploadFilePath = null, ?string $uploadFileName = null, ?string $uploadMime = null, string $method = 'POST', string $cookie = ''): array {
    global $apiUrl;

    $ch = curl_init();
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true];

    if ($cookie !== '') {
        $options[CURLOPT_COOKIE] = $cookie;
    }

    if ($method === 'POST') {
        $options[CURLOPT_URL] = $apiUrl;
        $options[CURLOPT_POST] = true;
        if ($uploadFilePath !== null && file_exists($uploadFilePath)) {
            $options[CURLOPT_POSTFIELDS] = [
                'media_id' => (string) $mediaId,
                'file' => new CURLFile($uploadFilePath, $uploadMime ?? mime_content_type($uploadFilePath), $uploadFileName ?? basename($uploadFilePath)),
            ];
        } else {
            $options[CURLOPT_POSTFIELDS] = http_build_query(['media_id' => (string) $mediaId]);
        }
    } else {
        $options[CURLOPT_URL] = $apiUrl . '?media_id=' . $mediaId;
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($response, $headerSize);

    return [
        'http_code' => $httpCode,
        'body' => json_decode($body, true) ?: ['_raw' => $body],
    ];
}

// ─── HELPERS ─────────────────────────────────────────────────
function createTestImage(string $path, string $mime = 'image/jpeg'): void {
    $img = imagecreatetruecolor(50, 50);
    $bg = imagecolorallocate($img, 200, 200, 200);
    imagefill($img, 0, 0, $bg);
    switch ($mime) {
        case 'image/png': imagepng($img, $path, 9); break;
        case 'image/webp': imagewebp($img, $path, 80); break;
        default: imagejpeg($img, $path, 80); break;
    }
    imagedestroy($img);
}

function ensureTestUser(PDO $pdo, string $username, string $role): int {
    $stmt = $pdo->prepare("SELECT id FROM research_users WHERE username = ?");
    $stmt->execute([$username]);
    $id = (int) $stmt->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO research_users (username, password, full_name, role) VALUES (?, ?, ?, ?)")
            ->execute([$username, password_hash('test', PASSWORD_DEFAULT), 'Test ' . ucfirst($role), $role]);
        $id = (int) $pdo->lastInsertId();
    }
    return $id;
}

// ═══════════════════════════════════════════════════════════════
// SETUP
// ═══════════════════════════════════════════════════════════════

echo "\n📋 Setting up test data...\n";

// Ensure soft-delete columns exist on research_media
try {
    $cols = $pdo->query("SHOW COLUMNS FROM research_media")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['is_deleted', 'deleted_at', 'deleted_by'] as $col) {
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE research_media ADD COLUMN `$col` " . match($col) {
                'is_deleted' => "TINYINT(1) DEFAULT 0 AFTER `uploaded_by`",
                'deleted_at' => "DATETIME DEFAULT NULL AFTER `is_deleted`",
                'deleted_by' => "INT DEFAULT NULL AFTER `deleted_at`",
            });
        }
    }
} catch (PDOException $e) { echo "  ⚠️ " . $e->getMessage() . "\n"; }

// Ensure upload directories exist
$imgDir = MEDIA_IMAGES_DIR . '/' . date('Y') . '/' . date('m');
if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

// Create test users
$leadId     = ensureTestUser($pdo, 'test_rm_lead', 'lead');
$viewerId   = ensureTestUser($pdo, 'test_rm_viewer', 'viewer');
$contribAId = ensureTestUser($pdo, 'test_rm_contrib_a', 'contributor');
$contribBId = ensureTestUser($pdo, 'test_rm_contrib_b', 'contributor');
$editorId   = ensureTestUser($pdo, 'test_rm_editor', 'editor');

// Create a test media record owned by contribA
$y = date('Y'); $m = date('m');
$origFn = 'test_rm_orig_' . bin2hex(random_bytes(4)) . '.jpg';
$relP   = "images/$y/$m/$origFn";
$fullP  = MEDIA_IMAGES_DIR . "/$y/$m/$origFn";
createTestImage($fullP, 'image/jpeg');

$stmt = $pdo->prepare("INSERT INTO research_media
    (media_type, file_path, original_name, stored_name, file_size, mime_type, caption, consent, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([MEDIA_TYPE_IMAGE, $relP, 'orig-test.jpg', $origFn, filesize($fullP), 'image/jpeg', 'Original', 'research', $contribAId]);
$testMediaId = (int) $pdo->lastInsertId();

echo "  ✅ Media ID: $testMediaId (owner: contrib #$contribAId)\n";
echo "  ✅ Users: lead=$leadId viewer=$viewerId contribA=$contribAId contribB=$contribBId editor=$editorId\n";

// Log in all test users (two-step CSRF-aware flow)
echo "  ⏳ Logging in...\n";
$cookieLead     = loginAs('test_rm_lead');
$cookieViewer   = loginAs('test_rm_viewer');
$cookieContribA = loginAs('test_rm_contrib_a');
$cookieContribB = loginAs('test_rm_contrib_b');
$cookieEditor   = loginAs('test_rm_editor');

// Create temp upload files
$tempJpg = sys_get_temp_dir() . '/test_rm_up_' . bin2hex(random_bytes(4)) . '.jpg';
$tempPng = sys_get_temp_dir() . '/test_rm_up_' . bin2hex(random_bytes(4)) . '.png';
$tempTxt = sys_get_temp_dir() . '/test_rm_up_' . bin2hex(random_bytes(4)) . '.txt';
createTestImage($tempJpg, 'image/jpeg');
createTestImage($tempPng, 'image/png');
file_put_contents($tempTxt, 'not-an-image');

echo "\n🧪 Running tests...\n\n";

// ═══════════════════════════════════════════════════════════════
// TEST 1: GET request → 405
// ═══════════════════════════════════════════════════════════════
test('GET request returns 405 Method Not Allowed', function() use ($testMediaId, $cookieLead) {
    $res = curlCallReplaceApi($testMediaId, null, null, null, 'GET', $cookieLead);
    assertEq(405, $res['http_code'], 'Expected HTTP 405');
    assertContains('Method not allowed', $res['body']['error'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 2: media_id = 0 → 400
// ═══════════════════════════════════════════════════════════════
test('Missing media_id returns 400', function() use ($cookieLead) {
    $res = curlCallReplaceApi(0, null, null, null, 'POST', $cookieLead);
    assertEq(400, $res['http_code'], 'Expected HTTP 400');
    assertContains('Media ID required', $res['body']['error'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 3: Non-existent ID → 404
// ═══════════════════════════════════════════════════════════════
test('Non-existent media_id returns 404', function() use ($cookieLead) {
    $res = curlCallReplaceApi(999999, null, null, null, 'POST', $cookieLead);
    assertEq(404, $res['http_code'], 'Expected HTTP 404');
    assertContains('Media not found', $res['body']['error'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 4: Viewer → 403
// ═══════════════════════════════════════════════════════════════
test('Viewer cannot replace media (403)', function() use ($testMediaId, $cookieViewer) {
    $res = curlCallReplaceApi($testMediaId, $GLOBALS['tempJpg'], 'test.jpg', 'image/jpeg', 'POST', $cookieViewer);
    assertEq(403, $res['http_code'], 'Expected HTTP 403');
    assertContains('cannot replace', strtolower($res['body']['error'] ?? ''));
});

// ═══════════════════════════════════════════════════════════════
// TEST 5: No file → 400
// ═══════════════════════════════════════════════════════════════
test('No file uploaded returns 400', function() use ($testMediaId, $cookieLead) {
    $res = curlCallReplaceApi($testMediaId, null, null, null, 'POST', $cookieLead);
    assertEq(400, $res['http_code'], 'Expected HTTP 400');
    assertContains('No file uploaded', $res['body']['error'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 6: Wrong MIME type → 400
// ═══════════════════════════════════════════════════════════════
test('Wrong MIME type rejected (400)', function() use ($testMediaId, $cookieLead) {
    $res = curlCallReplaceApi($testMediaId, $GLOBALS['tempTxt'], 'bad.txt', 'text/plain', 'POST', $cookieLead);
    assertEq(400, $res['http_code'], 'Expected HTTP 400');
    assertContains('File type does not match', $res['body']['error'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 7: ContribB → 403 (not their media)
// ═══════════════════════════════════════════════════════════════
test('Contributor cannot replace another user\'s media (403)', function() use ($testMediaId, $cookieContribB) {
    $res = curlCallReplaceApi($testMediaId, $GLOBALS['tempJpg'], 'bad-replace.jpg', 'image/jpeg', 'POST', $cookieContribB);
    assertEq(403, $res['http_code'], 'Expected HTTP 403');
    assertContains('only replace your own', strtolower($res['body']['error'] ?? ''));
});

// ═══════════════════════════════════════════════════════════════
// TEST 8: ContribA → 200 (their own media)
// ═══════════════════════════════════════════════════════════════
test('Contributor CAN replace their own media (200)', function() use ($testMediaId, $cookieContribA) {
    $res = curlCallReplaceApi($testMediaId, $GLOBALS['tempJpg'], 'my-replace.jpg', 'image/jpeg', 'POST', $cookieContribA);
    assertEq(200, $res['http_code'], 'Expected HTTP 200');
    assertEq(true, $res['body']['success'] ?? false);
    assertContains('Media replaced successfully', $res['body']['message'] ?? '');
    assertNotEmpty($res['body']['file_path'] ?? '');
    assertNotEmpty($res['body']['file_size'] ?? '');
});

// ═══════════════════════════════════════════════════════════════
// TEST 9: Editor → 200 (any media)
// ═══════════════════════════════════════════════════════════════
test('Editor can replace any media (200)', function() use ($pdo, $contribAId, $cookieEditor) {
    $y = date('Y'); $m = date('m');
    $fn = 'test_rm_editor_' . bin2hex(random_bytes(4)) . '.jpg';
    $fp = MEDIA_IMAGES_DIR . "/$y/$m/$fn";
    createTestImage($fp, 'image/jpeg');
    $stmt = $pdo->prepare("INSERT INTO research_media (media_type, file_path, original_name, stored_name, file_size, mime_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([MEDIA_TYPE_IMAGE, "images/$y/$m/$fn", 'editor-test.jpg', $fn, filesize($fp), 'image/jpeg', 'Owned by contribA', $contribAId]);
    $newId = (int) $pdo->lastInsertId();

    $res = curlCallReplaceApi($newId, $GLOBALS['tempPng'], 'editor-replace.png', 'image/png', 'POST', $cookieEditor);
    assertEq(200, $res['http_code'], 'Expected HTTP 200');
    assertEq(true, $res['body']['success'] ?? false);
});

// ═══════════════════════════════════════════════════════════════
// TEST 10: Lead → 200 (any media)
// ═══════════════════════════════════════════════════════════════
test('Lead can replace any media (200)', function() use ($pdo, $contribAId, $cookieLead) {
    $y = date('Y'); $m = date('m');
    $fn = 'test_rm_lead_' . bin2hex(random_bytes(4)) . '.jpg';
    $fp = MEDIA_IMAGES_DIR . "/$y/$m/$fn";
    createTestImage($fp, 'image/jpeg');
    $stmt = $pdo->prepare("INSERT INTO research_media (media_type, file_path, original_name, stored_name, file_size, mime_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([MEDIA_TYPE_IMAGE, "images/$y/$m/$fn", 'lead-test.jpg', $fn, filesize($fp), 'image/jpeg', 'Owned by contribA', $contribAId]);
    $newId = (int) $pdo->lastInsertId();

    $res = curlCallReplaceApi($newId, $GLOBALS['tempJpg'], 'lead-replace.jpg', 'image/jpeg', 'POST', $cookieLead);
    assertEq(200, $res['http_code'], 'Expected HTTP 200');
    assertEq(true, $res['body']['success'] ?? false);
});

// ═══════════════════════════════════════════════════════════════
// RESULTS
// ═══════════════════════════════════════════════════════════════
$total = $passed + $failed;
echo "\n" . str_repeat('═', 60) . "\n";
echo "  TOTAL: $total  |  ✅ PASSED: $passed  |  ❌ FAILED: $failed\n";
echo str_repeat('═', 60) . "\n\n";

// ═══════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════
echo "🧹 Cleaning up test data...\n";

$pdo->exec("DELETE FROM research_media WHERE file_path LIKE 'images/" . date('Y') . "/" . date('m') . "/test_rm_%'");
$pdo->exec("DELETE FROM research_users WHERE username LIKE 'test_rm_%'");

foreach ([$tempJpg, $tempPng, $tempTxt] as $f) {
    if (file_exists($f)) @unlink($f);
}
foreach (glob(MEDIA_IMAGES_DIR . '/' . date('Y') . '/' . date('m') . '/test_rm_*') as $f) {
    @unlink($f);
}

echo "  ✅ Done.\n\n";
exit($failed > 0 ? 1 : 0);
