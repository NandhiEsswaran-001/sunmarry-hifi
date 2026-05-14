<?php
session_start();
require_once 'db.php';
require_once 'rate_limit.php';

$error = '';
$registrationError = '';
$registrationSuccess = '';
$showRegistrationForm = false;
$registerData = [
    'name' => '',
    'phone' => '',
    'alternate_phone' => '',
    'marriage_type' => '',
    'caste' => '',
    'birth_date' => '',
    'city' => '',
    'education' => ''
];

if (isset($_GET['error']) && $_GET['error'] === 'credits_expired') {
    $error = "Your credits are completed. Please contact the administrator.";
}

if (!checkRateLimit('login', 5, 300)) {
    $error = 'Too many login attempts. Please wait 5 minutes before trying again.';
}

if (!checkRateLimit('register', 3, 3600)) {
    $registrationError = 'Too many registration requests. Please try again later.';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register_request') {
        $showRegistrationForm = true;

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $registrationError = 'Invalid request. Please try again.';
        } else {
            foreach (array_keys($registerData) as $field) {
                $registerData[$field] = trim((string)($_POST[$field] ?? ''));
            }

        $requiredFields = [
            'name' => 'Name',
            'phone' => 'Phone number',
            'marriage_type' => 'திருமண வகை',
            'caste' => 'Caste',
            'birth_date' => 'Date of birth',
            'city' => 'City',
            'education' => 'Education'
        ];

        foreach ($requiredFields as $field => $label) {
            if ($registerData[$field] === '') {
                $registrationError = $label . ' is required.';
                break;
            }
        }

        if ($registrationError === '' && !preg_match('/^\d{10}$/', $registerData['phone'])) {
            $registrationError = 'Phone number must be exactly 10 digits.';
        }

        if ($registrationError === '' && $registerData['alternate_phone'] !== '' && !preg_match('/^\d{10}$/', $registerData['alternate_phone'])) {
            $registrationError = 'Alternative phone number must be exactly 10 digits.';
        }

        if ($registrationError === '' && !in_array($registerData['marriage_type'], ['முதல்மணம்', 'மறுமணம்', 'First', 'Second'], true)) {
            $registrationError = 'Please choose a valid marriage type.';
        }

        if ($registrationError === '') {
            $birthDate = DateTime::createFromFormat('Y-m-d', $registerData['birth_date']);
            $today = new DateTime('today');
            if (!$birthDate || $birthDate->format('Y-m-d') !== $registerData['birth_date']) {
                $registrationError = 'Please choose a valid date of birth.';
            } else {
                $age = $today->diff($birthDate)->y;
                if ($birthDate > $today || $age < 18 || $age > 70) {
                    $registrationError = 'Date of birth must be between 18 and 70 years.';
                }
            }
        }

        if ($registrationError === '') {
            $stmt = $pdo->prepare(
                "INSERT INTO registration_requests
                (name, phone, alternate_phone, marriage_type, caste, birth_date, city, education)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $registerData['name'],
                $registerData['phone'],
                $registerData['alternate_phone'] !== '' ? $registerData['alternate_phone'] : null,
                $registerData['marriage_type'],
                $registerData['caste'],
                $registerData['birth_date'],
                $registerData['city'],
                $registerData['education']
            ]);

            $registrationSuccess = 'உங்கள் விவரங்கள் பெறப்பட்டுவிட்டது. விரைவில் நாங்கள் உங்களைத் தொடர்பு கொள்ளுவோம்.';
            $showRegistrationForm = false;
            foreach (array_keys($registerData) as $field) {
                $registerData[$field] = '';
            }
        }
        }
    } elseif ($action === 'login' && $error === '') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $error = 'Invalid request. Please try again.';
        } elseif ($username && $password) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                $storedHash = $user['password'] ?? '';
                $passwordOk = $storedHash && password_verify($password, $storedHash);

                if ($passwordOk) {
                    session_regenerate_id(true);
                    $dbRole = $user['role'] ?? '';

                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $dbRole === 'support' ? 'customer' : $dbRole;

                    $allowedRedirects = [
                        'super_admin' => 'admin_dashboard.php',
                        'admin' => 'admin_dashboard.php',
                        'manager' => 'profiles.php',
                        'customer' => 'profiles.php',
                        'special_customer' => 'profiles.php'
                    ];
                    $role = $_SESSION['role'] ?? '';
                    $redirectTo = $allowedRedirects[$role] ?? 'home.php';
                    header('Location: ' . $redirectTo);
                    exit();
                }

                $error = 'Invalid password.';
            } else {
                $error = 'User not found.';
            }
        } else {
            $error = 'Please enter both username and password.';
        }
    }
}

