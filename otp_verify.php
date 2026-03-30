<?php
require_once 'db.php';
require_once 'auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
// Ensure there's a pending OTP
if (empty($_SESSION['pending_otp_user_id'])) {
    header('Location: login.php');
    exit();
}

$pendingUserId = (int)$_SESSION['pending_otp_user_id'];
$pendingUsername = $_SESSION['pending_otp_username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    if ($otp === '') {
        $error = 'OTP ஐ உள்ளிடவும்.';
    } else {
        if (verify_otp_for_user($pendingUserId, $otp)) {
            // OTP valid — establish full session
            $stmt = getDB()->prepare('SELECT id, username, role FROM users WHERE id = ?');
            $stmt->execute([$pendingUserId]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $dbRole = $user['role'] ?? '';
                if ($dbRole === 'support') {
                    $_SESSION['role'] = 'customer';
                } else {
                    $_SESSION['role'] = $dbRole;
                }
                // update last_login
                getDB()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
                // Clear pending vars
                unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_username']);
                $redirectTo = isCustomerRole() ? 'profiles.php' : 'home.php';
                header('Location: ' . $redirectTo);
                exit();
            } else {
                $error = 'User not found.';
            }
        } else {
            $error = 'தவறான அல்லது காலாவதியான OTP. மீண்டும் முயற்சிக்கவும்.';
        }
    }
}
?>
<!doctype html>
<html lang="ta">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OTP சரிபார்ப்பு</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Super Admin OTP Verification</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <p>OTP was sent to the administrator email. Enter the 6-digit code below. It expires in 5 minutes.</p>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="otp" class="form-label">OTP</label>
                            <input type="text" id="otp" name="otp" class="form-control" maxlength="6" required>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary">Verify OTP</button>
                        </div>
                    </form>
                    <div class="mt-3 text-muted">Pending: <?php echo htmlspecialchars($pendingUsername); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
