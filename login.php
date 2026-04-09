<?php
session_start();
require_once 'db.php';

// Ensure required user columns exist to avoid runtime errors (safe, idempotent)
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');

    if (!in_array('role', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('super_admin', 'admin', 'manager', 'customer', 'special_customer', 'support') NOT NULL DEFAULT 'customer'");
    } else {
        foreach ($cols as $c) {
            if ($c['Field'] === 'role' && isset($c['Type']) && (strpos($c['Type'], "'admin'") === false || strpos($c['Type'], "'special_customer'") === false)) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'manager', 'customer', 'special_customer', 'support') NOT NULL DEFAULT 'customer'");
                break;
            }
        }
    }
    if (!in_array('profiles_viewed', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profiles_viewed INT DEFAULT 0");
    }
    if (!in_array('last_login', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
    }
} catch (PDOException $e) {
    // If schema modifications fail (permissions, missing table), continue without breaking login flow.
    // We intentionally do not expose detailed DB errors to the user here.
}

$error = '';

if (isset($_GET['error']) && $_GET['error'] === 'credits_expired') {
    $error = "நீங்கள் உங்கள் அனைத்து பாயிண்ட் -ஐயும் பயன்படுத்திவிட்டீர்கள். மேலாளரைத் தொடர்பு கொள்ளவும்.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        // Prepare and execute query
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // Support secure password_hash() values and fall back to legacy md5() hashes.
            $storedHash = $user['password'] ?? '';
            $passwordOk = false;

            // If stored is a password_hash value, use password_verify()
            if ($storedHash && (password_verify($password, $storedHash))) {
                $passwordOk = true;
            } elseif ($storedHash === md5($password)) {
                // Legacy MD5 match: accept login but upgrade hash to password_hash()
                $passwordOk = true;
                try {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $rehashStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $rehashStmt->execute([$newHash, $user['id']]);
                } catch (PDOException $e) {
                    // If rehash fails, continue login anyway; do not expose DB errors to user.
                }
            }

            if ($passwordOk) {
                // Do not reset `profiles_viewed` on login — preserve the count across sessions.

                // For Super Admins require OTP verification before establishing full session
                $dbRole = $user['role'] ?? '';
                $appRole = ($dbRole === 'support') ? 'customer' : $dbRole;

if (false) { // disabled OTP for all
                    // Store pending info and generate/send OTP
                    $_SESSION['pending_otp_user_id'] = $user['id'];
                    $_SESSION['pending_otp_username'] = $user['username'];
                    // Generate and send OTP (function in auth.php)
                    require_once 'auth.php';
                    generate_and_send_otp_for_user($user['id']);
                    header('Location: otp_verify.php');
                    exit();
                }

                // Non-super_admin: proceed with normal login
                // Update last login
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);

                // Start session and store login info
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                // Normalize DB role: map legacy 'support' to app-facing 'customer'
                if ($dbRole === 'support') {
                    $_SESSION['role'] = 'customer';
                } else {
                    $_SESSION['role'] = $dbRole;
                }

                // Redirect based on role
                $redirectTo = 'home.php';
                $role = $_SESSION['role'] ?? $dbRole ?? '';
                if (in_array($role, ['customer', 'special_customer'], true)) {
                    $redirectTo = 'profiles.php';
                }
                header("Location: " . $redirectTo);
                exit();
            } else {
                $error = "❌ தவறான கடவுச்சொல்.";
            }
        } else {
            $error = "❌ பயனர் கிடைக்கவில்லை.";
        }
    } else {
        $error = "பயனர்பெயர் மற்றும் கடவுச்சொல் இரண்டையும் உள்ளிடவும்.";
    }
}
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.16);
            --glass-border: rgba(255, 255, 255, 0.35);
            --glass-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
        }

        body.login-hero {
            min-height: 100vh;
            background: linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.35)), url('assets/hero.png') center/cover no-repeat fixed;
        }

        .marquee-bar {
            background: rgba(0, 0, 0, 0.55);
            color: #fff;
            padding: 8px 0;
            overflow: hidden;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .marquee {
            display: inline-block;
            white-space: nowrap;
            animation: marquee 18s linear infinite;
            padding-left: 100%;
        }
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }

        .login-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .login-card .card-header {
            background: rgba(13, 110, 253, 0.75);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .login-card .form-control {
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(255,255,255,0.6);
        }
        .login-card .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
            border-color: rgba(13, 110, 253, 0.65);
        }
</style>
    </head>
    <body class="login-hero">
        <?php include 'header.php'; ?>
        <div class="marquee-bar">
            <div class="marquee">சன் மெட்ரிமோனி | அன்பும் நம்பிக்கையும் இணையும் இடம் | புதிய சுயவிவரம் உருவாக்கி உங்கள் வாழ்க்கை துணையைத் தேடுங்கள்</div>
        </div>
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-5">
                <div class="card login-card">
                    <div class="card-header text-center bg-primary text-white">
                        <h4>Sun Matrimony Login</h4>
                        <h4>திருமண பதிவு உள்நுழைவு</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger text-center">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">பயனர்பெயர்</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="பயனர்பெயரை உள்ளிடவும்" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">கடவுச்சொல்</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="கடவுச்சொல்லை உள்ளிடவும்" required>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">உள்நுழைக</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <footer class="text-center text-white py-2 fixed-bottom" style="background: rgba(0,0,0,0.7);">
        <p class="mb-0 small">சன் மேட்ரிமோனி | www.sunmatri.in | +91 86400 90400 | +91 63793 99175 | +91 82480 55207 | +91 97917 81651</p>
    </footer>
</body>
</html>