try {
    ensureRegistrationRequestsTable($pdo);
} catch (PDOException $e) {
}
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.16);
            --glass-border: rgba(255, 255, 255, 0.35);
            --glass-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
        }

        body.login-hero {
            min-height: 100vh;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.45), rgba(0, 0, 0, 0.35)), url('assets/login-hero.jpg') center/cover no-repeat fixed;
        }

        .marquee-bar {
            background: rgba(0, 0, 0, 0.55);
            color: #fff;
            padding: 8px 0;
            overflow: hidden;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
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
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .login-card label {
            color: #fff;
            font-weight: 500;
        }

        .login-card .form-control,
        .login-card .form-select {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: #212529;
        }

        .login-card .form-control:focus,
        .login-card .form-select:focus {
            background: #fff;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
            border-color: rgba(13, 110, 253, 0.65);
        }

        .login-card .form-control::placeholder {
            color: #6c757d;
        }

        .register-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .register-card .card-header {
            background: rgba(13, 110, 253, 0.75);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .register-card label {
            color: #fff;
            font-weight: 500;
        }

        .register-card .form-control,
        .register-card .form-select {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: #212529;
        }

        .register-card .form-control:focus,
        .register-card .form-select:focus {
            background: #fff;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
            border-color: rgba(13, 110, 253, 0.65);
        }

        .register-card .form-control::placeholder {
            color: #6c757d;
        }

        .whatsapp-float {
            position: fixed;
            width: 60px;
            height: 60px;
            bottom: 25px;
            right: 25px;
            background-color: #25D366;
            color: #FFF;
            border-radius: 50px;
            text-align: center;
            font-size: 30px;
            line-height: 60px;
            box-shadow: 2px 2px 10px rgba(0,0,0,0.3);
            z-index: 1000;
            text-decoration: none;
            animation: whatsappPulse 2s infinite;
        }
        .whatsapp-float:hover {
            background-color: #128C7E;
            color: #FFF;
            text-decoration: none;
        }
        @keyframes whatsappPulse {
            0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); }
            70% { box-shadow: 0 0 0 15px rgba(37, 211, 102, 0); }
            100% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
        }
    </style>
