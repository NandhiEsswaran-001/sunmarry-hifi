<?php
require_once 'auth.php';
requireLogin();

// Ensure `deleted_at` column exists in `profiles` to avoid SQL errors when filtering
try {
    $pdo = getDB();
    $colStmt = $pdo->query("SHOW COLUMNS FROM profiles");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('deleted_at', $colNames)) {
        // Add nullable deleted_at column for soft deletes
        $pdo->exec("ALTER TABLE profiles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    }
    if (!in_array('dosham', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN dosham VARCHAR(255) DEFAULT ''");
    }
} catch (PDOException $e) {
    // If the table doesn't exist or user lacks privileges, continue so the page can still load where possible.
}

// Initialize filter variables
$sort_id = isset($_GET['sort_id']) ? $_GET['sort_id'] : 'asc';
$id_from = isset($_GET['id_from']) ? trim($_GET['id_from']) : '';
$id_to = isset($_GET['id_to']) ? trim($_GET['id_to']) : '';
$age_from = $_GET['age_from'] ?? '';
$age_to = $_GET['age_to'] ?? '';
$gender = $_GET['gender'] ?? 'Female';
$selectedDistricts = $_GET['districts'] ?? [];
$city = $_GET['city'] ?? '';
$marriage_type = $_GET['marriage_type'] ?? 'முதல்மணம்';
$selectedCastes = isset($_GET['castes']) ? (array)$_GET['castes'] : [];
$selectedNakshatram = isset($_GET['nakshatram']) ? (array)$_GET['nakshatram'] : [];
$selectedEducation = isset($_GET['education']) ? (array)$_GET['education'] : [];
$selectedDosham = isset($_GET['dosham']) ? (array)$_GET['dosham'] : [];
$phone = $_GET['phone'] ?? ''; // mobile search (visible to super_admin & manager only in UI)
$name = $_GET['name'] ?? '';
$id_search = isset($_GET['id_search']) ? trim($_GET['id_search']) : '';
$hasSearched = isset($_GET['searched']) && $_GET['searched'] === '1';

$role = getUserRole();
$isNormalCustomer = ($role === 'customer');
$isSpecialCustomer = ($role === 'special_customer');
$isCustomerRole = isCustomerRole();

// Enforce role-based search permissions
if ($isNormalCustomer) {
    $selectedDistricts = [];
    $selectedDosham = [];
}
if ($isCustomerRole) {
    // Customers don't use admin-only filters
    $phone = '';
    $city = '';
    $name = '';
    $selectedNakshatram = [];
    $id_from = '';
    $id_to = '';
}

// Define caste and subcaste relationships
// Subcaste mapping removed (subcaste is no longer treated as a separate searchable field)

// Castes and their subcastes are defined in select elements and JavaScript

$nakshatramOptions = [
    'அஸ்வினி', 'பரணி', 'கிருத்திகை', 'ரோஹிணி', 'மிருகசீரிடம்', 'திருவாதிரை', 'புனர்பூசம்', 'பூசம்', 'ஆயில்யம்', 'மகம்', 'பூரம்', 'உத்திரம்', 'ஹஸ்தம்', 'சித்திரை', 'சுவாதி', 'விசாகம்', 'அனுஷம்', 'கேட்டை', 'மூலம்', 'பூராடம்', 'உத்திராடம்', 'திருவோணம்', 'அவிட்டம்', 'சதயம்', 'பூரட்டாதி', 'உத்திரட்டாதி', 'ரேவதி'
];

$doshamOptions = [
    'ராகு கேது',
    'பரிகார செவ்வாய்',
    'சுத்த ஜாதகம்'
];

$educationOptions = [
    '10th, 12th',
    'Degree UG/PG'
];

// Initialize where/params and by default exclude deleted profiles unless explicitly requested
$where = [];
$params = [];
$show_deleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] == '1';
if (!$show_deleted) {
    $where[] = 'deleted_at IS NULL';
}

// Handle ID search (exact) or range
if ($id_search !== '') {
    $where[] = "id = ?";
    $params[] = $id_search;
} else {
    if ($id_from !== '' && $id_to !== '') {
        $where[] = "id BETWEEN ? AND ?";
        $params[] = min($id_from, $id_to);
        $params[] = max($id_from, $id_to);
    } elseif ($id_from !== '') {
        $where[] = "id >= ?";
        $params[] = $id_from;
    } elseif ($id_to !== '') {
        $where[] = "id <= ?";
        $params[] = $id_to;
    }
}

// Handle age range filter
if ($age_from && $age_to) {
    $where[] = "age BETWEEN ? AND ?";
    $params[] = min($age_from, $age_to); // Use min to handle if user selects "to" less than "from"
    $params[] = max($age_from, $age_to);
} elseif ($age_from) {
    $where[] = "age >= ?";
    $params[] = $age_from;
} elseif ($age_to) {
    $where[] = "age <= ?";
    $params[] = $age_to;
}

if ($gender) {
    // Also include profiles with NULL or empty gender to handle edge cases
    $where[] = "(gender = ? OR gender IS NULL OR gender = '')";
    $params[] = $gender;
}
if (!empty($selectedDistricts)) {
    $placeholders = str_repeat('?,', count($selectedDistricts) - 1) . '?';
    $where[] = "district IN ($placeholders)";
    $params = array_merge($params, $selectedDistricts);
}
if ($city) {
    $where[] = "city LIKE ?";
    $params[] = "%$city%";
}

if ($marriage_type) {
    // Handle both Tamil and English values for backwards compatibility
    // Also handle case where filter has English but DB has Tamil or vice versa
    // Also include profiles with NULL or empty marriage_type to handle edge cases
    if ($marriage_type === 'முதல்மணம்') {
        $where[] = "(marriage_type = 'முதல்மணம்' OR marriage_type = 'First' OR marriage_type IS NULL OR marriage_type = '')";
    } elseif ($marriage_type === 'மறுமணம்') {
        $where[] = "(marriage_type = 'மறுமணம்' OR marriage_type = 'Second' OR marriage_type IS NULL OR marriage_type = '')";
    } elseif ($marriage_type === 'First') {
        $where[] = "(marriage_type = 'முதல்மணம்' OR marriage_type = 'First' OR marriage_type IS NULL OR marriage_type = '')";
    } elseif ($marriage_type === 'Second') {
        $where[] = "(marriage_type = 'மறுமணம்' OR marriage_type = 'Second' OR marriage_type IS NULL OR marriage_type = '')";
    } else {
        $where[] = "marriage_type = ?";
        $params[] = $marriage_type;
    }
}

    if (!empty($selectedCastes)) {
        $placeholders = str_repeat('?,', count($selectedCastes) - 1) . '?';
        $where[] = "caste IN ($placeholders)";
        $params = array_merge($params, $selectedCastes);
    }

// subcaste filter removed

if (!empty($selectedNakshatram)) {
    $placeholders = str_repeat('?,', count($selectedNakshatram) - 1) . '?';
    $where[] = "nakshatram IN ($placeholders)";
    $params = array_merge($params, $selectedNakshatram);
}

if (!empty($selectedEducation)) {
    // stored column name is education_type in the database
    $placeholders = str_repeat('?,', count($selectedEducation) - 1) . '?';
    $where[] = "education_type IN ($placeholders)";
    $params = array_merge($params, $selectedEducation);
}

if (!empty($selectedDosham)) {
    $placeholders = str_repeat('?,', count($selectedDosham) - 1) . '?';
    $where[] = "dosham IN ($placeholders)";
    $params = array_merge($params, $selectedDosham);
}

// Mobile / phone search (matches primary, secondary or tertiary phones)
if (!empty($phone)) {
    $where[] = "(phone_primary LIKE ? OR phone_secondary LIKE ? OR phone_tertiary LIKE ?)";
    $like = "%$phone%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($name) {
    $where[] = "name LIKE ?";
    $params[] = "%$name%";
}

// Build the query
// Sorting
$orderBy = '';
if ($sort_id === 'asc') {
    $orderBy = ' ORDER BY id ASC';
} elseif ($sort_id === 'desc') {
    $orderBy = ' ORDER BY id DESC';
}

// Pagination defaults
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalRecords = 0;
$totalPages = 0;
$profiles = [];

if ($hasSearched) {
    $sql = "SELECT * FROM profiles";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= $orderBy;

    // Count total records for pagination
    $countSql = "SELECT COUNT(*) FROM profiles" . (!empty($where) ? " WHERE " . implode(" AND ", $where) : "");
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();
    $totalPages = (int)ceil($totalRecords / $perPage);

    // Get paginated results
    $sql .= " LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $profiles = $stmt->fetchAll();
}

// Tamil Nadu districts map: value => label (English => Tamil)
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
    'Pondicherry' => 'புதுச்சேரி',
    'Kerala' => 'கேரளா',
    'Karnataka' => 'கர்நாடகா',
    'Andhra/Telangana' => 'ஆந்திரா / தெலங்கானா'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>View Profiles - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        body {
            overflow-x: hidden;
        }
        .container {
            max-width: 100%;
            overflow-x: hidden;
        }
        .dropdown-menu {
            max-height: 300px;
            overflow-y: auto;
            padding: 0;
        }
        .dropdown-item {
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .dropdown-item.active {
            background-color: #e9ecef;
            color: #000;
        }
        .dropdown-item input[type="checkbox"] {
            margin: 0;
        }
        .district-dropdown {
            width: 100%;
        }
        .district-dropdown button {
            width: 100%;
            text-align: left;
            position: relative;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 25px;
            font-size: 0.85rem;
            line-height: 1.2;
            min-height: 38px;
        }
        .district-dropdown button::after {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }
        .search-box {
            position: sticky;
            top: 0;
            background-color: white;
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
            z-index: 1;
        }
        .search-box button {
            padding: 4px 8px;
            font-size: 0.875rem;
        }
        .search-box button:focus {
            box-shadow: none;
        }
        #selectedCount {
            font-size: 0.875rem;
            color: #6c757d;
            margin-left: 8px;
        }
        
        /* Table styles */
        .table {
            font-size: 14px;
        }
        .table th {
            background-color: #f8f9fa;
            vertical-align: middle;
        }
        .table th a {
            display: inline-flex;
            align-items: center;
        }
        .table td {
            vertical-align: middle;
        }
        .table .btn-sm {
             margin: 10px;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        /* Mobile responsive table - Card layout */
        @media (max-width: 768px) {
            .mobile-table-wrapper {
                border: 0;
                width: 100%;
                padding: 0;
            }
            .mobile-table-wrapper table,
            .mobile-table-wrapper thead,
            .mobile-table-wrapper tbody,
            .mobile-table-wrapper tr,
            .mobile-table-wrapper th {
                display: block;
                width: 100%;
            }
            .mobile-table-wrapper thead {
                display: none;
            }
            .mobile-table-wrapper tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 10px;
                background: #fff;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            .mobile-table-wrapper td {
                display: block;
                padding: 10px 15px;
                border: none;
                text-align: left;
                background: #fff !important;
            }
            .mobile-table-wrapper td[data-label="படம்"] {
                padding: 15px;
                text-align: left;
                background: #fff !important;
            }
            .mobile-table-wrapper td[data-label="படம்"] img {
                width: 120px;
                height: 120px;
                border-radius: 10px;
                object-fit: cover;
                border: 2px solid #007bff;
            }
            .mobile-table-wrapper td[data-label=""] {
                padding: 8px 15px;
                background: #fff !important;
            }
            .mobile-table-wrapper td[data-label="ID"] {
                display: inline-block;
                width: auto;
                padding: 5px 12px;
                background: #fff !important;
                border: 1px solid #ddd;
                border-radius: 15px;
                font-size: 13px;
                font-weight: 600;
            }
            .mobile-table-wrapper td[data-label="பெயர்"] {
                font-size: 20px;
                font-weight: 700;
                color: #333;
                padding: 10px 15px;
            }
            .mobile-table-wrapper td[data-label="வயது"],
            .mobile-table-wrapper td[data-label="பாலினம்"] {
                display: inline-block;
                width: auto;
                padding: 6px 12px;
                background: #fff !important;
                font-size: 14px;
            }
            .mobile-table-wrapper td[data-label="மாவட்டம்"],
            .mobile-table-wrapper td[data-label="சாதி"] {
                display: inline-block;
                width: auto;
                padding: 6px 12px;
                margin-right: 8px;
                margin-bottom: 5px;
                background: #fff !important;
                border: 1px solid #ddd;
                border-radius: 15px;
                font-size: 14px;
            }
            .mobile-table-wrapper td[data-label="நட்சத்திரம்"],
            .mobile-table-wrapper td[data-label="படிப்பு வகை"] {
                display: inline-block;
                width: auto;
                padding: 6px 12px;
                margin-right: 8px;
                margin-bottom: 5px;
                background: #fff !important;
                border: 1px solid #007bff;
                border-radius: 15px;
                font-size: 14px;
                color: #007bff;
            }
            .mobile-table-wrapper td[data-label="ஊர்"],
            .mobile-table-wrapper td[data-label="வேலை"] {
                display: block;
                width: 100%;
                padding: 10px 15px;
                border-top: 1px solid #eee;
                background: #fff !important;
                font-size: 14px;
            }
            .mobile-table-wrapper td::before {
                display: none;
            }
            .mobile-table-wrapper td:has(img) {
                text-align: left;
            }
            .table .btn-sm {
                padding: 8px 12px;
                font-size: 13px;
                margin: 3px;
            }
            .mobile-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                padding: 15px !important;
                background: #fff !important;
                border-top: 1px solid #eee;
            }
            .mobile-actions .btn {
                text-align: center;
                font-size: 12px;
                padding: 10px 5px;
            }
        }

    </style>
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>சுயவிவரங்களை காண்</h2>
            <?php if (isCustomerRole()):
                $pdo = getDB();
                $user_id = $_SESSION['user_id'];
                $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $credits = $stmt->fetchColumn();
                $limit = getCustomerCreditLimit();
                $credits = $credits === null ? $limit : (int)$credits;
                $credits = min($credits, $limit);
            ?>
                <div class="alert alert-info mb-0">
                    Profiles remaining: <strong><?php echo $credits; ?>/<?php echo $limit; ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            சுயவிவரம் நீக்கப்பட்டு Recycle Bin நகர்த்தப்பட்டது.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filter Form - All Users -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="searched" value="1">
                    <!-- Row 1: திருமண வகை / பாலினம்/ வயது -->
                    <div class="col-md-4">
                        <label class="form-label">திருமண வகை</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="marriage_type" id="first_marriage" value="முதல்மணம்" <?php echo (empty($marriage_type) || $marriage_type === 'முதல்மணம்' || $marriage_type === 'First') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="first_marriage">முதல்மணம்</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="marriage_type" id="second_marriage" value="மறுமணம்" <?php echo ($marriage_type === 'மறுமணம்' || $marriage_type === 'Second') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="second_marriage">மறுமணம்</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">பாலினம்</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="gender" id="female" value="Female" <?php echo ($gender === 'Female') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="female">பெண்</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="gender" id="male" value="Male" <?php echo $gender === 'Male' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="male">ஆண்</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">வயது</label>
                        <div class="d-flex gap-2 align-items-center">
                            <select class="form-select" id="age_from" name="age_from">
                                <option value="">முதல்</option>
                                <?php for($i = 18; $i <= 65; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo isset($_GET['age_from']) && $_GET['age_from'] == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <span>வரை</span>
                            <select class="form-select" id="age_to" name="age_to">
                                <option value=""></option>
                                <?php for($i = 18; $i <= 65; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo isset($_GET['age_to']) && $_GET['age_to'] == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: சாதி பெயர் (Caste)/ படிப்பு (Education)/ வசிக்கும் மாவட்டங்கள் -->
                    <div class="col-md-4">
                        <label class="form-label">சாதி பெயர் (Caste)</label>
                        <div class="district-dropdown dropdown">
                            <button class="btn btn-light border dropdown-toggle" type="button" id="casteDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                சாதி தேர்வு
                                <span id="selectedCasteCount"></span>
                            </button>
                            <div class="dropdown-menu w-100" aria-labelledby="casteDropdown">
                                <div class="search-box">
                                    <input type="text" class="form-control form-control-sm" id="casteSearch" placeholder="Search caste...">
                                    <div class="d-flex justify-content-between mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllCastes">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllCastes">Clear All</button>
                                    </div>
                                </div>
                                <?php 
                                $casteOptions = [
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
                                $selectedCastes = isset($_GET['castes']) ? (array)$_GET['castes'] : [];
                                foreach($casteOptions as $casteOpt): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="castes[]" value="<?php echo htmlspecialchars($casteOpt); ?>" <?php echo in_array($casteOpt, $selectedCastes) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($casteOpt); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">படிப்பு (Education)</label>
                        <div class="district-dropdown dropdown">
                            <button class="btn btn-light border dropdown-toggle" type="button" id="educationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                படிப்பு தேர்வு
                                <span id="selectedEducationCount"></span>
                            </button>
                            <div class="dropdown-menu w-100" aria-labelledby="educationDropdown">
                                <div class="search-box">
                                    <input type="text" class="form-control form-control-sm" id="educationSearch" placeholder="Search education...">
                                    <div class="d-flex justify-content-between mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllEducation">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllEducation">Clear All</button>
                                    </div>
                                </div>
                                <?php 
                                $selectedEducation = isset($_GET['education']) ? (array)$_GET['education'] : [];
                                foreach($educationOptions as $option): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="education[]" value="<?php echo htmlspecialchars($option); ?>" <?php echo in_array($option, $selectedEducation) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($option); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isNormalCustomer): ?>
                    <div class="col-md-4">
                        <label class="form-label">வசிக்கும் மாவட்டங்கள்</label>
                        <div class="district-dropdown dropdown">
                            <button class="btn btn-light border dropdown-toggle" type="button" id="districtDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                மாவட்டம் தேர்வு
                                <span id="selectedCount"></span>
                            </button>
                            <div class="dropdown-menu w-100" aria-labelledby="districtDropdown">
                                <div class="search-box">
                                    <input type="text" class="form-control form-control-sm" id="districtSearch" placeholder="Search districts...">
                                    <div class="d-flex justify-content-between mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllDistricts">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllDistricts">Clear All</button>
                                    </div>
                                </div>
                                <?php foreach($districtsMap as $en => $ta): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="districts[]" value="<?php echo htmlspecialchars($en); ?>" 
                                           <?php echo (isset($selectedDistricts) && in_array($en, (array)$selectedDistricts)) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($ta); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager', 'special_customer'])): ?>
                    <!-- Row 3: Dosham -->
                    <div class="col-md-4">
                        <label class="form-label">தோசம் (Dosham)</label>
                        <div class="district-dropdown dropdown">
                            <button class="btn btn-light border dropdown-toggle" type="button" id="doshamDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                தோசம் தேர்வு
                                <span id="selectedDoshamCount"></span>
                            </button>
                            <div class="dropdown-menu w-100" aria-labelledby="doshamDropdown">
                                <div class="search-box">
                                    <input type="text" class="form-control form-control-sm" id="doshamSearch" placeholder="Search dosham...">
                                    <div class="d-flex justify-content-between mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllDosham">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllDosham">Clear All</button>
                                    </div>
                                </div>
                                <?php 
                                $selectedDosham = isset($_GET['dosham']) ? (array)$_GET['dosham'] : [];
                                foreach($doshamOptions as $option): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="dosham[]" value="<?php echo htmlspecialchars($option); ?>" <?php echo in_array($option, $selectedDosham) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($option); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager'])): ?>
                    <div class="col-md-4">
                        <label class="form-label">நட்சத்திரம்</label>
                        <div class="district-dropdown dropdown">
                            <button class="btn btn-light border dropdown-toggle" type="button" id="nakshatramDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                நட்சத்திரம் தேர்வு
                                <span id="selectedNakshatramCount"></span>
                            </button>
                            <div class="dropdown-menu w-100" aria-labelledby="nakshatramDropdown">
                                <div class="search-box">
                                    <input type="text" class="form-control form-control-sm" id="nakshatramSearch" placeholder="Search nakshatram...">
                                    <div class="d-flex justify-content-between mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllNakshatram">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllNakshatram">Clear All</button>
                                    </div>
                                </div>
                                <?php 
                                $selectedNakshatram = isset($_GET['nakshatram']) ? (array)$_GET['nakshatram'] : [];
                                foreach($nakshatramOptions as $option): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="nakshatram[]" value="<?php echo htmlspecialchars($option); ?>" <?php echo in_array($option, $selectedNakshatram) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($option); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!isCustomerRole()): ?>
                    <div class="col-md-4">
                        <label for="city" class="form-label">வசிக்கும் ஊர்</label>
                        <input type="text" class="form-control" id="city" name="city" 
                               value="<?php echo htmlspecialchars($city); ?>" 
                               placeholder="உங்கள் ஊரின் பெயரை உள்ளிடவும்">
                    </div>

                    <!-- Row 4: பெயர்/ ID முதல் ID வரை / போன் - Admin & Manager Only -->
                    <div class="col-md-4">
                        <label for="name" class="form-label">பெயர்</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($name); ?>" 
                               placeholder="பெயர் மூலம் தேடல்">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">ID முதல் வரை</label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="number" class="form-control" id="id_from" name="id_from" 
                                   value="<?php echo htmlspecialchars($id_from); ?>" 
                                   placeholder="முதல்">
                            <span>to</span>
                            <input type="number" class="form-control" id="id_to" name="id_to" 
                                   value="<?php echo htmlspecialchars($id_to); ?>" 
                                   placeholder="வரை">
                        </div>
                    </div>
                    <?php endif; ?>

<?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager'])): ?>
                    <div class="col-md-4">
                        <label for="phone" class="form-label">போன்</label>
                        <input type="text" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($phone); ?>" 
                               placeholder="Search mobile..."
                               maxlength="10" pattern="\d{10}" 
                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10)">
                    </div>
                    <?php endif; ?>

                    <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager', 'customer', 'special_customer'])): ?>
                    <div class="col-md-4">
                        <label class="form-label">ID</label>
                        <input type="number" class="form-control" id="id_search" name="id_search"
                               value="<?php echo htmlspecialchars($id_search); ?>"
                               placeholder="ID">
                    </div>
                    <?php endif; ?>

                    <!-- Submit Button Row -->
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">தேடல்</button>
                        <a href="profiles.php" class="btn btn-secondary">அனைத்தும் அழி</a>
                    </div>
                </form>
            </div>
        </div>
        <!-- End of filter form -->
        <div class="mobile-table-wrapper">
            <?php if (!$hasSearched): ?>
                <div class="alert alert-info">தேடல் பொத்தானை அழுத்திய பிறகு மட்டுமே சுயவிவரங்கள் காண்பிக்கப்படும்.</div>
            <?php else: ?>
            <?php
                // Calculate displayed range for the results
                $resultsOnPage = count($profiles);
                $startResult = $totalRecords > 0 ? ($offset + 1) : 0;
                $endResult = $totalRecords > 0 ? ($offset + $resultsOnPage) : 0;
            ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <strong style="font-size: 1.2rem;">மொத்த வரன்கள்:</strong>
                        <?php if ($totalRecords > 0): ?>
                            <span style="font-size: 1.2rem; font-weight:bold;"><?php echo htmlspecialchars($totalRecords); ?></span>
                        <?php else: ?>
                            <span style="font-size: 1.2rem; font-weight:bold;">0</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($totalRecords > 0 && in_array(getUserRole(), ['super_admin', 'admin'])): ?>
                        <?php 
                        $exportParams = $_GET;
                        $exportParams['export'] = 'excel';
                        $exportUrl = 'export_excel.php?' . http_build_query($exportParams);
                        ?>
                        <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="btn btn-success btn-sm" target="_blank">
                            Excel Export (<?php echo $totalRecords; ?>)
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <small class="text-muted">Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars(max(1, $totalPages)); ?></small>
                    <div class="mt-1">
                        <a href="?sort_id=desc<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['sort_id' => ''])) : ''; ?>" title="Newest first" style="text-decoration:none; vertical-align:middle; margin-right:15px;">
                            <span style="font-size:2.8em; font-weight:bold; color:#007bff;">&#8595;</span>
                        </a>
                        <a href="?sort_id=asc<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['sort_id' => ''])) : ''; ?>" title="Oldest first" style="text-decoration:none; vertical-align:middle;">
                            <span style="font-size:2.8em; font-weight:bold; color:#dc3545;">&#8593;</span>
                        </a>
                    </div>
                </div>
            </div>
            <!-- Bulk delete form -->
            <form method="POST" action="delete.php" id="bulkDeleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <table class="table table-striped table-hover" style="--bs-table-striped-bg: #fff;">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <?php if (!isCustomerRole()): ?>
                                <input type="checkbox" id="selectAllProfiles" title="Select all">
                            <?php endif; ?>
                        </th>
                        <th>
                            ID
                            <a href="?sort_id=desc<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['sort_id' => ''])) : ''; ?>" title="Newest first" style="text-decoration:none; vertical-align:middle; margin-left:8px;">
                                <span style="font-size:2.8em; font-weight:bold; color:#007bff;">&#8595;</span>
                            </a>
                            <a href="?sort_id=asc<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['sort_id' => ''])) : ''; ?>" title="Oldest first" style="text-decoration:none; vertical-align:middle; margin-left:6px;">
                                <span style="font-size:2.8em; font-weight:bold; color:#dc3545;">&#8593;</span>
                            </a>
                        </th>
                        <th>படம்</th>
                        <th>பெயர்</th>
                        <th>வயது</th>
                        <th>பாலினம்</th>
                        <th>மாவட்டம்</th>
                        <th>சாதி</th>
                        <th>நட்சத்திரம் </th>
                        <th>படிப்பு வகை</th>
                        <th>ஊர்</th>
                        <th>வேலை</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($profiles as $profile): ?>
                    <tr>
                        <td data-label="">
                            <?php if (!isCustomerRole()): ?>
                                <input type="checkbox" class="profileCheckbox" name="ids[]" value="<?php echo $profile['id']; ?>">
                            <?php endif; ?>
                        </td>
                        <td data-label="ID"><?php echo htmlspecialchars($profile['id']); ?></td>
                        <td data-label="படம்">
                            <?php if (!empty($profile['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($profile['profile_photo']); ?>" alt="Profile Photo" style="width: 90px; height: 90px; object-fit: contain; border-radius: 8px; border: none;">
                            <?php else: ?>
                                <div style="width: 90px; height: 90px; background: #e9ecef; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 40px;">
                                    <span>👤</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="பெயர்"><?php echo htmlspecialchars($profile['name']); ?></td>
                        <td data-label="வயது"><?php echo htmlspecialchars($profile['age']); ?></td>
                        <td data-label="பாலினம்"><?php echo htmlspecialchars($profile['gender']) === 'Male' ? 'ஆண்' : 'பெண்'; ?></td>
                        <td data-label="மாவட்டம்"><?php echo htmlspecialchars($districtsMap[$profile['district']] ?? $profile['district']); ?></td>
                        <td data-label="சாதி"><?php echo htmlspecialchars(($profile['caste'] ?? '') . (!empty($profile['subcaste']) ? ' / ' . $profile['subcaste'] : '')); ?></td>
                        <td data-label="நட்சத்திரம்"><?php echo htmlspecialchars($profile['nakshatram'] ?? ''); ?></td>
                        <td data-label="படிப்பு வகை"><?php echo htmlspecialchars($profile['education_type'] ?? ''); ?></td>
                        <td data-label="ஊர்"><?php echo htmlspecialchars($profile['city']); ?></td>
                        <td data-label="வேலை" class="mobile-actions">
                            <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager', 'customer', 'special_customer'])): ?>
                                <a href="print2.php?id=<?php echo $profile['id']; ?>" class="btn btn-sm btn-outline-dark">பார் (No Number)</a>
                            <?php endif; ?>
                            <a href="view.php?id=<?php echo $profile['id']; ?>" class="btn btn-sm btn-info">பார் (Number)</a>
                            <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'manager'])): ?>
                                <a href="edit.php?id=<?php echo $profile['id']; ?>" class="btn btn-sm btn-warning">திருத்து</a>
                            <?php endif; ?>
                            <?php if (in_array(getUserRole(), ['super_admin', 'admin', 'customer', 'special_customer'])): ?>
                                <a href="print.php?id=<?php echo $profile['id']; ?>" class="btn btn-sm btn-success">பிரிண்ட்</a>
                            <?php endif; ?>
                            <?php if (in_array(getUserRole(), ['super_admin', 'admin'])): ?>
                                <!-- Delete form: uses POST and a JS confirmation to avoid accidental deletes -->
                                <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this profile? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="id" value="<?php echo $profile['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">அழி</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
                </form>
                <?php if (in_array(getUserRole(), ['super_admin', 'admin'])): ?>
                    <div class="mb-3">
                        <button id="deleteSelectedBtn" class="btn btn-danger" disabled>Delete selected</button>
                    </div>
                <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
