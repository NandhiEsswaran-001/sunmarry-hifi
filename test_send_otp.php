<?php
// Simple testing endpoint to trigger OTP send for a user id
// Usage: test_send_otp.php?id=123
$requireArg = null;
require_once 'db.php';
require_once 'auth.php';

// Support both web GET ?id= and CLI usage: `php test_send_otp.php 1`
if (PHP_SAPI === 'cli') {
    global $argv;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $requireArg = (int)$argv[1];
    } else {
        echo "Usage: php test_send_otp.php <user_id>\n";
        exit(1);
    }
}

if ($requireArg === null && !isset($_GET['id'])) {
    echo "Missing ?id= parameter\n";
    exit;
}

$id = $requireArg ?? (int)$_GET['id'];
$result = generate_and_send_otp_for_user($id);
$log = '';
// show both the summary log and debug SMTP log when present
$logFile = __DIR__ . '/logs/otp.log';
$debugFile = __DIR__ . '/logs/otp_debug.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log = implode("\n", array_slice($lines, -20));
}

echo "send_result=" . ($result ? '1' : '0') . "\n";
if ($log !== '') {
    echo "--- last otp.log lines ---\n" . $log . "\n";
}
if (file_exists($debugFile)) {
    $dlines = file($debugFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $dlog = implode("\n", array_slice($dlines, -200));
    echo "--- last otp_debug.log lines ---\n" . $dlog . "\n";
}
?>