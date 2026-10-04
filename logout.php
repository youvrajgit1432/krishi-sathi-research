<?php
/**
 * Krishi Sathi Research System - Logout
 */
require_once __DIR__ . '/config.php';

// Clear session data
$_SESSION = [];

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

$redirect = BASE_URL . '/index.php';
header("Location: $redirect");
exit;