<nav aria-label="Page navigation" class="mt-5" style="overflow-x: auto; max-width: 100%;">
            <ul class="pagination pagination-lg justify-content-center mb-0" style="flex-wrap: wrap;">
                <?php for($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Bulk select / delete behavior
        $(function(){
            var selectAll = $('#selectAllProfiles');
            var checkboxes = $('.profileCheckbox');
            var deleteBtn = $('#deleteSelectedBtn');

            selectAll.on('change', function(){
                checkboxes.prop('checked', this.checked);
                deleteBtn.prop('disabled', checkboxes.filter(':checked').length === 0);
            });

            $(document).on('change', '.profileCheckbox', function(){
                var total = checkboxes.length;
                var checked = checkboxes.filter(':checked').length;
                selectAll.prop('checked', total > 0 && checked === total);
                deleteBtn.prop('disabled', checked === 0);
            });

            $('#deleteSelectedBtn').on('click', function(e){
                e.preventDefault();
                if (!confirm('Are you sure you want to delete selected profiles? This cannot be undone.')) return;
                // submit the form
                $('#bulkDeleteForm').submit();
            });
        });
    </script>
    <script>
        // Subcaste mapping removed (no dynamic subcaste population)
        $(document).ready(function() {
            // Select All / Clear All logic for all multi-select dropdowns
            function setupSelectAllClearAll(selectAllId, clearAllId, inputName) {
                // Scope the action to the dropdown menu containing the clicked button when possible.
                $(document).on('click', selectAllId, function(e) {
                    var $menu = $(this).closest('.dropdown-menu');
                    if ($menu.length) {
                        $menu.find('input[name="' + inputName + '"]').prop('checked', true);
                    } else {
                        $('input[name="' + inputName + '"]').prop('checked', true);
                    }
                });
                $(document).on('click', clearAllId, function(e) {
                    var $menu = $(this).closest('.dropdown-menu');
                    if ($menu.length) {
                        $menu.find('input[name="' + inputName + '"]').prop('checked', false);
                    } else {
                        $('input[name="' + inputName + '"]').prop('checked', false);
                    }
                });
            }

            setupSelectAllClearAll('#selectAllDistricts', '#clearAllDistricts', 'districts[]');
            setupSelectAllClearAll('#selectAllCastes', '#clearAllCastes', 'castes[]');
            setupSelectAllClearAll('#selectAllNakshatram', '#clearAllNakshatram', 'nakshatram[]');
            setupSelectAllClearAll('#selectAllReligion', '#clearAllReligion', 'religion[]');
            setupSelectAllClearAll('#selectAllEducation', '#clearAllEducation', 'education[]');
            setupSelectAllClearAll('#selectAllDosham', '#clearAllDosham', 'dosham[]');

            // Update selected values on dropdown buttons
            function updateDropdownValues(inputName, spanId, useLabelText = false) {
                var $checkboxes = $('input[name="' + inputName + '"]:checked');
                var values;
                if (useLabelText) {
                    values = $checkboxes.map(function() { return $(this).siblings('span').text() || $(this).closest('label').text().trim(); }).get();
                } else {
                    values = $checkboxes.map(function() { return $(this).val(); }).get();
                }
                var $span = $('#' + spanId);
                if (values.length > 0) {
                    $span.text(' (' + values.join(', ') + ')');
                } else {
                    $span.text('');
                }
            }

            // Bind change events for each checkbox group
            $(document).on('change', 'input[name="castes[]"]', function() { updateDropdownValues('castes[]', 'selectedCasteCount'); });
            $(document).on('change', 'input[name="districts[]"]', function() { updateDropdownValues('districts[]', 'selectedCount', true); });
            $(document).on('change', 'input[name="education[]"]', function() { updateDropdownValues('education[]', 'selectedEducationCount'); });
            $(document).on('change', 'input[name="nakshatram[]"]', function() { updateDropdownValues('nakshatram[]', 'selectedNakshatramCount'); });
            $(document).on('change', 'input[name="dosham[]"]', function() { updateDropdownValues('dosham[]', 'selectedDoshamCount'); });

            // Update on page load based on existing selections
            updateDropdownValues('castes[]', 'selectedCasteCount');
            updateDropdownValues('districts[]', 'selectedCount', true);
            updateDropdownValues('education[]', 'selectedEducationCount');
            updateDropdownValues('nakshatram[]', 'selectedNakshatramCount');
            updateDropdownValues('dosham[]', 'selectedDoshamCount');

            // ...existing code...
            // Remove double-click/double-enter protection and related styles if not needed
        });
    </script>

    <style>
        .submit-message {
            font-size: 0.9rem;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .pagination-lg .page-link {
            font-size: 1.3rem !important;
            padding: 0.6rem 0.9rem !important;
            min-width: 2.8rem !important;
        }
    </style>
</body>
</html>
