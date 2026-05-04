<?php
function checkRateLimit($action, $maxAttempts = 5, $timeWindow = 300) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }

    $session = $_SESSION[$key];
    $elapsed = time() - $session['first_attempt'];

    if ($elapsed > $timeWindow) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        return true;
    }

    if ($session['count'] >= $maxAttempts) {
        return false;
    }

    $_SESSION[$key]['count']++;
    return true;
}

function getRateLimitRemaining($action, $maxAttempts = 5, $timeWindow = 300) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";

    if (!isset($_SESSION[$key])) {
        return $maxAttempts;
    }

    $session = $_SESSION[$key];
    $elapsed = time() - $session['first_attempt'];

    if ($elapsed > $timeWindow) {
        return $maxAttempts;
    }

    return max(0, $maxAttempts - $session['count']);
}

function resetRateLimit($action) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    unset($_SESSION[$key]);
}

function applySecurityHeaders() {
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        
        $csp = "default-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://ajax.googleapis.com https://wa.me 'unsafe-inline' 'unsafe-eval';";
        if (defined('APP_ENV') && APP_ENV !== 'production') {
            $csp .= " connect-src 'self' https://wa.me;";
        }
        header("Content-Security-Policy: $csp");
    }
}
?>