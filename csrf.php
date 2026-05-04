<?php
/**
 * CSRF Protection Helper Functions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a CSRF token and store in session
 */
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get the current CSRF token
 */
function getCSRFToken(): string {
    return $_SESSION['csrf_token'] ?? generateCSRFToken();
}

/**
 * Validate CSRF token from POST request
 */
function validateCSRFToken(string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token field HTML
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Get CSRF meta tag for AJAX
 */
function csrfMeta(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(getCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
}