</head>
<body class="login-hero">
    <?php include 'header.php'; ?>
    <div class="marquee-bar">
        <div class="marquee">சன் மெட்ரிமோனி | அன்பும் நம்பிக்கையும் இணையும் இடம் | புதிய சுயவிவரம் உருவாக்கி உங்கள் வாழ்க்கை துணையைத் தேடுங்கள்</div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center mt-5 pt-4 pb-5 mb-5">
            <div class="col-md-5">
                <div class="card login-card <?php echo $showRegistrationForm ? 'd-none' : ''; ?>" id="loginCard">
                    <div class="card-header text-center bg-primary text-white">
                        <h5>Sun Matrimony Login</h5>
                        <h5>திருமண பதிவு உள்நுழைவு</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger text-center"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <?php if ($registrationSuccess): ?>
                            <div class="alert alert-success text-center"><?php echo htmlspecialchars($registrationSuccess); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" maxlength="50" required autocomplete="username">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" maxlength="128" required autocomplete="current-password">
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Login</button>
                            </div>
                        </form>

                        <div class="text-center mt-3 text-white">
                            <a href="#" class="btn btn-success register-link" id="registerToggle">New User - Registration</a>
                        </div>
                    </div>
                </div>

                <div class="card register-card <?php echo $showRegistrationForm ? '' : 'd-none'; ?>" id="registerRequestCard">
                    <div class="card-header text-center bg-primary text-white">
                        <h5>Sun Matrimony Register</h5>
                        <h5>திருமண பதிவு</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($registrationError): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($registrationError); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="register_request">
                            <div class="mb-3">
                                <label for="register_name" class="form-label">பெயர்</label>
                                <input type="text" class="form-control" id="register_name" name="name" value="<?php echo htmlspecialchars($registerData['name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="register_phone" class="form-label">தொலைபேசி</label>
                                <input type="tel" class="form-control" id="register_phone" name="phone" value="<?php echo htmlspecialchars($registerData['phone']); ?>" pattern="\d{10}" maxlength="10" inputmode="numeric" required>
                            </div>
                            <div class="mb-3">
                                <label for="register_alternate_phone" class="form-label">மாற்று தொலைபேசி</label>
                                <input type="tel" class="form-control" id="register_alternate_phone" name="alternate_phone" value="<?php echo htmlspecialchars($registerData['alternate_phone']); ?>" pattern="\d{10}" maxlength="10" inputmode="numeric">
                            </div>
                            <div class="mb-3">
                                <label for="register_marriage_type" class="form-label">திருமண வகை</label>
                                <select class="form-select" id="register_marriage_type" name="marriage_type" required>
                                    <option value="">தேர்வு செய்க</option>
                                    <option value="முதல்மணம்" <?php echo $registerData['marriage_type'] === 'முதல்மணம்' ? 'selected' : ''; ?>>முதல்மணம்</option>
                                    <option value="மறுமணம்" <?php echo $registerData['marriage_type'] === 'மறுமணம்' ? 'selected' : ''; ?>>மறுமணம்</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="register_caste" class="form-label">சாதி</label>
                                <select class="form-select" id="register_caste" name="caste" required>
                                    <option value="">-- தேர்வு செய்க --</option>
                                    <?php
