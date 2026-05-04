<?php
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure required user columns and support_profile_views table exist
try {
    $pdo = getDB();
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');

    if (!in_array('role', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('super_admin', 'admin', 'manager', 'customer', 'special_customer', 'support') NOT NULL DEFAULT 'customer'");
    } else {
        // Ensure role enum includes 'admin' for admin users
        foreach ($cols as $c) {
            if ($c['Field'] === 'role' && isset($c['Type']) && (strpos($c['Type'], "'admin'") === false || strpos($c['Type'], "'special_customer'") === false)) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'manager', 'customer', 'special_customer', 'support') NOT NULL DEFAULT 'customer'");
                break;
            }
        }
    }
    if (!in_array('credits', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN credits INT DEFAULT 25");
    }
    if (!in_array('profiles_viewed', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profiles_viewed INT DEFAULT 0");
    }
    if (!in_array('last_login', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
    }
    if (!in_array('note', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN note TEXT DEFAULT NULL");
    }
    // Create support_profile_views table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_profile_views (
        user_id INT NOT NULL,
        profile_id INT NOT NULL,
        PRIMARY KEY (user_id, profile_id)
    )");
} catch (PDOException $e) {
    // If we can't modify schema (no privileges or table missing), continue gracefully.
}

// Ensure OTP columns exist for Super Admin flow
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('otp_code', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('otp_expires', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires DATETIME DEFAULT NULL");
    }
} catch (PDOException $e) {
    // ignore
}

// Authentication Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

function getUserRole() {
    $r = $_SESSION['role'] ?? null;
    // Normalize old DB value 'support' to app-facing 'customer'
    if ($r === 'support') return 'customer';
    return $r;
}

function getCustomerCreditLimit(): int {
    return 25;
}

function isCustomerRole(?string $role = null): bool {
    $role = $role ?? getUserRole();
    return in_array($role, ['customer', 'special_customer'], true);
}

function isSpecialCustomer(?string $role = null): bool {
    $role = $role ?? getUserRole();
    return $role === 'special_customer';
}

function checkPermission($required_role) {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }

    $role_hierarchy = [
        'super_admin' => 3,
        'admin' => 3,
        'manager' => 2,
        'customer' => 1,
        'special_customer' => 1
    ];

    $user_role = getUserRole();
    
    if (!isset($role_hierarchy[$user_role]) || 
        $role_hierarchy[$user_role] < $role_hierarchy[$required_role]) {
        header('Location: access_denied.php');
        exit();
    }
    
    // Do not auto-logout customers here; charging happens on actions (view/print).
    
    return true;
}

function chargeCustomerForProfileAction(int $profile_id): bool {
    // Returns true if action allowed (credits consumed or already consumed for this profile).
    if (!isCustomerRole()) return true;
    $pdo = getDB();
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id || $profile_id <= 0) return true;

    // If already recorded for this profile, do not charge again
    $stmt = $pdo->prepare("SELECT 1 FROM support_profile_views WHERE user_id = ? AND profile_id = ?");
    $stmt->execute([$user_id, $profile_id]);
    if ($stmt->fetch()) return true;

    // Check credits
    $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $credits = (int)$stmt->fetchColumn();
    $limit = getCustomerCreditLimit();
    if ($credits > $limit) {
        // Clamp legacy higher balances to the current limit
        $credits = $limit;
        $pdo->prepare("UPDATE users SET credits = ? WHERE id = ?")->execute([$limit, $user_id]);
    }
    if ($credits <= 0) {
        return false; // limit exceeded
    }

    // Charge: insert view and decrement credit in a transaction
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO support_profile_views (user_id, profile_id) VALUES (?, ?)")
            ->execute([$user_id, $profile_id]);
        $pdo->prepare("UPDATE users SET credits = credits - 1 WHERE id = ?")
            ->execute([$user_id]);
        // legacy counter
        if (in_array('profiles_viewed', array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field'))) {
            $pdo->prepare("UPDATE users SET profiles_viewed = profiles_viewed + 1 WHERE id = ?")->execute([$user_id]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // On failure, allow action to proceed (do not lock user out due to DB error)
        return true;
    }
}

function incrementProfileViews(): bool {
    $profile_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    return chargeCustomerForProfileAction($profile_id);
}

// OTP helpers for Super Admin
function generate_and_send_otp_for_user(int $user_id): bool {
    $pdo = getDB();
    // 6-digit OTP
    $otp = random_int(100000, 999999);
    $hashed = password_hash((string)$otp, PASSWORD_DEFAULT);
    $expiresAt = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE id = ?");
        $stmt->execute([$hashed, $expiresAt, $user_id]);
    } catch (PDOException $e) {
        return false;
    }

    // Load SMTP config if not already loaded
    if (!defined('SMTP_USER')) {
        if (file_exists(__DIR__ . '/smtp.php')) {
            require_once __DIR__ . '/smtp.php';
        }
    }
    
    // Determine recipient: use user's email when present
    try {
        $emStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $emStmt->execute([$user_id]);
        $userEmail = $emStmt->fetchColumn();
    } catch (Exception $e) {
        $userEmail = null;
    }
    // Normalize and validate fetched email
    $userEmail = is_string($userEmail) ? trim($userEmail) : '';
    $isValidEmail = $userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL);
    $to = $isValidEmail ? $userEmail : '';
    $subject = 'Your OTP Code - Sunmarry';
    $message = "===========================================\n"
             . "SUNMARRY OTP VERIFICATION\n"
             . "===========================================\n\n"
             . "Your One-Time Password (OTP):\n\n"
             . "  ➤ $otp\n\n"
             . "⏱️  This code expires in: 5 minutes\n"
             . "⚠️  Do not share this code with anyone\n\n"
             . "If you did not request this code, please ignore this email.\n"
             . "===========================================\n";
    $fromHeader = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('SMTP_USER') ? SMTP_USER : 'no-reply@localhost');
    $replyToHeader = defined('SMTP_USER') ? SMTP_USER : '';
    $headers = "From: " . $fromHeader . ($replyToHeader ? "\r\nReply-To: " . $replyToHeader : '') . "\r\nContent-Type: text/plain; charset=UTF-8";

    $sent = false;
    $method = 'none';
    $errorMsg = '';

    // PHPMailer if present
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            // Always load SMTP credentials from smtp.php before creating PHPMailer instance
            if (file_exists(__DIR__ . '/smtp.php')) {
                require_once __DIR__ . '/smtp.php';
            }
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $method = 'phpmailer';
            // Configure SMTP if credentials are available
            if (defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS') && SMTP_PASS !== '') {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
                // Ensure envelope sender is the authenticated SMTP user to avoid SPF/DMARC issues
                $mail->Sender = SMTP_USER;
                // Optional debug logging into logs/otp_debug.log when enabled in smtp.php
                if (defined('SMTP_DEBUG') && SMTP_DEBUG) {
                    $mail->SMTPDebug = SMTP_DEBUG;
                    $mail->Debugoutput = function($str, $level) {
                        $logDir = __DIR__ . '/logs';
                        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                        $entry = sprintf("%s | SMTPDBG | level=%s | %s\n", (new DateTime())->format('Y-m-d H:i:s'), $level, trim($str));
                        @file_put_contents($logDir . '/otp_debug.log', $entry, FILE_APPEND | LOCK_EX);
                    };
                }
            } else {
                // If SMTP not configured, report error and fail
                throw new \Exception('SMTP credentials not configured. Please check smtp.php file.');
            }
            // Ensure the From header matches the authenticated SMTP user to
            // comply with Gmail/DMARC rules and reduce delivery rejections.
            $fromEmail = defined('SMTP_USER') ? SMTP_USER : (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'no-reply@localhost');
            $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Sun Matrimony';
            $mail->setFrom($fromEmail, $fromName);
            // If an alternate reply-to is configured (original SMTP user), add it
            if (!empty($replyToHeader)) {
                $mail->addReplyTo($replyToHeader);
            }
            if ($to === '') {
                throw new \Exception('Recipient email missing or invalid for user id ' . $user_id);
            }
            $mail->addAddress($to);
            // TEMPORARY DEBUG BCC: send a copy to developer/test address so admin can always
            // receive a copy while debugging live delivery. Commented out for security.
            // try {
            //     $mail->addBCC('arunasaithambiclassified@gmail.com');
            // } catch (Exception $e) {
            //     // ignore if BCC fails
            // }
            $mail->Subject = $subject;
            $mail->Body = $message;
            $sent = (bool)$mail->send();
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage() . ' | PHPMailerError: ' . (isset($mail) ? $mail->ErrorInfo : 'PHPMailer not initialized');
            $sent = false;
        }
    } else {
        // Fallback to PHP mail()
        $method = 'mail()';
        try {
            if ($to === '') {
                throw new \Exception('Recipient email missing or invalid for user id ' . $user_id);
            }
            $sent = (bool)@mail($to, $subject, $message, $headers);
            if (!$sent) $errorMsg = 'mail() returned false';
        } catch (\Exception $e) {
            $sent = false;
            $errorMsg = $e->getMessage();
        }
    }

    // Log attempts for debugging (creates public_html/logs/otp.log)
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $entry = sprintf("%s | user_id=%d | fetched_email=%s | to=%s | sent=%s | err=%s\n",
            (new DateTime())->format('Y-m-d H:i:s'), $user_id, ($userEmail ?: 'NULL'), ($to ?: 'NONE'), $sent ? '1' : '0', str_replace("\n", ' ', $errorMsg)
        );
        @file_put_contents($logDir . '/otp.log', $entry, FILE_APPEND | LOCK_EX);
    } catch (\Exception $e) {
        // ignore logging errors
    }

    return $sent;
}

function verify_otp_for_user(int $user_id, string $otp): bool {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT otp_code, otp_expires FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['otp_code'] || !$row['otp_expires']) return false;
        $expires = new DateTime($row['otp_expires']);
        $now = new DateTime();
        if ($now > $expires) return false;
        if (!password_verify($otp, $row['otp_code'])) return false;

        // Clear OTP fields
        $clear = $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?");
        $clear->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
?>
