<?php
require_once 'auth.php';
requireLogin();

// Only allow super_admin and manager roles
if (isCustomerRole()) {
    header('Location: access_denied.php');
    exit();
}

// Ensure `education_details` and `notes` columns exist in `profiles` table
try {
    $pdo = getDB();
    $colStmt = $pdo->query("SHOW COLUMNS FROM profiles");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('education_details', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN education_details VARCHAR(500) DEFAULT ''");
    }
    if (!in_array('notes', $colNames)) {
        // notes stored as TEXT
        $pdo->exec("ALTER TABLE profiles ADD COLUMN notes TEXT DEFAULT ''");
    }
} catch (PDOException $e) {
    // ignore schema change errors
}

// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: profiles.php');
    exit();
}

$message = '';
$messageType = 'info';

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = 'சுயவிவரம் வெற்றிகரமாக புதுப்பிக்கப்பட்டது!';
    $messageType = 'success';
}

if (!function_exists('parseBirthDateInput')) {
    function parseBirthDateInput($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}

// Constants for file upload
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('ALLOWED_DOC_TYPES', ['application/pdf', 'image/jpeg', 'image/png']);

// Fetch existing profile
$stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
$stmt->execute([$id]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: profiles.php');
    exit();
}

// Prepare existing birth date/time parts for form prefill
$existing_birth_date = $profile['birth_date'] ?? '';
$display_birth_date = '';
$display_birth_date_iso = '';
if (!empty($existing_birth_date)) {
    $d = DateTime::createFromFormat('Y-m-d', $existing_birth_date);
    if ($d) {
        $display_birth_date = $d->format('d/m/Y');
        $display_birth_date_iso = $d->format('Y-m-d');
    }
}
$birth_hour_val = '';
$birth_minute_val = '';
$birth_ampm_val = '';
if (!empty($profile['birth_time'])) {
    if (preg_match('/^(\d{2}):(\d{2})\s*(AM|PM)$/i', $profile['birth_time'], $m)) {
        $birth_hour_val = (int)$m[1];
        $birth_minute_val = (int)$m[2];
        $birth_ampm_val = strtoupper($m[3]);
    }
}

// Display age - priority: POST value > stored age > computed from birth_date
$display_age = null;
if (isset($_POST['age']) && $_POST['age'] !== '') {
    $display_age = (int)$_POST['age'];
} elseif (isset($profile['age'])) {
    $display_age = (int)$profile['age'];
} elseif (!empty($profile['birth_date'])) {
    $d = DateTime::createFromFormat('Y-m-d', $profile['birth_date']);
    if ($d) {
        $display_age = $d->diff(new DateTime())->y;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Function to compute age from date
    if (!function_exists('computeAgeFromDate')) {
        function computeAgeFromDate($dateStr) {
            $d = DateTime::createFromFormat('Y-m-d', $dateStr);
            if (!$d) return null;
            $now = new DateTime();
            $diff = $now->diff($d);
            return (int)$diff->y;
        }
    }

    $name = $_POST['name'] ?? '';
    $age = isset($_POST['age']) ? (int)$_POST['age'] : null;
    $marriage_type = $_POST['marriage_type'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $district = $_POST['district'] ?? '';
    $city = $_POST['city'] ?? '';
    $birth_place = $_POST['birth_place'] ?? '';
    $caste = $_POST['caste'] ?? '';
    $kulam = trim($_POST['kulam'] ?? '');
    $nakshatram = $_POST['nakshatram'] ?? '';
    $rasi = $_POST['rasi'] ?? '';
    $dosham = $_POST['dosham'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $education = $_POST['education_select'] ?? '';
    $education_text = trim($_POST['education_text'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    // Birth date and time
    $original_birth_date = isset($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
    $birth_date = isset($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
    $dateObj = null;
    if ($birth_date) {
        $dateObj = parseBirthDateInput($birth_date);
        if ($dateObj) {
            $birth_date = $dateObj->format('Y-m-d');
            $age = computeAgeFromDate($birth_date);
        } else {
            $birth_date = null;
            $age = null;
        }
    } else {
        $age = null;
    }
    $birth_hour = isset($_POST['birth_hour']) ? (int)$_POST['birth_hour'] : null;
    $birth_minute = isset($_POST['birth_minute']) ? (int)$_POST['birth_minute'] : null;
    $birth_ampm = $_POST['birth_ampm'] ?? '';
    $birth_time = null;
    if ($birth_hour !== null && $birth_minute !== null && in_array(strtoupper($birth_ampm), ['AM','PM'])) {
        $birth_time = sprintf('%02d:%02d %s', $birth_hour, $birth_minute, strtoupper($birth_ampm));
    }

    // For form redisplay
    $display_birth_date = $original_birth_date ?? '';
    $display_birth_date_iso = $dateObj ? $dateObj->format('Y-m-d') : '';

    // Sibling fields
    $brothers_total = isset($_POST['brothers_total']) ? (int)$_POST['brothers_total'] : 0;
    $brothers_married = 0;
    $sisters_total = isset($_POST['sisters_total']) ? (int)$_POST['sisters_total'] : 0;
    $sisters_married = 0;
    // Profession and phone numbers
    $profession = trim($_POST['profession'] ?? '');
    if (getUserRole() === 'manager') {
        $phone1 = $profile['phone_primary'] ?? '';
        $phone2 = $profile['phone_secondary'] ?? '';
    } else {
        $phone1 = trim($_POST['phone1'] ?? '');
        $phone2 = trim($_POST['phone2'] ?? '');
    }
    // குறிப்பு (phone3) is editable by all roles
    $phone3 = trim($_POST['phone3'] ?? '');

    // Handle file uploads
    $profile_photo = $_FILES['profile_photo'] ?? null;
    $supporting_doc = $_FILES['supporting_doc'] ?? null;

    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $profile_photo_path = $profile['profile_photo'];
    $supporting_doc_path = $profile['file_upload'];

    // Function to validate file upload
    function validateFile($file, $allowedTypes, $maxSize, $type) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception("Error uploading $type: " . $file['error']);
            }
            return false;
        }

        if ($file['size'] > $maxSize) {
            throw new Exception("$type size exceeds limit of " . ($maxSize / 1024 / 1024) . "MB");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception("Invalid $type type. Allowed types: " . implode(', ', $allowedTypes));
        }

        return true;
    }

    // Upload new profile photo if provided
    if ($profile_photo && $profile_photo['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            if (validateFile($profile_photo, ALLOWED_PHOTO_TYPES, MAX_FILE_SIZE, 'Profile photo')) {
                $ext = pathinfo($profile_photo['name'], PATHINFO_EXTENSION);
                $profile_photo_name = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $new_path = $uploadDir . $profile_photo_name;
                
                if (move_uploaded_file($profile_photo['tmp_name'], $new_path)) {
                    // Delete old photo if exists
                    if ($profile_photo_path && file_exists($uploadDir . basename($profile_photo_path))) {
                        unlink($uploadDir . basename($profile_photo_path));
                    }
                    $profile_photo_path = 'uploads/' . $profile_photo_name;
                } else {
                    throw new Exception("Failed to move uploaded profile photo");
                }
            }
        } catch (Exception $e) {
            $message = "Profile photo error: " . $e->getMessage();
            $messageType = 'info';
        }
    }

    // Upload new supporting document if provided
    if ($supporting_doc && $supporting_doc['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            if (validateFile($supporting_doc, ALLOWED_DOC_TYPES, MAX_FILE_SIZE, 'Supporting document')) {
                $ext = pathinfo($supporting_doc['name'], PATHINFO_EXTENSION);
                $supporting_doc_name = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $new_path = $uploadDir . $supporting_doc_name;
                
                if (move_uploaded_file($supporting_doc['tmp_name'], $new_path)) {
                    // Delete old document if exists
                    if ($supporting_doc_path && file_exists($uploadDir . basename($supporting_doc_path))) {
                        unlink($uploadDir . basename($supporting_doc_path));
                    }
                    $supporting_doc_path = 'uploads/' . $supporting_doc_name;
                } else {
                    throw new Exception("Failed to move uploaded supporting document");
                }
            }
        } catch (Exception $e) {
            $message = ($message ? $message . "<br>" : "") . "Supporting document error: " . $e->getMessage();
            $messageType = 'info';
        }
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE profiles
                        SET name = ?, age = ?, marriage_type = ?, gender = ?,
                            district = ?, city = ?, birth_place = ?, birth_date = ?, birth_time = ?, caste = ?, kulam = ?, nakshatram = ?, rasi = ?, dosham = ?, religion = ?, education_type = ?, education_details = ?, brothers_total = ?, brothers_married = ?, sisters_total = ?, sisters_married = ?, profession = ?, phone_primary = ?, phone_secondary = ?, phone_tertiary = ?, notes = ?, profile_photo = ?, file_upload = ?
                WHERE id = ?"
        );

            $stmt->execute([
                $name,
                $age,
                $marriage_type,
                $gender,
                $district,
                $city,
                $birth_place,
                $birth_date,
                $birth_time,
                $caste,
                $kulam,
                $nakshatram,
                $rasi,
                $dosham,
                $religion,
                $education,
                $education_text,
                $brothers_total,
                $brothers_married,
                $sisters_total,
                $sisters_married,
                $profession,
                $phone1,
                $phone2,
                $phone3,
                $notes,
                $profile_photo_path,
                $supporting_doc_path,
                $id
            ]);

        // Only redirect when no upload/validation messages exist
        if (empty($message)) {
            // Redirect to the edit page to reload from DB and avoid form resubmission
            header('Location: edit.php?id=' . intval($id) . '&updated=1');
            exit();
        }
        
        // If we have a non-fatal message, refresh profile data so the form shows latest DB values
        $stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
        $stmt->execute([$id]);
        $profile = $stmt->fetch();
    } catch (PDOException $e) {
        $message = "Error updating profile: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Tamil Nadu districts map: English => Tamil
$districtsMap = [
    'Ariyalur' => 'அரியலூர்',
    'Chennai' => 'சென்னை',
    'Coimbatore' => 'கோயம்புத்தூர்',
    'Cuddalore' => 'கடலூர்',
    'Dharmapuri' => 'தர்மபுரி',
    'Dindigul' => 'திண்டுக்கல்',
    'Erode' => 'ஈரோடு',
    'Kallakurichi' => 'கள்ளக்குறிச்சி',
    'Kanchipuram' => 'காஞ்சிபுரம்',
    'Kanyakumari' => 'கன்னியாகுமரி',
    'Karur' => 'கரூர்',
    'Krishnagiri' => 'கிருஷ்ணகிரி',
    'Madurai' => 'மதுரை',
    'Nagapattinam' => 'நாகப்பட்டினம்',
    'Namakkal' => 'நாமக்கல்',
    'Nilgiris' => 'நீலகிரி',
    'Perambalur' => 'பெரம்பலூர்',
    'Pudukkottai' => 'புதுக்கோட்டை',
    'Ramanathapuram' => 'ராமநாதபுரம்',
    'Salem' => 'சேலம்',
    'Sivaganga' => 'சிவகங்கை',
    'Thanjavur' => 'தஞ்சாவூர்',
    'Theni' => 'தேனி',
    'Thoothukudi' => 'தூத்துக்குடி',
    'Tiruchirappalli' => 'திருச்சிராப்பள்ளி',
    'Tirunelveli' => 'திருநெல்வேலி',
    'Tiruppur' => 'திருப்பூர்',
    'Tiruvallur' => 'திருவல்லூர்',
    'Tiruvannamalai' => 'திருவண்ணாமலை',
    'Tiruvarur' => 'திருவாரூர்',
    'Vellore' => 'வேலூர்',
    'Viluppuram' => 'விழுப்புரம்',
    'Virudhunagar' => 'விருதுநகர்',
    'Chengalpattu' => 'செங்கல்பட்டு',
    'Mayiladuthurai' => 'மயிலாடுதுறை',
    'Ranipet' => 'ராணிப்பேட்டை',
    'Tenkasi' => 'தென்காசி',
    'Tirupathur' => 'திருப்பத்தூர்',
    'Pondicherry' => 'புதுச்சேரி'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- jQuery UI CSS with fallback -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css" id="jquery-ui-fallback">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h2>Edit Profile</h2>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="row">
                <!-- 1. Marriage type -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">திருமண வகை</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="marriage_type" id="first" value="முதல்மணம்" <?php echo (empty($profile['marriage_type']) || $profile['marriage_type'] === 'முதல்மணம்') ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="first">முதல்மணம்</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="marriage_type" id="second" value="மறுமணம்" <?php echo $profile['marriage_type'] === 'மறுமணம்' ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="second">மறுமணம்</label>
                        </div>
                    </div>
                </div>

                <!-- 2. Gender -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">பாலினம்</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gender" id="female" value="Female" <?php echo $profile['gender'] === 'Female' ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="female">பெண்</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gender" id="male" value="Male" <?php echo $profile['gender'] === 'Male' ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="male">ஆண்</label>
                        </div>
                    </div>
                </div>

                <!-- 3. Name -->
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label">பெயர்</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                </div>

                <!-- 4. Birth date -->
                <div class="col-md-6 mb-3">
                    <label for="birth_date" class="form-label">பிறந்த தேதி (நாள்)</label>
                        <input
                            type="date"
                            class="form-control"
                            id="birth_date"
                            name="birth_date"
                            value="<?php echo htmlspecialchars($display_birth_date_iso); ?>"
                            min="<?php echo date('Y-m-d', strtotime('-70 years')); ?>"
                            max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                        >
                </div>

                <!-- 5. Age -->
                <div class="col-md-6 mb-3">
                    <label for="age" class="form-label">வயது</label>
                    <input type="number" class="form-control" id="age" name="age" value="<?php echo htmlspecialchars($display_age); ?>" readonly>
                </div>

                <!-- 6. Birth time -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">பிறந்த நேரம்</label>
                    <div class="d-flex gap-2">
                        <select class="form-select" id="birth_hour" name="birth_hour" style="width:100px;">
                            <?php for ($h = 1; $h <= 12; $h++): $sel = ((int)$birth_hour_val === $h) ? 'selected' : ''; ?>
                                <option value="<?php echo $h; ?>" <?php echo $sel; ?>><?php echo $h; ?></option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select" id="birth_minute" name="birth_minute" style="width:100px;">
                            <?php for ($m = 0; $m < 60; $m++): $mm = str_pad($m,2,'0',STR_PAD_LEFT); $sel = ((int)$birth_minute_val === $m) ? 'selected' : ''; ?>
                                <option value="<?php echo $mm; ?>" <?php echo $sel; ?>><?php echo $mm; ?></option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select" id="birth_ampm" name="birth_ampm" style="width:110px;">
                            <option value="AM" <?php echo $birth_ampm_val === 'AM' ? 'selected' : ''; ?>>AM</option>
                            <option value="PM" <?php echo $birth_ampm_val === 'PM' ? 'selected' : ''; ?>>PM</option>
                        </select>
                    </div>
                </div>

                <!-- 7. Birth place -->
                <div class="col-md-6 mb-3">
                    <label for="birth_place" class="form-label">பிறந்த ஊர்</label>
                    <input type="text" class="form-control" id="birth_place" name="birth_place" value="<?php echo htmlspecialchars($profile['birth_place'] ?? ''); ?>" placeholder="பிறந்த ஊர்">
                </div>

                <!-- 8. Rasi -->
                <div class="col-md-6 mb-3">
                    <label for="rasi" class="form-label">ராசி (Rasi)</label>
                    <select class="form-select" id="rasi" name="rasi">
                        <option value="">-- தேர்வு செய்க --</option>
                        <?php
                        $rasis = ['மேஷம்','ரிஷபம்','மிதுனம்','கடகம்','சிம்மம்','கன்னி','துலாம்','விருச்சிகம்','தனுசு','மகரம்','கும்பம்','மீனம்'];
                        foreach ($rasis as $r) {
                            $sel = (isset($profile['rasi']) && $profile['rasi'] === $r) ? 'selected' : '';
                            echo "<option value=\"".htmlspecialchars($r)."\" $sel>".htmlspecialchars($r)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 9. Nakshatram -->
                <div class="col-md-6 mb-3">
                    <label for="nakshatram" class="form-label">நட்சத்திரம்(Nakshatram)</label>
                    <select class="form-select" id="nakshatram" name="nakshatram">
                        <option value="">-- தேர்வு செய்க --</option>
                        <?php
                        $nakshatrams = ['அஸ்வினி','பரணி','கிருத்திகை','ரோஹிணி','மிருகசீரிடம்','திருவாதிரை','புனர்பூசம்','பூசம்','ஆயில்யம்','மகம்','பூரம்','உத்திரம்','ஹஸ்தம்','சித்திரை','சுவாதி','விசாகம்','அனுஷம்','கேட்டை','மூலம்','பூராடம்','உத்திராடம்','திருவோணம்','அவிட்டம்','சதயம்','பூரட்டாதி','உத்திரட்டாதி','ரேவதி'];
                        foreach ($nakshatrams as $n) {
                            $sel = ($profile['nakshatram'] === $n) ? 'selected' : '';
                            echo "<option value=\"".htmlspecialchars($n)."\" $sel>".htmlspecialchars($n)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 10. Caste -->
                <div class="col-md-6 mb-3">
                    <label for="caste" class="form-label">சாதி பெயர் (Caste)</label>
                    <select class="form-select" id="caste" name="caste">
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
                            'முஸ்லிம் (தமிழ் முஸ்லிம்)',
                            'முஸ்லிம் (உருது முஸ்லிம்)',
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
                            $sel = ($profile['caste'] === $c) ? 'selected' : '';
                            echo "<option value=\"".htmlspecialchars($c)."\" $sel>".htmlspecialchars($c)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 11. Kulam -->
                <div class="col-md-6 mb-3">
                    <label for="kulam" class="form-label">குலம் (Kulam)</label>
                    <input type="text" class="form-control" id="kulam" name="kulam" value="<?php echo htmlspecialchars($profile['kulam'] ?? ''); ?>">
                </div>

                <!-- 12. Dosham -->
                <div class="col-md-6 mb-3">
                    <label for="dosham" class="form-label">தோசம் (Dosham)</label>
                    <select class="form-select" id="dosham" name="dosham">
                        <option value="">-- தேர்வு செய்க --</option>
                        <option value="ராகு கேது" <?php echo ($profile['dosham'] ?? '') === 'ராகு கேது' ? 'selected' : ''; ?>>ராகு கேது</option>
                        <option value="பரிகார செவ்வாய்" <?php echo ($profile['dosham'] ?? '') === 'பரிகார செவ்வாய்' ? 'selected' : ''; ?>>பரிகார செவ்வாய்</option>
                        <option value="சுத்த ஜாதகம்" <?php echo ($profile['dosham'] ?? '') === 'சுத்த ஜாதகம்' ? 'selected' : ''; ?>>சுத்த ஜாதகம்</option>
                    </select>
                </div>

                <!-- 13. Education select -->
                <div class="col-md-6 mb-3">
                    <label for="education_select" class="form-label">படிப்பு(Education)</label>
                    <select class="form-select" id="education_select" name="education_select">
                        <option value="">-- தேர்வு செய்க --</option>
                        <?php
                        $educations = ['10th, 12th','Degree UG/PG'];
                        foreach ($educations as $e) {
                            $label = $e === 'Degree UG/PG' ? 'Degree UG/PG' : $e;
                            $sel = ($profile['education_type'] === $e) ? 'selected' : '';
                            echo "<option value=\"".htmlspecialchars($e)."\" $sel>".htmlspecialchars($label)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 14. Education text -->
                <div class="col-md-6 mb-3">
                    <label for="education_text" class="form-label">படிப்பு பிரிவு</label>
                    <input type="text" class="form-control" id="education_text" name="education_text" value="<?php echo htmlspecialchars($profile['education_details'] ?? ''); ?>" placeholder="படித்த பட்டதை எழுதுக, உதா: BE, PHD">
                </div>

                <!-- 15. Profession and 16-17. District & City -->
                <div class="col-md-6 mb-3">
                    <label for="profession" class="form-label">வேலை (Profession)</label>
                    <input type="text" class="form-control" id="profession" name="profession" value="<?php echo htmlspecialchars($profile['profession'] ?? ''); ?>" placeholder="உதா: ஆசிரியர், பொறியாளர்">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="district" class="form-label">வசிக்கும் மாவட்டம்</label>
                    <select class="form-select" id="district" name="district">
                        <option value="">மாவட்டத்தைத் தேர்வு செய்க</option>
                        <?php foreach($districtsMap as $en => $ta): ?>
                            <option value="<?php echo htmlspecialchars($en); ?>" <?php echo ($profile['district'] === $en) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ta); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="city" class="form-label">வசிக்கும் ஊர்</label>
                    <input type="text" class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($profile['city'] ?? ''); ?>">
                </div>

                <!-- 18-21. Siblings -->
                <div class="col-12 mb-3">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="brothers_total" class="form-label">சகோதரர்கள்</label>
                            <select class="form-select" id="brothers_total" name="brothers_total">
                                <?php for ($i=0; $i<=5; $i++): $sel = (isset($profile['brothers_total']) && (int)$profile['brothers_total'] === $i) ? 'selected' : ''; ?>
                                    <option value="<?php echo $i; ?>" <?php echo $sel; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="sisters_total" class="form-label">சகோதரிகள்</label>
                            <select class="form-select" id="sisters_total" name="sisters_total">
                                <?php for ($i=0; $i<=5; $i++): $sel = (isset($profile['sisters_total']) && (int)$profile['sisters_total'] === $i) ? 'selected' : ''; ?>
                                    <option value="<?php echo $i; ?>" <?php echo $sel; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="phone3" class="form-label">குறிப்பு</label>
                            <input type="text" class="form-control" id="phone3" name="phone3" value="<?php echo htmlspecialchars($profile['phone_tertiary'] ?? ''); ?>" maxlength="30">
                        </div>
                    </div>
                </div>

                <!-- 22-24. Phones -->
                <?php if (getUserRole() !== 'manager'): ?>
                <div class="col-md-6 mb-3">
                    <label for="phone1" class="form-label">தொலைபேசி 1</label>
                    <input type="tel" class="form-control" id="phone1" name="phone1" value="<?php echo htmlspecialchars($profile['phone_primary'] ?? ''); ?>" maxlength="10">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="phone2" class="form-label">தொலைபேசி 2</label>
                    <input type="tel" class="form-control" id="phone2" name="phone2" value="<?php echo htmlspecialchars($profile['phone_secondary'] ?? ''); ?>" maxlength="10">
                </div>
                <?php endif; ?>

                <!-- 25. Profile photo -->
                <div class="col-md-6 mb-3">
                    <label for="profile_photo" class="form-label">Profile Photo</label>
                    <?php if ($profile['profile_photo']): ?>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($profile['profile_photo']); ?>" alt="Current Profile Photo" class="img-thumbnail" style="max-height: 100px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/*">
                    <small class="form-text text-muted">Leave empty to keep current photo</small>
                </div>

                <!-- 26. Supporting document -->
                <div class="col-md-6 mb-3">
                    <label for="supporting_doc" class="form-label">Supporting Document</label>
                    <?php if ($profile['file_upload']): ?>
                        <div class="mb-2">
                            <a href="<?php echo htmlspecialchars($profile['file_upload']); ?>" target="_blank">View Current Document</a>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="supporting_doc" name="supporting_doc">
                    <small class="form-text text-muted">Leave empty to keep current document</small>
                </div>
            
                <!-- 27. Notes (editable only in edit.php) -->
                <div class="col-12 mb-3">
                    <label for="notes" class="form-label">குறிப்பு விவரங்கள்</label>
                    <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="30" placeholder="குறிப்பு விவரங்களை உள்ளிடவும்"><?php echo htmlspecialchars($profile['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Update Profile</button>
            <a href="profiles.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Check if jQuery loaded
        if (typeof jQuery === 'undefined') {
            console.error('jQuery not loaded!');
            // Fallback: load from Google CDN
            document.write('<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"><\/script>');
        } else {
            console.log('jQuery loaded successfully, version:', jQuery.fn.jquery);
        }
    </script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script>
        // Check if jQuery UI loaded
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.datepicker === 'undefined') {
            console.error('jQuery UI not loaded!');
            // Fallback: load from Google CDN
            document.write('<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"><\/script>');
        } else {
            console.log('jQuery UI loaded successfully');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    </script>

    <script>
        // Form validation (this runs after datepicker is initialized)
        (function () {
            'use strict'
            
            // Phone number validation
            function validatePhone(input) {
                const value = input.value.trim();
                if (value && !/^[0-9]{10}$/.test(value)) {
                    input.setCustomValidity('Please enter a valid 10-digit phone number');
                } else {
                    input.setCustomValidity('');
                }
            }

            // Add phone validation to phone1 and phone2 only (not phone3 which is text)
            ['phone1', 'phone2'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', function() {
                        validatePhone(this);
                    });
                    input.addEventListener('blur', function() {
                        validatePhone(this);
                    });
                }
            });

            // Sibling count validation
            function validateSiblings() {
                const brothersTotalInput = document.getElementById('brothers_total');
                const brothersMarriedInput = document.getElementById('brothers_married');
                const sistersTotalInput = document.getElementById('sisters_total');
                const sistersMarriedInput = document.getElementById('sisters_married');

                if (!brothersTotalInput || !brothersMarriedInput || !sistersTotalInput || !sistersMarriedInput) {
                    return;
                }

                const brothersTotal = parseInt(brothersTotalInput.value) || 0;
                const brothersMarried = parseInt(brothersMarriedInput.value) || 0;
                const sistersTotal = parseInt(sistersTotalInput.value) || 0;
                const sistersMarried = parseInt(sistersMarriedInput.value) || 0;

                const brothersValid = brothersMarried <= brothersTotal;
                const sistersValid = sistersMarried <= sistersTotal;

                brothersMarriedInput.setCustomValidity(brothersValid ? '' : 'Married brothers cannot exceed total brothers');
                sistersMarriedInput.setCustomValidity(sistersValid ? '' : 'Married sisters cannot exceed total sisters');
            }

            // Add sibling validation event listeners
            ['brothers_total', 'brothers_married', 'sisters_total', 'sisters_married'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('change', validateSiblings);
                }
            });

            function parseBirthDate(value) {
                if (!value) return null;
                const normalized = value.trim().replace(/\//g, '-');
                const parts = normalized.split('-');
                if (parts.length !== 3) return null;

                let day;
                let month;
                let year;

                if (parts[0].length === 4) {
                    year = parseInt(parts[0], 10);
                    month = parseInt(parts[1], 10) - 1;
                    day = parseInt(parts[2], 10);
                } else {
                    day = parseInt(parts[0], 10);
                    month = parseInt(parts[1], 10) - 1;
                    year = parseInt(parts[2], 10);
                }

                if ([day, month, year].some(Number.isNaN)) return null;

                const birthDate = new Date(year, month, day);
                if (
                    Number.isNaN(birthDate.getTime()) ||
                    birthDate.getFullYear() !== year ||
                    birthDate.getMonth() !== month ||
                    birthDate.getDate() !== day
                ) {
                    return null;
                }

                return birthDate;
            }

            // Calculate age from date string
            function calculateAge(dateStr) {
                const birthDate = parseBirthDate(dateStr);
                if (!birthDate) return 0;
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                return age;
            }

            // Birth date validation and auto-update age select
            const birthDateInput = document.getElementById('birth_date');
            if (birthDateInput) {
                function computeAgeFromDateJS(value) {
                    const d = parseBirthDate(value);
                    if (!d || isNaN(d.getTime())) return null;
                    const today = new Date();
                    let age = today.getFullYear() - d.getFullYear();
                    const m = today.getMonth() - d.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < d.getDate())) {
                        age--;
                    }
                    return age;
                }

                function validateBirthDate() {
                    const selectedDate = this.value;
                    const ageInput = document.getElementById('age');
                    if (!selectedDate.trim()) {
                        this.setCustomValidity('');
                        if (ageInput) {
                            ageInput.value = '';
                        }
                        return;
                    }
                    const today = new Date();
                    const minDate = new Date();
                    minDate.setFullYear(today.getFullYear() - 70); // Max age 70 years
                    const maxDate = new Date();
                    maxDate.setFullYear(today.getFullYear() - 18); // Min age 18 years
                    const validDate = parseBirthDate(selectedDate);

                    if (!validDate || isNaN(validDate.getTime())) {
                        this.setCustomValidity('Please choose a valid birth date.');
                    } else if (validDate > maxDate) {
                        this.setCustomValidity('Age must be at least 18 years');
                    } else if (validDate < minDate) {
                        this.setCustomValidity('Age cannot exceed 70 years');
                    } else {
                        this.setCustomValidity('');
                    }

                    // Auto-update age when birth date changes
                    if (ageInput) {
                        const ageVal = computeAgeFromDateJS(this.value);
                        ageInput.value = ageVal !== null && !isNaN(ageVal) ? ageVal : '';
                    }
                }

                birthDateInput.addEventListener('input', validateBirthDate);
                birthDateInput.addEventListener('change', validateBirthDate);
                birthDateInput.addEventListener('blur', function() {
                    const value = this.value.trim();
                    if (value && !parseBirthDate(value)) {
                        this.setCustomValidity('Please enter a valid birth date.');
                    } else {
                        this.setCustomValidity('');
                    }
                });
                validateBirthDate.call(birthDateInput);
            }
            // File size validation
            const maxSize = <?php echo MAX_FILE_SIZE; ?>;
            function validateFileSize(input) {
                if (input.files.length > 0) {
                    if (input.files[0].size > maxSize) {
                        input.setCustomValidity(`File size must not exceed ${maxSize / 1024 / 1024}MB`);
                    } else {
                        input.setCustomValidity('');
                    }
                }
            }

            ['profile_photo', 'supporting_doc'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('change', function() {
                        validateFileSize(this);
                    });
                }
            });

            // Form submit validation
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        validateSiblings();
                        if (!form.checkValidity()) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false)
                })
        })();
    </script>
</body>
</html>
