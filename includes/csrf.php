<?php
// includes/csrf.php - CSRF protection utilities

/**
 * Generate CSRF token and store in session
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

/**
 * Check a CSRF token without terminating the request.
 * Useful for JSON endpoints that need to return JSON errors.
 * @param string $token
 * @param bool $regenerate
 * @return bool
 */
function checkCSRF($token, $regenerate = true) {
    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        return false;
    }

    if ($regenerate) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return true;
}

/**
 * Validate CSRF token from form submission
 * @param string $token
 * @return void
 */
function validateCSRF($token) {
    if (!checkCSRF($token)) {
        http_response_code(403);
        die("CSRF token validation failed.");
    }

    return true;
}
