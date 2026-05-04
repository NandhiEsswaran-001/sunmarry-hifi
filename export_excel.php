<?php
require_once 'auth.php';
requireLogin();

$role = getUserRole();
if (!in_array($role, ['super_admin', 'admin'])) {
    die('Access denied');
}

function escapeForExcel($value) {
    if ($value === null || $value === '') return '';
    $value = str_replace("\n", " ", $value);
    $value = str_replace("\r", "", $value);
    $value = str_replace("\t", " ", $value);
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function generateExcel($data, $filename = 'profiles.xlsx') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Profiles</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
    
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>ID</th><th>Name</th><th>Age</th><th>Gender</th><th>Caste</th><th>Nakshatram</th><th>Education</th><th>City</th><th>Phone</th>';
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>'.escapeForExcel($row['id']).'</td>';
        echo '<td>'.escapeForExcel($row['name'] ?? '').'</td>';
        echo '<td>'.escapeForExcel($row['age'] ?? '').'</td>';
        echo '<td>'.($row['gender'] === 'Male' ? 'Male' : 'Female').'</td>';
        echo '<td>'.escapeForExcel(($row['caste'] ?? '') . (!empty($row['subcaste']) ? ' / ' . $row['subcaste'] : '')).'</td>';
        echo '<td>'.escapeForExcel($row['nakshatram'] ?? '').'</td>';
        echo '<td>'.escapeForExcel($row['education_type'] ?? '').'</td>';
        echo '<td>'.escapeForExcel($row['city'] ?? '').'</td>';
        
        $phone = $row['phone_primary'] ?? '';
        if (empty($phone) && !empty($row['phone_secondary'])) {
            $phone = $row['phone_secondary'];
        }
        echo '<td>'.escapeForExcel($phone).'</td>';
        
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

try {
    $pdo = getDB();
    
    $colStmt = $pdo->query("SHOW COLUMNS FROM profiles");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('deleted_at', $colNames)) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    }
} catch (PDOException $e) {
}

$where = [];
$params = [];
$show_deleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] == '1';
if (!$show_deleted) {
    $where[] = 'deleted_at IS NULL';
}

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
$phone = $_GET['phone'] ?? '';
$name = $_GET['name'] ?? '';
$id_search = isset($_GET['id_search']) ? trim($_GET['id_search']) : '';

$role = getUserRole();
$isNormalCustomer = ($role === 'customer');
$isCustomerRole = isCustomerRole();

if ($isNormalCustomer) {
    $selectedDistricts = [];
    $selectedDosham = [];
}
if ($isCustomerRole) {
    $phone = '';
    $city = '';
    $name = '';
    $selectedNakshatram = [];
    $id_from = '';
    $id_to = '';
}

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

if ($age_from && $age_to) {
    $where[] = "age BETWEEN ? AND ?";
    $params[] = min($age_from, $age_to);
    $params[] = max($age_from, $age_to);
} elseif ($age_from) {
    $where[] = "age >= ?";
    $params[] = $age_from;
} elseif ($age_to) {
    $where[] = "age <= ?";
    $params[] = $age_to;
}

if ($gender) {
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
    if ($marriage_type === 'முதல்மணம்' || $marriage_type === 'First') {
        $where[] = "(marriage_type = 'முதல்மணம்' OR marriage_type = 'First' OR marriage_type IS NULL OR marriage_type = '')";
    } elseif ($marriage_type === 'மறுமணம்' || $marriage_type === 'Second') {
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

if (!empty($selectedNakshatram)) {
    $placeholders = str_repeat('?,', count($selectedNakshatram) - 1) . '?';
    $where[] = "nakshatram IN ($placeholders)";
    $params = array_merge($params, $selectedNakshatram);
}

if (!empty($selectedEducation)) {
    $placeholders = str_repeat('?,', count($selectedEducation) - 1) . '?';
    $where[] = "education_type IN ($placeholders)";
    $params = array_merge($params, $selectedEducation);
}

if (!empty($selectedDosham)) {
    $placeholders = str_repeat('?,', count($selectedDosham) - 1) . '?';
    $where[] = "dosham IN ($placeholders)";
    $params = array_merge($params, $selectedDosham);
}

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

$sql = "SELECT id, name, age, gender, caste, subcaste, nakshatram, education_type, city, phone_primary, phone_secondary FROM profiles";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'profiles_' . date('Y-m-d_His') . '.xls';
generateExcel($profiles, $filename);