$castes = [
                                        '24 மனை தெலுங்கு (8 வீடு)',
                                        '24 மனை தெலுங்கு (16 வீடு)',
                                        'கவுண்டர் (கொங்கு வெள்ளாள கவுண்டர்)',
                                        'கவுண்டர் (வேட்டுவ கவுண்டர்)',
                                        'கவுண்டர் (குறும்ப கவுண்டர்)',
                                        'நாயுடு (கம்மவார் நாயுடு)',
                                        'நாயுடு (கவரா நாயுடு)',
                                        'நாயுடு (பலிஜா நாயுடு)',
                                        'செட்டியார் (கன்னட தேவாங்க செட்டியார்)',
                                        'செட்டியார் (தெலுங்கு தேவாங்க செட்டியார்)',
                                        'செட்டியார் (வாணிய செட்டியார்)',
                                        'செட்டியார் (கொங்கு செட்டியார்)',
                                        'செட்டியார் (சைவ செட்டியார்)',
                                        'செட்டியார் (நாட்டுக்கோட்டை செட்டியார்)',
                                        'செட்டியார் (ஆரிய வைசியர்)',
                                        'செட்டியார் (ஆயிரம் வைசியர்)',
                                        'செட்டியார் (வெள்ளஞ்செட்டியார்)',
                                        'தேவர் (அகமுடையார்)',
                                        'தேவர் (மறவர்)',
                                        'தேவர் (கள்ளர்)',
                                        'விஸ்வகர்மா (தமிழ்)',
                                        'விஸ்வகர்மா (தெலுங்கு)',
                                        'விஸ்வகர்மா (மலையாளம்)',
                                        'பிராமின் (ஐயங்கார்)',
                                        'பிராமின் (அய்யர்)',
                                        'பிராமின் (மத்வா - கன்னட பிராமின்)',
                                        'பிராமின் (தெலுங்கு பிராமின்)',
                                        'பிராமின் (குருக்கள்)',
                                        'கிறிஸ்டியன் (RC)',
                                        'கிறிஸ்டியன் (CSI)',
                                        'கிறிஸ்டியன் (Pentecost)',
                                        'முஸ்லிம்கள்',
                                        'முஸ்லிமும் (தமிழ் முஸ்லிம)',
                                        'முஸ்லிம (உருது முஸ்லிம)',
                                        'வன்னியர்',
                                        'மருத்துவர்',
                                        'நாடார்',
                                        'முதலியார்',
                                        'பிள்ளை',
                                        'முத்திரையர் / முத்துராஜா / அம்பலக்காரர்',
                                        'உடையார் / குலாலர்',
                                        'ரெட்டியார்',
                                        'ஒக்கலிக கவுடர்',
                                        'சௌராஷ்டிரா',
                                        'மூப்பனார்',
                                        'நாயர்',
                                        'ஈழவா',
                                        'ஜங்கம் / பண்டாரம் / வீர சைவம்',
                                        'போயர்',
                                        'தேவேந்திர குல வெள்ளாளர்',
                                        'அருந்ததியர்',
                                        'ஆதி திராவிடர்',
                                        'நாயக்கர்',
                                        'யாதவா / கோணார்',
                                        'வண்ணார்',
                                        'சேனைத் தலைவர்',
                                        'வள்ளுவர்',
                                        'குறவர்',
                                        'மீனவர்'
                                    ];
                                    foreach ($castes as $c) {
                                        echo "<option value=\"".htmlspecialchars($c)."\"".($registerData['caste'] === $c ? ' selected' : '').">".htmlspecialchars($c)."</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="register_birth_date" class="form-label">பிறந்த தேதி</label>
                                <input type="date" class="form-control" id="register_birth_date" name="birth_date" value="<?php echo htmlspecialchars($registerData['birth_date']); ?>" min="<?php echo date('Y-m-d', strtotime('-70 years')); ?>" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="register_city" class="form-label">வசிக்கும் ஊர்</label>
                                <input type="text" class="form-control" id="register_city" name="city" value="<?php echo htmlspecialchars($registerData['city']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="register_education" class="form-label">படிப்பு</label>
                                <input type="text" class="form-control" id="register_education" name="education" value="<?php echo htmlspecialchars($registerData['education']); ?>" required>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success">சமர்ப்பி</button>
                                <button type="button" class="btn btn-primary" id="cancelRegister">உள்நுழைக்குத் திரும்பு</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="text-center text-white py-2 mt-auto" style="background: rgba(0,0,0,0.7); position: fixed; bottom: 0; width: 100%;">
        <p class="mb-0" style="font-size: 1.5rem;">
            Contact: +91 90471 79211 | +91 86400 90400 | +91 63793 99175 | +91 82480 55207 | +91 97917 81651
        </p>
    </footer>
    <style>
    body { min-height: 100vh; display: flex; flex-direction: column; }
    .container { flex: 1; }
    @media (max-width: 576px) {
        footer p { font-size: 1.1rem !important; }
    }
    </style>

    <a href="https://wa.me/918148653302" target="_blank" class="whatsapp-float" title="Chat on WhatsApp">
        <i class="bi bi-whatsapp"></i>
    </a>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggle = document.getElementById('registerToggle');
            const loginCard = document.getElementById('loginCard');
            const card = document.getElementById('registerRequestCard');
            const cancel = document.getElementById('cancelRegister');
            const phoneInputs = ['register_phone', 'register_alternate_phone']
                .map(function (id) { return document.getElementById(id); })
                .filter(Boolean);

            function showRegisterForm(visible) {
                if (loginCard) {
                    loginCard.classList.toggle('d-none', visible);
                }
                if (card) {
                    card.classList.toggle('d-none', !visible);
                }
            }

            if (toggle) {
                toggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    showRegisterForm(true);
                });
            }

            if (cancel) {
                cancel.addEventListener('click', function () {
                    showRegisterForm(false);
                });
            }

            phoneInputs.forEach(function (input) {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, 10);
                });
            });
        });
    </script>
</body>
</html>
