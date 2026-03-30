<?php
require_once 'auth.php';

// Minimum permission required is customer
checkPermission('customer');

// Increment profile views for customer users (charge one credit if first time)
$allowed = incrementProfileViews();
if ($allowed === false) {
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Limit Exceeded</title>\n<link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\"></head><body class=\"bg-light\">";
    echo "<div class='container mt-4'><div class='alert alert-danger'>Limit Exceeded</div><a href='profiles.php' class='btn btn-primary'>Back</a></div>";
    echo "</body></html>";
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: profiles.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
$stmt->execute([$id]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: profiles.php');
    exit();
}

// Districts map for Tamil labels
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
    'Tiruvannamalai' => 'திருவண்ணามலை',
    'Tiruvarur' => 'திருவாரூர்',
    'Vellore' => 'வேலூர்',
    'Viluppuram' => 'விழுப்புரம்',
    'Virudhunagar' => 'விருதுநகர்'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Profile - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h2 class="mb-0">Profile Details</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <?php if ($profile['profile_photo']): ?>
                                    <img src="<?php echo htmlspecialchars($profile['profile_photo']); ?>" 
                                         alt="Profile Photo" 
                                         class="img-fluid rounded mb-3">
                                <?php endif; ?>
                            </div>
                            <div class="col-md-8">
                                <table class="table">
                                    <tr>
                                        <th>திருமண வகை:</th>
                                        <td><?php 
                                            $type = $profile['marriage_type'] ?? '';
                                            if ($type === 'First') echo 'முதல்மணம்';
                                            elseif ($type === 'Second') echo 'இரண்டாம் திருமணம்';
                                            else echo htmlspecialchars($type);
                                        ?></td>
                                    </tr>
                                    <tr>
                                        <th>பெயர்:</th>
                                        <td><?php echo htmlspecialchars($profile['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>பிறந்த தேதி:</th>
                                        <td>
                                            <?php
                                            if (!empty($profile['birth_date'])) {
                                                $d = DateTime::createFromFormat('Y-m-d', $profile['birth_date']);
                                                echo $d ? $d->format('d-m-Y') : htmlspecialchars($profile['birth_date']);
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>பிறந்த நேரம்:</th>
                                        <td><?php echo htmlspecialchars($profile['birth_time'] ?? ''); ?></td>
                                    </tr>

                                     <tr>
                                        <th>வயது:</th>
                                        <td><?php echo htmlspecialchars($profile['age']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>பிறந்த ஊர்:</th>
                                        <td><?php echo htmlspecialchars($profile['birth_place'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>ராசி:</th>
                                        <td><?php echo htmlspecialchars($profile['rasi'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>நட்சத்திரம்:</th>
                                        <td><?php echo htmlspecialchars($profile['nakshatram'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>சாதி:</th>
                                        <td>
                                            <?php
                                            $casteDisplay = $profile['caste'] ?? '';
                                            if (!empty($profile['subcaste'])) {
                                                $casteDisplay .= ' / ' . $profile['subcaste'];
                                            }
                                            echo htmlspecialchars($casteDisplay);
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>குலம் / கோத்திரம்:</th>
                                        <td><?php echo htmlspecialchars($profile['kulam'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>படிப்பு:</th>
                                        <td><?php echo htmlspecialchars($profile['education_type'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>படிப்பு பிரிவு:</th>
                                        <td><?php echo htmlspecialchars($profile['education_details'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>குறிப்பு விவரங்கள்:</th>
                                        <td><?php echo nl2br(htmlspecialchars($profile['notes'] ?? '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th>தொழில்:</th>
                                        <td><?php echo htmlspecialchars($profile['profession'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>மாவட்டம்:</th>
                                        <td><?php echo htmlspecialchars($districtsMap[$profile['district']] ?? $profile['district']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>ஊர்:</th>
                                        <td><?php echo htmlspecialchars($profile['city']); ?></td>
                                    </tr>
                                    
                                    <tr>
                                        <th>சகோதரர்கள் (மொத்தம்):</th>
                                        <td><?php echo htmlspecialchars($profile['brothers_total'] ?? '0'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>சகோதரர்கள் (திருமணமான):</th>
                                        <td><?php echo htmlspecialchars($profile['brothers_married'] ?? '0'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>சகோதரிகள் (மொத்தம்):</th>
                                        <td><?php echo htmlspecialchars($profile['sisters_total'] ?? '0'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>சகோதரிகள் (திருமணமான):</th>
                                        <td><?php echo htmlspecialchars($profile['sisters_married'] ?? '0'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>தொலைபேசி 1:</th>
                                        <td><?php
                                            $phoneVal = $profile['phone_primary'] ?? '';
if (!empty($phoneVal)) {
                                                if (getUserRole() === 'manager') {
                                                    echo '';
                                                } else {
                                                    echo htmlspecialchars($phoneVal);
                                                }
                                            }
                                        ?></td>
                                    </tr>
                                    <tr>
                                        <th>தொலைபேசி 2:</th>
                                        <td><?php
                                            $phoneVal = $profile['phone_secondary'] ?? '';
                                            if (!empty($phoneVal)) {
                                                if (getUserRole() === 'manager') {
                                                    $len = strlen($phoneVal);
                                                    if ($len > 5) {
                                                        echo str_repeat('#', $len - 5) . substr($phoneVal, -5);
                                                    } else {
                                                        echo $phoneVal;
                                                    }
                                                } else {
                                                    echo htmlspecialchars($phoneVal);
                                                }
                                            }
                                        ?></td>
                                    </tr>
                                    <tr>
                                        <th>குறிப்பு:</th>
                                        <td><?php echo htmlspecialchars($profile['phone_tertiary'] ?? ''); ?></td>
                                    </tr>
                                   
                                        
                                    <?php if ($profile['file_upload']): ?>
                                    <tr>
                                        <th>ஜாதகம்:</th>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($profile['file_upload']); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-primary">
                                                View Horoscope
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <a href="profiles.php" class="btn btn-secondary">Back to Profiles</a>
                            <?php if (!isCustomerRole()): ?>
                            <a href="edit.php?id=<?php echo $profile['id']; ?>" class="btn btn-warning">Edit Profile</a>
                            <?php endif; ?>
                            <a href="print.php?id=<?php echo $profile['id']; ?>" class="btn btn-info">Print Profile</a>
                            <?php if (isCustomerRole()): ?>
                            <div class="alert alert-info mt-3">
                                <?php
                                require_once 'db.php';
                                $user_id = $_SESSION['user_id'];
                                $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
                                $stmt->execute([$user_id]);
                                $credits = $stmt->fetchColumn();
                                $limit = getCustomerCreditLimit();
                                $credits = $credits === null ? $limit : (int)$credits;
                                $credits = min($credits, $limit);
                                echo "Profiles remaining: $credits/$limit";
                                ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

