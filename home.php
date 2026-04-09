<?php
require_once 'auth.php';
requireLogin();

// Ensure `dosham`, `education_details`, and `notes` columns exist in `profiles` table
try {
    $pdo = getDB();
    $colStmt = $pdo->query("SHOW COLUMNS FROM profiles");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('dosham', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN dosham VARCHAR(255) DEFAULT ''");
    }
    if (!in_array('education_details', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN education_details VARCHAR(500) DEFAULT ''");
    }
    if (!in_array('notes', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN notes TEXT DEFAULT ''");
    }
} catch (PDOException $e) {
    // ignore schema change errors
}

// If the logged-in user is a customer role, redirect them to profiles.php only.
if (isCustomerRole()) {
    header('Location: profiles.php');
    exit();
}

$message = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $age = isset($_POST['age']) ? (int)$_POST['age'] : 0;
    $marriage_type = $_POST['marriage_type'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $district = $_POST['district'] ?? '';
    $city = $_POST['city'] ?? '';
    $birth_place = trim($_POST['birth_place'] ?? ''); // New field
    // Birth date and time
    $original_birth_date = $_POST['birth_date'] ?? null;
    $birth_date = $_POST['birth_date'] ?? null; // input as DD-MM-YYYY, convert to YYYY-MM-DD
    if ($birth_date) {
        $dateObj = parseBirthDateInput($birth_date);
        if ($dateObj) {
            $birth_date = $dateObj->format('Y-m-d');
            // Calculate age from birth date
            $birthDateObj = new DateTime($birth_date);
            $today = new DateTime();
            $age = $today->diff($birthDateObj)->y;
        } else {
            $birth_date = null; // invalid format
            $age = 0;
        }
    } else {
        $age = 0;
    }
    $birth_hour = isset($_POST['birth_hour']) ? (int)$_POST['birth_hour'] : null;
    $birth_minute = isset($_POST['birth_minute']) ? (int)$_POST['birth_minute'] : null;
    $birth_ampm = $_POST['birth_ampm'] ?? '';
    $birth_time = null;
    if ($birth_hour !== null && $birth_minute !== null && in_array(strtoupper($birth_ampm), ['AM','PM'])) {
        $birth_time = sprintf('%02d:%02d %s', $birth_hour, $birth_minute, strtoupper($birth_ampm));
    }
    $caste = $_POST['caste'] ?? '';
    $kulam = trim($_POST['kulam'] ?? '');
    $nakshatram = $_POST['nakshatram'] ?? '';
    $rasi = $_POST['rasi'] ?? '';
    $education = $_POST['education_select'] ?? '';
    $education_text = trim($_POST['education_text'] ?? '');
    $dosham = $_POST['dosham'] ?? '';
    // Sibling fields
    $brothers_total = isset($_POST['brothers_total']) ? (int)$_POST['brothers_total'] : 0;
    $brothers_married = 0;
    $sisters_total = isset($_POST['sisters_total']) ? (int)$_POST['sisters_total'] : 0;
    $sisters_married = 0;
    // Profession and phone numbers
    $profession = trim($_POST['profession'] ?? '');
    $phone1 = trim($_POST['phone1'] ?? '');
    $phone2 = trim($_POST['phone2'] ?? '');
    $phone3 = trim($_POST['phone3'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    $allEmpty = empty($name) && empty($age) && empty($original_birth_date) && empty($birth_place) && 
                empty($caste) && empty($kulam) && empty($nakshatram) && empty($rasi) && 
                empty($education) && empty($education_text) && empty($profession) && 
                empty($district) && empty($city) && empty($phone1);
    
    if ($allEmpty) {
        $errors[] = 'தயவுசெய்து குறைந்தபட்சம் ஒரு விவரத்தை உள்ளிடவும்.';
    } else {
        if (empty($name)) {
            $errors[] = 'தயவுசெய்து பெயரை உள்ளிடவும்.';
        }
        if (empty($phone1)) {
            $errors[] = 'தயவுசெய்து தொலைபேசி 1 ஐ உள்ளிடவும்.';
        }
    }
    if ($phone1 !== '' && $phone2 !== '' && $phone1 === $phone2) {
        $errors[] = 'தொலைபேசி 2 (' . htmlspecialchars($phone2) . '), தொலைபேசி 1 (' . htmlspecialchars($phone1) . ') போல் இருக்கக் கூடாது.';
    }
    // Prevent duplicate profiles (by primary phone or by name + birth date)
    try {
        $hasDeletedAt = false;
        try {
            $hasDeletedAt = (bool)$pdo->query("SHOW COLUMNS FROM profiles LIKE 'deleted_at'")->fetch();
        } catch (PDOException $e) {
            $hasDeletedAt = false;
        }

        $dupParts = [];
        $dupParams = [];
        if ($phone1 !== '') {
            $dupParts[] = "(phone_primary = ? OR phone_secondary = ? OR phone_tertiary = ?)";
            $dupParams[] = $phone1;
            $dupParams[] = $phone1;
            $dupParams[] = $phone1;
        }
        if ($phone2 !== '') {
            $dupParts[] = "(phone_primary = ? OR phone_secondary = ? OR phone_tertiary = ?)";
            $dupParams[] = $phone2;
            $dupParams[] = $phone2;
            $dupParams[] = $phone2;
        }
        if ($name !== '' && !empty($birth_date)) {
            $dupParts[] = "(name = ? AND birth_date = ?)";
            $dupParams[] = $name;
            $dupParams[] = $birth_date;
        }

        if (!empty($dupParts)) {
            $dupSql = "SELECT id FROM profiles WHERE (" . implode(" OR ", $dupParts) . ")";
            if ($hasDeletedAt) {
                $dupSql .= " AND deleted_at IS NULL";
            }
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupRow = $dupStmt->fetch()) {
                $existingPhone = '';
                if ($phone1 !== '') {
                    $checkStmt = $pdo->prepare("SELECT phone_primary, phone_secondary, phone_tertiary FROM profiles WHERE phone_primary = ? OR phone_secondary = ? OR phone_tertiary = ?");
                    $checkStmt->execute([$phone1, $phone1, $phone1]);
                    if ($row = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($row['phone_primary'] == $phone1) $existingPhone = $phone1;
                        elseif ($row['phone_secondary'] == $phone1) $existingPhone = $phone1;
                        elseif ($row['phone_tertiary'] == $phone1) $existingPhone = $phone1;
                    }
                }
                if ($phone2 !== '' && empty($existingPhone)) {
                    $checkStmt = $pdo->prepare("SELECT phone_primary, phone_secondary, phone_tertiary FROM profiles WHERE phone_primary = ? OR phone_secondary = ? OR phone_tertiary = ?");
                    $checkStmt->execute([$phone2, $phone2, $phone2]);
                    if ($row = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($row['phone_primary'] == $phone2) $existingPhone = $phone2;
                        elseif ($row['phone_secondary'] == $phone2) $existingPhone = $phone2;
                        elseif ($row['phone_tertiary'] == $phone2) $existingPhone = $phone2;
                    }
                }
                if (!empty($existingPhone)) {
                    $errors[] = 'தொலைபேசி எண் (' . htmlspecialchars($existingPhone) . ') ஏற்கனவே உள்ளது. தயவுசெய்து வேறு தொலைபேசி எண்ணை பயன்படுத்தவும்.';
                } else {
                    $errors[] = 'ஏற்கனவே இதே விவரத்துடன் சுயவிவரம் உள்ளது. தயவுசெய்து புதிய விவரங்களை உள்ளிடவும்.';
                }
            }
        }
    } catch (PDOException $e) {
        // ignore duplicate check errors
    }

    // Handle file uploads
    $profile_photo = $_FILES['profile_photo'] ?? null;
    $supporting_doc = $_FILES['supporting_doc'] ?? null;

    if (!empty($errors)) {
        $message = $errors[0];
    } else {
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $profile_photo_path = '';
    $supporting_doc_path = '';

    // Upload profile photo
    if ($profile_photo && $profile_photo['error'] === UPLOAD_ERR_OK) {
        $profile_photo_name = uniqid() . '_' . basename($profile_photo['name']);
        if (move_uploaded_file($profile_photo['tmp_name'], $uploadDir . $profile_photo_name)) {
            $profile_photo_path = 'uploads/' . $profile_photo_name;
        }
    }

    // Upload supporting document
    if ($supporting_doc && $supporting_doc['error'] === UPLOAD_ERR_OK) {
        $supporting_doc_name = uniqid() . '_' . basename($supporting_doc['name']);
        if (move_uploaded_file($supporting_doc['tmp_name'], $uploadDir . $supporting_doc_name)) {
            $supporting_doc_path = 'uploads/' . $supporting_doc_name;
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO profiles (name, age, marriage_type, gender, district, city, birth_place, birth_date, birth_time, caste, kulam, nakshatram, rasi, education_type, education_details, dosham, brothers_total, brothers_married, sisters_total, sisters_married, profession, phone_primary, phone_secondary, phone_tertiary, notes, profile_photo, file_upload)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            $education,
            $education_text,
            $dosham,
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
            $supporting_doc_path
        ]);

        $message = "சுயவிவரம் வெற்றிகரமாக உருவாக்கப்பட்டது!";
    } catch (PDOException $e) {
        $message = "Error creating profile: " . $e->getMessage();
    }
    }
}

// Tamil Nadu districts array
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
    'Tenkasi' => 'தென்கசி',
    'Tirupathur' => 'திருப்பதூர்',
    'Pondicherry' => 'புதுச்சேரி'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>சுயவிவரம் உருவாக்கு - திருமண பதிவு அமைப்பு</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h2>சுயவிவரம் உருவாக்கு</h2>
        <?php if ($message): ?>
            <?php $isSuccess = strpos($message, 'வெற்றிகரமாக') !== false; ?>
            <div class="alert <?php echo $isSuccess ? 'alert-success' : 'alert-danger'; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
            <div class="row">
                <!-- 1. Marriage type -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">திருமண வகை</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="marriage_type" id="first" value="First" checked>
                            <label class="form-check-label" for="first">முதல்மணம்</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="marriage_type" id="second" value="Second">
                            <label class="form-check-label" for="second">இரண்டாம் திருமணம்</label>
                        </div>
                    </div>
                </div>

                <!-- 2. Gender -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">பாலினம்</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gender" id="female" value="Female" checked>
                            <label class="form-check-label" for="female">பெண்</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gender" id="male" value="Male">
                            <label class="form-check-label" for="male">ஆண்</label>
                        </div>
                    </div>
                </div>

                <!-- 3. Name -->
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label">பெயர்</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                </div>

                <!-- 4. Birth date -->
                <div class="col-md-6 mb-3">
                    <label for="birth_date" class="form-label">பிறந்த தேதி (நாள்)</label>
                    <input type="date" class="form-control" id="birth_date" name="birth_date" value="<?php echo isset($original_birth_date) ? htmlspecialchars($original_birth_date) : ''; ?>">
                </div>

                <!-- 5. Age (computed) -->
                <div class="col-md-6 mb-3">
                    <label for="age" class="form-label">வயது</label>
                    <input type="number" class="form-control" id="age" name="age" value="<?php echo isset($age) ? (int)$age : 0; ?>" readonly required>
                </div>

                <!-- 6. Birth time -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">பிறந்த நேரம்</label>
                    <div class="d-flex g-2">
                        <select class="form-select me-2" id="birth_hour" name="birth_hour" style="max-width:110px;">
                            <option value="">HH</option>
                            <?php for ($h = 1; $h <= 12; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php echo (isset($birth_hour) && (int)$birth_hour === $h) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $h); ?></option>
                            <?php endfor; ?>
                        </select>

                        <select class="form-select me-2" id="birth_minute" name="birth_minute" style="max-width:110px;">
                            <option value="">MM</option>
                            <?php for ($m = 0; $m <= 59; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo (isset($birth_minute) && (int)$birth_minute === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
                            <?php endfor; ?>
                        </select>

                        <select class="form-select" id="birth_ampm" name="birth_ampm" style="max-width:120px;">
                            <option value="">AM/PM</option>
                            <option value="AM" <?php echo (isset($birth_ampm) && strtoupper($birth_ampm) === 'AM') ? 'selected' : ''; ?>>AM</option>
                            <option value="PM" <?php echo (isset($birth_ampm) && strtoupper($birth_ampm) === 'PM') ? 'selected' : ''; ?>>PM</option>
                        </select>
                    </div>
                </div>

                <!-- 7. Birth place -->
                <div class="col-md-6 mb-3">
                    <label for="birth_place" class="form-label">பிறந்த ஊர்</label>
                    <input type="text" class="form-control" id="birth_place" name="birth_place" placeholder="பிறந்த ஊர்" value="<?php echo isset($birth_place) ? htmlspecialchars($birth_place) : ''; ?>">
                </div>

                <!-- 8. Rasi -->
                <div class="col-md-6 mb-3">
                    <label for="rasi" class="form-label">ராசி (Rasi)</label>
                    <select class="form-select" id="rasi" name="rasi">
                        <option value="">-- தேர்வு செய்க --</option>
                        <?php
                        $rasis = ['மேஷம்','ரிஷபம்','மிதுனம்','கடகம்','சிம்மம்','கன்னி','துலாம்','விருச்சிகம்','தனுசு','மகரம்','கும்பம்','மீனம்'];
                        foreach ($rasis as $r) {
                            echo "<option value=\"".htmlspecialchars($r)."\">".htmlspecialchars($r)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 9. Nakshatram -->
                <div class="col-md-6 mb-3">
                    <label for="nakshatram" class="form-label">நட்சத்திரம்(Nakshatram)</label>
                    <select class="form-select" id="nakshatram" name="nakshatram">
                        <option value="">-- தேர்வு செய்க --</option>
                        <option value="அஸ்வினி">அஸ்வினி</option>
                        <option value="பரணி">பரணி</option>
                        <option value="கிருத்திகை">கிருத்திகை</option>
                        <option value="ரோஹிணி">ரோஹிணி</option>
                        <option value="மிருகசீரிடம்">மிருகசீரிடம்</option>
                        <option value="திருவாதிரை">திருவாதிரை</option>
                        <option value="புனர்பூசம்">புனர்பூசம்</option>
                        <option value="பூசம்">பூசம்</option>
                        <option value="ஆயில்யம்">ஆயில்யம்</option>
                        <option value="மகம்">மகம்</option>
                        <option value="பூரம்">பூரம்</option>
                        <option value="உத்திரம்">உத்திரம்</option>
                        <option value="ஹஸ்தம்">ஹஸ்தம்</option>
                        <option value="சித்திரை">சித்திரை</option>
                        <option value="சுவாதி">சுவாதி</option>
                        <option value="விசாகம்">விசாகம்</option>
                        <option value="அனுஷம்">அனுஷம்</option>
                        <option value="கேட்டை">கேட்டை</option>
                        <option value="மூலம்">மூலம்</option>
                        <option value="பூராடம்">பூராடம்</option>
                        <option value="உத்திராடம்">உத்திராடம்</option>
                        <option value="திருவோணம்">திருவோணம்</option>
                        <option value="அவிட்டம்">அவிட்டம்</option>
                        <option value="சதயம்">சதயம்</option>
                        <option value="பூரட்டாதி">பூரட்டாதி</option>
                        <option value="உத்திரட்டாதி">உத்திரட்டாதி</option>
                        <option value="ரேவதி">ரேவதி</option>
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
                            echo "<option value=\"".htmlspecialchars($c)."\">".htmlspecialchars($c)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- 11. Kulam -->
                <div class="col-md-6 mb-3">
                    <label for="kulam" class="form-label">குலம் (கோத்திரம்)</label>
                    <input type="text" class="form-control" id="kulam" name="kulam" placeholder="குலம் / கோத்திரம்">
                </div>

                <!-- 12. Dosham -->
                <div class="col-md-6 mb-3">
                    <label for="dosham" class="form-label">தோசம் (Dosham)</label>
                    <select class="form-select" id="dosham" name="dosham">
                        <option value="">-- தேர்வு செய்க --</option>
                        <option value="ராகு கேது">ராகு கேது</option>
                        <option value="பரிகார செவ்வாய்">பரிகார செவ்வாய்</option>
                        <option value="சுத்த ஜாதகம்">சுத்த ஜாதகம்</option>
                    </select>
                </div>

                <!-- 13. Education select -->
                <div class="col-md-6 mb-3">
                    <label for="education_select" class="form-label">படிப்பு(Education)</label>
                    <select class="form-select" id="education_select" name="education_select">
                        <option value="">-- தேர்வு செய்க --</option>
                        <option value="10 ஆம் வகுப்பு, 12 ஆம் வகுப்பு, ஐ.டி.ஐ, டிப்ளமோ">10 ஆம் வகுப்பு, 12 ஆம் வகுப்பு, ஐ.டி.ஐ, டிப்ளமோ</option>
                        <option value="இளங்கலை (UG)">இளங்கலை (UG)</option>
                        <option value="முதுகலை (PG)">முதுகலை (PG)</option>
                    </select>
                </div>

                <!-- 14. Education text -->
                <div class="col-md-6 mb-3">
                    <label for="education_text" class="form-label">படிப்பு பிரிவு</label>
                    <input type="text" class="form-control" id="education_text" name="education_text" placeholder="படித்த பட்டதை எழுதுக, உதா: BE, PHD">
                </div>

                <!-- 15. Profession -->
                <div class="col-md-4 mb-3">
                    <label for="profession" class="form-label">தொழில் (Profession)</label>
                    <input type="text" class="form-control" id="profession" name="profession" placeholder="உதா: ஆசிரியர், பொறியாளர்">
                </div>

                <!-- 16. District -->
                <div class="col-md-4 mb-3">
                    <label for="district" class="form-label">வசிக்கும் மாவட்டம்</label>
                    <select class="form-select" id="district" name="district">
                        <option value="">மாவட்டத்தைத் தேர்வு செய்க</option>
                        <?php foreach($districtsMap as $en => $ta): ?>
                            <option value="<?php echo htmlspecialchars($en); ?>"><?php echo htmlspecialchars($ta); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 17. City -->
                <div class="col-md-4 mb-3">
                    <label for="city" class="form-label">வசிக்கும் ஊர்</label>
                    <input type="text" class="form-control" id="city" name="city">
                </div>

                <!-- 18-21. Siblings -->
                <div class="col-12 mb-3">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="brothers_total" class="form-label">சகோதரர்கள்</label>
                            <select class="form-select" id="brothers_total" name="brothers_total">
                                <?php for ($i=0; $i<=5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i===0 ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="sisters_total" class="form-label">சகோதரிகள்</label>
                            <select class="form-select" id="sisters_total" name="sisters_total">
                                <?php for ($i=0; $i<=5; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 22-24. Phones -->
                <div class="col-md-4 mb-3">
                    <label for="phone1" class="form-label">தொலைபேசி 1 *</label>
                    <input type="tel" class="form-control" id="phone1" name="phone1" placeholder="+91" required maxlength="10">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="phone2" class="form-label">தொலைபேசி 2</label>
                    <input type="tel" class="form-control" id="phone2" name="phone2" placeholder="(optional)" maxlength="10">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="phone3" class="form-label">குறிப்பு</label>
                    <input type="text" class="form-control" id="phone3" name="phone3" placeholder="(optional)" maxlength="30">
                </div>

                <!-- Notes -->
                <div class="col-12 mb-3">
                    <label for="notes" class="form-label">குறிப்பு விவரங்கள்</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="30" placeholder="குறிப்பு விவரங்களை உள்ளிடவும்"></textarea>
                </div>

                <!-- 25. Profile photo -->
                <div class="col-md-6 mb-3">
                    <label for="profile_photo" class="form-label">சுயவிவர புகைப்படம்</label>
                    <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/*">
                </div>

                <!-- 26. Supporting doc -->
                <div class="col-md-6 mb-3">
                    <label for="supporting_doc" class="form-label">ஜாதகம்</label>
                    <input type="file" class="form-control" id="supporting_doc" name="supporting_doc">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">சுயவிவரத்தைச் சமர்ப்பி</button>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function parseBirthDate(birthDateStr) {
            if (!birthDateStr) return null;
            const normalized = birthDateStr.trim().replace(/\//g, '-');
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

        // Auto-calculate age from birth_date
        function calculateAge(birthDateStr) {
            const birthDate = parseBirthDate(birthDateStr);
            if (!birthDate) return '';
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const m = today.getMonth() - birthDate.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            return age;
        }
        document.addEventListener('DOMContentLoaded', function() {
            const birthDateInput = document.getElementById('birth_date');
            const ageInput = document.getElementById('age');
            const phone1Input = document.getElementById('phone1');
            const phone2Input = document.getElementById('phone2');
            function updateAge() {
                if (!birthDateInput.value.trim()) {
                    ageInput.value = 0;
                    birthDateInput.setCustomValidity('');
                    return;
                }
                const age = calculateAge(birthDateInput.value);
                ageInput.value = age > 0 ? age : 0;
                birthDateInput.setCustomValidity(age > 0 ? '' : 'Please choose a valid birth date.');
            }
            function validatePhones() {
                if (!phone1Input || !phone2Input) return;
                const p1 = phone1Input.value.trim();
                const p2 = phone2Input.value.trim();
                if (p1 && p2 && p1 === p2) {
                    phone2Input.setCustomValidity('தொலைபேசி 2, தொலைபேசி 1 போல இருக்கக் கூடாது.');
                } else {
                    phone2Input.setCustomValidity('');
                }
            }
            if (birthDateInput) {
                birthDateInput.addEventListener('input', updateAge);
                updateAge();

                const today = new Date();
                const minDate = new Date();
                minDate.setFullYear(today.getFullYear() - 70);
                const maxDate = new Date();
                maxDate.setFullYear(today.getFullYear() - 18);

                birthDateInput.min = minDate.toISOString().split('T')[0];
                birthDateInput.max = maxDate.toISOString().split('T')[0];
            }
            if (phone1Input && phone2Input) {
                phone1Input.addEventListener('input', validatePhones);
                phone2Input.addEventListener('input', validatePhones);
                validatePhones();
            }
        });
        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
    <!-- Subcaste mapping removed (subcaste is no longer a separate field) -->
</body>
</html>
