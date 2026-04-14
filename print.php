<?php
require_once 'auth.php';
requireLogin();

// Allow super_admin, manager, and support roles
if (getUserRole() === null || (!in_array(getUserRole(), ['super_admin', 'admin', 'manager', 'customer', 'special_customer']))) {
    header('Location: access_denied.php');
    exit();
}
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: profiles.php');
    exit();
}

// Charge a credit for printing if applicable
$allowed = incrementProfileViews();
if ($allowed === false) {
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Limit Exceeded</title>\n<link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\"></head><body class=\"bg-light\">";
    echo "<div class='container mt-4'><div class='alert alert-danger'>Limit Exceeded</div><a href='profiles.php' class='btn btn-primary'>Back</a></div>";
    echo "</body></html>";
    exit();
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
$stmt->execute([$id]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: profiles.php');
    exit();
}

// Tamil District Names
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
<html lang="ta">
<head>
    <style>
        @media print {
            body, html {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
                background: #fff;
                font-size: 16px;
            }
            .container, .profile-container, .left-side, .right-side, .supporting-doc {
                box-sizing: border-box;
                width: 100%;
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
            .profile-container {
                display: flex;
                flex-direction: row;
                align-items: flex-start;
                page-break-inside: avoid;
            }
            .left-side {
                width: 30%;
                padding: 10px;
            }
            .right-side {
                width: 70%;
                padding: 10px;
            }
            .supporting-doc {
                margin-top: 10px;
                text-align: center;
                page-break-inside: avoid;
            }
            .supporting-doc img {
                max-width: 100%;
                max-height: 140mm;
                width: 100%;
                height: auto;
               
                margin: 10px auto;
                display: block;
            }
            .footer {
                position: absolute;
                bottom: 10mm;
                left: 8mm;
                right: 8mm;
                text-align: center;
                font-size: 11px;
                color: #888;
            }
            /* Hide print button or any non-print elements if present */
            .no-print { display: none !important; }
        }
    </style>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print Profile - Sun Matrimony</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* A4 single page */
@page { size: A4 portrait; margin: 3mm; }

html, body {
    height: 100%;
    margin: 0;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-family: 'Latha', sans-serif;
    font-size: 18px;
}

/* Don't force all elements to be bold (that increases layout size). Keep headings bold only. */
body { font-weight: 600; }

.header, .header p, .header img { font-weight: 700; }

.print-layout {
    width: 210mm;
    min-height: 290mm;
    box-sizing: border-box;
    margin: 0 auto;
    padding: 8mm;
    background: #fff;
    display: flex;
    flex-direction: column;
    overflow: visible;
    page-break-after: avoid;
    page-break-inside: avoid;
}

/* Header */
.header { text-align: center; margin-bottom: 6px; border-bottom: 2px solid #333; padding-bottom: 6px; }
.header p { margin: 0; font-size: 18px; }
.company-name { font-size: 28px; font-weight: bold; margin: 0 0 5px 0; }

/* Main content area */
.profile-container {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    flex: 0 0 auto;
}

/* Left side: two stacked images */
.left-side {
    width: 36%;
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: center;
}

/* Constrain combined heights so page stays single-sheet */
.left-side .photo-wrap {
    width: 100%;
    display: block;
    text-align: center;
}

/* Each photo sizing */
.left-side img {
    width: 90%;
    max-width: 100%;
    /* both images together should not exceed ~120mm */
    max-height: 100mm; /* reduce image heights to fit single page */
    height: auto;
    object-fit: cover;
   
    display: block;
}

/* Right side: details */
.right-side {
    width: 64%;
    font-size: 17px;
    padding-top: 0;
    padding-bottom: 0;
}

.right-side table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 6px;
}

.right-side td.phone-cell {
    font-size: 17px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.right-side th {
    text-align: left;
    width: 42%;
    padding: 5px 7px;
    background-color: #f8f9fa;
    border-radius: 4px 0 0 4px;
    vertical-align: top;
    font-size: 17px;
}

.right-side td {
    padding: 5px 7px;
    background-color: #fff;
    border-radius: 0 4px 4px 0;
    border-left: 2px solid #dee2e6;
    vertical-align: top;
    font-size: 17px;
}

/* Supporting Document large at bottom full width */
.supporting-doc {
    width: 100%;
    margin-top: 12px;
    text-align: center;
    padding-top: 10px;
    border-top: 1px dashed #ccc;
    page-break-inside: avoid;
    page-break-after: auto;
    break-inside: avoid;
    break-before: auto;
}

/* big heading */
.supporting-doc h3 {
    font-size: 22px;
    margin: 6px 0 10px;
}

/* Make supporting doc occupy wide area; limit height to keep single page */
.supporting-doc img {
    display: block;
    margin: 0 auto;
    width: 100%;
    max-width: 100%;
    max-height: 260mm;
    height: auto;
    object-fit: contain;
    
    page-break-inside: avoid;
}

/* Placeholder if no doc */
.supporting-doc .no-doc {
    display: inline-block;
    padding: 36px;
    
    max-width: 90%;
}

/* Footer */
.footer { text-align: center; font-size: 11px; color: #666; margin-top: 8px; }

/* Print adjustments */
@media print {
    .no-print { display: none; }
    html, body {
        width: 210mm;
        height: 297mm;
        font-size: 17px;
        margin: 0;
        padding: 0;
    }
    body { zoom: 0.98; }
    .print-layout {
        width: 204mm;
        min-height: auto;
        box-shadow: none;
        padding: 2.5mm 3.5mm;
        overflow: hidden;
    }
    .header {
        border-bottom-width: 1px;
        margin-bottom: 3px;
        padding-bottom: 3px;
    }
    .header p { font-size: 19px; }
    .profile-container { gap: 5px; margin-top: 2px; }
    .left-side { width: 31%; gap: 5px; }
    .right-side {
        width: 69%;
        font-size: 17px;
    }
    .right-side table { border-spacing: 0 2px; }
    .right-side th, .right-side td {
        padding: 3px 4px;
        font-size: 17px;
        line-height: 1.1;
    }
    .right-side td.phone-cell { font-size: 17px; }
    .left-side img { max-height: 48mm; }
    .supporting-doc {
        margin-top: 4px;
        padding-top: 3px;
        border-top: 1px dashed #bbb;
        break-before: auto;
        page-break-before: auto;
    }
    .supporting-doc h3 { margin: 0 0 2px; font-size: 18px; }
    .supporting-doc img {
        max-height: 105mm;
        width: 100%;
        max-width: 100%;
    }
    .footer {
        margin-top: 3px;
        font-size: 11px;
    }
}



/* Arunz code */

.phone-text {
    font-size: 17px;
    
}




</style>
</head>
<body>

<div class="no-print text-center mt-3" style="margin:8px;">
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
</div>

<div class="print-layout">
    <div class="header">
        <h2 class="company-name">Sun Matrimony</h2>
        <p>Profile ID: <strong><?php echo htmlspecialchars($profile['id']); ?></strong></p>
    </div>

    <div class="profile-container">
        <!-- Left: two photos stacked -->
        <div class="left-side">
            <div class="photo-wrap">
                <?php if (!empty($profile['profile_photo'])): ?>
                    <img src="<?php echo htmlspecialchars($profile['profile_photo']); ?>" alt="Profile Photo">
                <?php else: ?>
                    <div style="border:2px dashed #ffffffff; padding:20px; max-width:100%;"></div>
                <?php endif; ?>
            </div>

            <div class="photo-wrap">
                <?php
                // try multiple field names for second photo
                $secondPhoto = $profile['photo2'] ?? $profile['photo_secondary'] ?? $profile['other_photo'] ?? '';
                if (!empty($secondPhoto)): ?>
                    <img src="<?php echo htmlspecialchars($secondPhoto); ?>" alt="Second Photo">
                <?php else: ?>
                   
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: details -->
        <div class="right-side">
            <table>
                    <tr><th>திருமண வகை:</th><td>
                    <?php
                    $type = $profile['marriage_type'] ?? '';
                    if ($type === 'First') echo 'முதல்மணம்';
                    elseif ($type === 'Second') echo 'இரண்டாம் திருமணம்';
                    else echo htmlspecialchars($type);
                    ?>
                </td></tr>
                <tr><th>பெயர்:</th><td><?php echo htmlspecialchars($profile['name']); ?></td></tr>
<tr><th>பிறந்த தேதி:</th><td><?php
                    if (!empty($profile['birth_date'])) {
                        $d = DateTime::createFromFormat('Y-m-d', $profile['birth_date']);
                        echo $d ? $d->format('d-m-Y') : htmlspecialchars($profile['birth_date']);
                    }
                ?></td></tr>
                <tr><th>பிறந்த நேரம்:</th><td><?php echo htmlspecialchars($profile['birth_time']); ?></td></tr>

                <tr><th>பிறந்த ஊர்:</th><td><?php echo htmlspecialchars($profile['birth_place'] ?? ''); ?></td></tr>

                <?php /* Hidden: <tr><th>குறிப்பு விவரங்கள்:</th><td><?php echo nl2br(htmlspecialchars($profile["notes"] ?? "")); ?></td></tr> */ ?>

                <tr><th>படிப்பு பிரிவு:</th><td><?php
                    $educationType = trim($profile['education_type'] ?? '');
                    $educationDetails = trim($profile['education_details'] ?? '');
                    if ($educationType === '' && $educationDetails === '') {
                        echo '';
                    } elseif ($educationType !== '' && $educationDetails !== '') {
                        echo htmlspecialchars($educationType . ' (' . $educationDetails . ')');
                    } else {
                        echo htmlspecialchars($educationType !== '' ? $educationType : $educationDetails);
                    }
                ?></td></tr>

                <tr><th>ராசி:</th><td><?php echo htmlspecialchars($profile['rasi']); ?></td></tr>
                <tr><th>நட்சத்திரம்:</th><td><?php echo htmlspecialchars($profile['nakshatram']); ?></td></tr>
                
                
                
                <tr><th>சாதி:</th><td><?php
                    $casteDisplay = $profile['caste'] ?? '';
                    if (!empty($profile['subcaste'])) {
                        $casteDisplay .= ' / ' . $profile['subcaste'];
                    }
                    echo htmlspecialchars($casteDisplay);
                ?></td></tr>
                <tr><th>குலம் (கோத்திரம்):</th><td><?php echo htmlspecialchars($profile['kulam']); ?></td></tr>

                <tr><th>வேலை:</th><td><?php echo htmlspecialchars($profile['profession']); ?></td></tr>
                <tr><th>வசிக்கும் மாவட்டம்:</th><td>
<?php
    $districtEn = $profile['district'];
    $districtTa = '';
    foreach ($districtsMap as $en => $ta) {
        if (strcasecmp(trim($en), trim($districtEn)) === 0) {
            $districtTa = $ta;
            break;
        }
    }
    echo htmlspecialchars($districtTa ?: $districtEn);
?>
                </td></tr>

                <tr><th>வசிக்கும் ஊர்:</th><td><?php echo htmlspecialchars($profile['city']); ?></td></tr>
                
                
                
                <tr><th>சகோதரர்கள்:</th><td><?php echo htmlspecialchars($profile['brothers_total']); ?></td></tr>
                <tr><th>சகோதரிகள்:</th><td><?php echo htmlspecialchars($profile['sisters_total']); ?></td></tr>
                <tr><th>தொலைபேசி 1:</th><td class="phone-cell"><?php echo (getUserRole()==='manager') ? '' : htmlspecialchars($profile['phone_primary']); ?></td></tr>
                <tr><th>தொலைபேசி 2:</th><td class="phone-cell"><?php echo (getUserRole()==='manager') ? '' : htmlspecialchars($profile['phone_secondary']); ?></td></tr>
                <tr><th>குறிப்பு :</th><td><?php echo htmlspecialchars(mb_substr($profile['phone_tertiary'] ?? '', 0, 30)); ?></td></tr>
            </table>
            </table>
        </div>
    </div>

    <!-- Supporting Document: full width, big, at bottom -->
    <div class="supporting-doc">
        <h3></h3>
        <?php if (!empty($profile['file_upload'])): ?>
            <img src="<?php echo htmlspecialchars($profile['file_upload']); ?>" alt="Supporting Document">
        <?php else: ?>
            <div class="no-doc"></div>
        <?php endif; ?>
    </div>

    <div class="footer">Printed on <?php echo date('d F Y'); ?> — Made by Hifive web design</div>
</div>

</body>
</html>  


