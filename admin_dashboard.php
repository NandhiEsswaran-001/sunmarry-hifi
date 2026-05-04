<?php
require_once 'db.php';
require_once 'auth.php';

try {
    ensureRegistrationRequestsTable($pdo);
} catch (PDOException $e) {
    // Keep dashboard usable even if the requests table cannot be created yet.
}

// Ensure only super admin and admin can access
$role = getUserRole();
if (!in_array($role, ['super_admin', 'admin'])) {
    header('Location: access_denied.php');
    exit();
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for all POST requests
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('Invalid request');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_registration_reviewed') {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    if ($requestId > 0) {
        $stmt = $pdo->prepare("UPDATE registration_requests SET status = 'reviewed' WHERE id = ?");
        $stmt->execute([$requestId]);
    }

    header('Location: admin_dashboard.php#registration-requests');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_registration_request') {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    if ($requestId > 0) {
        $stmt = $pdo->prepare("DELETE FROM registration_requests WHERE id = ?");
        $stmt->execute([$requestId]);
    }

    header('Location: admin_dashboard.php#registration-requests');
    exit();
}

// Get statistics
$stats = [
    'total_profiles' => $pdo->query("SELECT COUNT(*) FROM profiles")->fetchColumn(),
    'total_admins' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'total_managers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn(),
    'total_customer' => $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('customer','special_customer','support')")->fetchColumn(),
    'active_profiles' => $pdo->query("SELECT COUNT(*) FROM profiles WHERE deleted_at IS NULL")->fetchColumn(),
    'registration_requests' => $pdo->query("SELECT COUNT(*) FROM registration_requests")->fetchColumn(),
    'new_registration_requests' => $pdo->query("SELECT COUNT(*) FROM registration_requests WHERE status = 'new'")->fetchColumn()
];

// Check which columns exist in the users table
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    
    $hasPhoneCol = in_array('phone', $colNames);
    $hasCreatedAtCol = in_array('created_at', $colNames);
    $hasProfilesViewedCol = in_array('profiles_viewed', $colNames);
    $hasNoteCol = in_array('note', $colNames);
    
    // Auto-add missing columns
    if (!$hasPhoneCol) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
            $hasPhoneCol = true;
        } catch (Exception $e) {
            // Column might already exist or other error
        }
    }
    
    if (!$hasCreatedAtCol) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            $hasCreatedAtCol = true;
        } catch (Exception $e) {
            // Column might already exist or other error
        }
    }
    
    if (!$hasProfilesViewedCol) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN profiles_viewed INT DEFAULT 0");
            $hasProfilesViewedCol = true;
        } catch (Exception $e) {
            // Column might already exist or other error
        }
    }
    if (!$hasNoteCol) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN note TEXT DEFAULT NULL");
            $hasNoteCol = true;
        } catch (Exception $e) {
            // Column might already exist or other error
        }
    }
} catch (Exception $e) {
    $hasPhoneCol = false;
    $hasCreatedAtCol = false;
    $hasProfilesViewedCol = false;
    $hasNoteCol = false;
}

// Handle search
$searchName = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$searchPhone = isset($_GET['search_phone']) ? trim($_GET['search_phone']) : '';

// Pagination
$perPage = 25;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// Build a dynamic query that includes all available columns
$sql = "SELECT id, username, role, last_login";
if ($hasProfilesViewedCol) {
    $sql .= ", profiles_viewed";
}
if ($hasPhoneCol) {
    $sql .= ", phone";
} else {
    $sql .= ", NULL as phone";
}
if ($hasNoteCol) {
    $sql .= ", note";
} else {
    $sql .= ", NULL as note";
}
if ($hasCreatedAtCol) {
    $sql .= ", created_at";
} else {
    $sql .= ", NULL as created_at";
}
$sql .= " FROM users WHERE role != 'super_admin'";
$params = [];
if ($searchName !== '') {
    $sql .= " AND username LIKE :search_name";
    $params[':search_name'] = "%$searchName%";
}
if ($searchPhone !== '') {
    $sql .= " AND phone LIKE :search_phone";
    $params[':search_phone'] = "%$searchPhone%";
}
$sql .= " ORDER BY FIELD(role, 'admin', 'manager', 'special_customer', 'customer', 'support'), created_at DESC";

// Get total count for pagination
$countSql = str_replace("SELECT id, username, role, last_login", "SELECT COUNT(*)", $sql);
$countSql = preg_replace('/ORDER BY.*/', '', $countSql);
$totalStmt = !empty($params) ? $pdo->prepare($countSql) : $pdo->prepare($countSql);
if (!empty($params)) {
    $totalStmt->execute($params);
} else {
    $totalStmt->execute();
}
$totalUsers = (int)$totalStmt->fetchColumn();
$totalPages = (int)ceil($totalUsers / $perPage);

// Add LIMIT and OFFSET
$sql .= " LIMIT $perPage OFFSET $offset";

// Get all admin users except super admin, with search
if (!empty($params)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} else {
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll();
}

$registrationRequests = $pdo->query(
    "SELECT id, name, phone, alternate_phone, marriage_type, caste, birth_date, city, education, status, created_at
     FROM registration_requests
     ORDER BY created_at DESC, id DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo (getUserRole() === 'admin') ? 'Admin Dashboard' : 'Super Admin Dashboard'; ?> - Marriage Profile System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>
    
    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-12">
                <h2 class="mb-4"><?php echo (getUserRole() === 'admin') ? 'Admin Dashboard' : 'Super Admin Dashboard'; ?></h2>
                
<?php if ((int)$stats['new_registration_requests'] > 0): ?>
                    <div class="alert alert-warning d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <strong>Reminder:</strong>
                            You have <?php echo (int)$stats['new_registration_requests']; ?> new registration request<?php echo ((int)$stats['new_registration_requests'] === 1) ? '' : 's'; ?> waiting for review.
                        </div>
                        <a href="#registration-requests" class="btn btn-dark btn-sm">View Requests</a>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success']) && $_GET['success'] === 'password_reset' && isset($_SESSION['reset_password'])): ?>
                    <div class="alert alert-success">
                        <strong>Password Reset Successful</strong><br>
                        New password for <?php echo htmlspecialchars($_SESSION['reset_username'] ?? 'user'); ?>: <code><?php echo htmlspecialchars($_SESSION['reset_password']); ?></code><br>
                        <small class="text-muted">Please share this password securely with the user.</small>
                    </div>
                    <?php unset($_SESSION['reset_password'], $_SESSION['reset_username']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Profiles</h5>
                                <h2><?php echo $stats['total_profiles']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Active Profiles</h5>
                                <h2><?php echo $stats['active_profiles']; ?></h2>
                            </div>
                        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card bg-dark text-white">
            <div class="card-body">
                <h5 class="card-title">Admins</h5>
                <h2><?php echo $stats['total_admins']; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">Managers</h5>
                <h2><?php echo $stats['total_managers']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h5 class="card-title">Customers</h5>
                                <h2><?php echo $stats['total_customer']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Registrations</h5>
                                <h2><?php echo $stats['registration_requests']; ?></h2>
                                <small>New: <?php echo (int)$stats['new_registration_requests']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Form -->
                <form class="row g-3 mb-4" method="get" action="">
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search_name" placeholder="Search by Name" value="<?php echo htmlspecialchars($searchName); ?>">
                    </div>
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search_phone" placeholder="Search by Mobile Number" value="<?php echo htmlspecialchars($searchPhone); ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="admin_dashboard.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>

<!-- User Management -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">User Management</h5>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            Add New User
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <?php if ($hasCreatedAtCol): ?><th>Created Date</th><?php endif; ?>
                                        <th>Last Login</th>
                                        <?php if ($hasProfilesViewedCol): ?><th>Profiles Viewed</th><?php endif; ?>
                                        <th>Mobile Number</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><span class="badge bg-<?php echo $user['role'] === 'manager' ? 'info' : ($user['role'] === 'admin' ? 'dark' : ($user['role'] === 'special_customer' ? 'secondary' : 'warning')); ?>">
                                        <?php
                                            $roleLabel = $user['role'];
                                            if ($user['role'] === 'special_customer') $roleLabel = 'Special Customer';
                                            elseif ($user['role'] === 'support') $roleLabel = 'Customer';
                                            echo htmlspecialchars($roleLabel);
                                        ?>
                                    </span></td>
                                    <?php if ($hasCreatedAtCol): ?><td><?php echo $user['created_at'] ? date('d-m-y', strtotime($user['created_at'])) : 'N/A'; ?></td><?php endif; ?>
                                    <td><?php echo $user['last_login'] ? date('d-m-y', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <?php if ($hasProfilesViewedCol): ?><td><?php echo $user['profiles_viewed'] ?? '0'; ?></td><?php endif; ?>
                                    <td><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['note'] ?? '', ENT_QUOTES); ?>')">
                                            Edit
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-warning" onclick="resetPassword(<?php echo $user['id']; ?>)">
                                            Reset
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="d-md-none">
                            <?php foreach ($users as $user): ?>
                            <div class="border-bottom p-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                    <span class="badge bg-<?php echo ($user['role'] === 'manager' ? 'info' : ($user['role'] === 'admin' ? 'dark' : ($user['role'] === 'special_customer' ? 'secondary' : 'warning'))); ?>">
                                        <?php echo ($user['role'] === 'special_customer' ? 'Special' : ($user['role'] === 'support' ? 'Customer' : ucfirst($user['role']))); ?>
                                    </span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <div><?php if ($hasCreatedAtCol): ?><span class="me-2">Created: <?php echo $user['created_at'] ? date('d-m-y', strtotime($user['created_at'])) : '-'; ?></span><?php endif; ?><span class="me-2">Login: <?php echo $user['last_login'] ? date('d-m-y', strtotime($user['last_login'])) : '-'; ?></span><?php if ($hasProfilesViewedCol): ?> Profiles: <?php echo $user['profiles_viewed'] ?? '0'; ?><?php endif; ?></div>
                                    <div>Mobile: <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '-'; ?></div>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-info" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['note'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button class="btn btn-warning" onclick="resetPassword(<?php echo $user['id']; ?>)">Reset</button>
                                    <button class="btn btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
<?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($searchName) ? '&search_name=' . urlencode($searchName) : ''; ?><?php echo !empty($searchPhone) ? '&search_phone=' . urlencode($searchPhone) : ''; ?>">Previous</a>
                            </li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($searchName) ? '&search_name=' . urlencode($searchName) : ''; ?><?php echo !empty($searchPhone) ? '&search_phone=' . urlencode($searchPhone) : ''; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($searchName) ? '&search_name=' . urlencode($searchName) : ''; ?><?php echo !empty($searchPhone) ? '&search_phone=' . urlencode($searchPhone) : ''; ?>">Next</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center text-muted mb-3">
                        <small>Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + count($users), $totalUsers); ?> of <?php echo $totalUsers; ?> users</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
                </div>

<div class="card mt-4" id="registration-requests">
                    <div class="card-header">
                        <h5 class="mb-0">New Registration Requests</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($registrationRequests)): ?>
                            <p class="text-muted mb-0">No registration requests yet.</p>
                        <?php else: ?>
                        <div class="table-responsive d-none d-md-block">
                                <table class="table table-sm table-striped align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead>
                                        <tr class="table-dark">
                                            <th style="width: 100px;">Name</th>
                                            <th style="width: 90px;">Phone</th>
                                            <th style="width: 90px;">Alt Phone</th>
                                            <th style="width: 90px;">Marriage</th>
                                            <th style="max-width: 120px;">Caste</th>
                                            <th style="width: 90px;">DOB</th>
                                            <th style="width: 80px;">City</th>
                                            <th style="max-width: 100px;">Education</th>
                                            <th style="width: 70px;">Status</th>
                                            <th style="width: 110px;">Submitted</th>
                                            <th style="width: 70px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registrationRequests as $request): ?>
                                            <tr>
                                                <td class="text-truncate" style="max-width: 100px;" title="<?php echo htmlspecialchars($request['name']); ?>"><?php echo htmlspecialchars($request['name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['phone']); ?></td>
                                                <td><?php echo $request['alternate_phone'] ? htmlspecialchars($request['alternate_phone']) : '-'; ?></td>
                                                <td><?php echo htmlspecialchars($request['marriage_type']); ?></td>
                                                <td class="text-truncate" style="max-width: 120px;" title="<?php echo htmlspecialchars($request['caste']); ?>"><?php echo htmlspecialchars($request['caste']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($request['birth_date']))); ?></td>
                                                <td><?php echo htmlspecialchars($request['city']); ?></td>
                                                <td class="text-truncate" style="max-width: 100px;" title="<?php echo htmlspecialchars($request['education']); ?>"><?php echo htmlspecialchars($request['education']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $request['status'] === 'new' ? 'warning text-dark' : 'success'; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars(date('d-m H:i', strtotime($request['created_at']))); ?></td>
                                                <td>
                                                    <?php if ($request['status'] === 'new'): ?>
                                                        <form method="POST" action="admin_dashboard.php#registration-requests" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="mark_registration_reviewed">
                                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-success py-0 px-1">Done</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="admin_dashboard.php#registration-requests" class="m-0" onsubmit="return confirm('Delete this request?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="delete_registration_request">
                                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger py-0 px-1">✕</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                        </div>
                        <div class="d-md-none">
                            <?php foreach ($registrationRequests as $request): ?>
                            <div class="border-bottom p-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong><?php echo htmlspecialchars($request['name']); ?></strong>
                                    <span class="badge bg-<?php echo $request['status'] === 'new' ? 'warning text-dark' : 'success'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                    </span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <div>Phone: <?php echo htmlspecialchars($request['phone']); ?></div>
                                    <div>Alt: <?php echo $request['alternate_phone'] ? htmlspecialchars($request['alternate_phone']) : '-'; ?></div>
                                    <div>Marriage: <?php echo htmlspecialchars($request['marriage_type']); ?></div>
                                    <div>Caste: <?php echo htmlspecialchars($request['caste']); ?></div>
                                    <div>DOB: <?php echo htmlspecialchars(date('d-m-Y', strtotime($request['birth_date']))); ?></div>
                                    <div>City: <?php echo htmlspecialchars($request['city']); ?></div>
                                    <div>Education: <?php echo htmlspecialchars($request['education']); ?></div>
                                    <div>Submitted: <?php echo htmlspecialchars(date('d-m H:i', strtotime($request['created_at']))); ?></div>
                                </div>
                                <?php if ($request['status'] === 'new'): ?>
                                <form method="POST" action="admin_dashboard.php#registration-requests" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="mark_registration_reviewed">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Mark Reviewed</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="admin_dashboard.php#registration-requests" class="m-0" onsubmit="return confirm('Delete this request?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_registration_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="manage_user.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-control" id="role" name="role" required>
                                <option value="admin" <?php echo ($stats['total_admins'] >= 3) ? 'disabled' : ''; ?>>
                                    Admin <?php echo ($stats['total_admins'] >= 3) ? '(Limit Reached)' : ''; ?>
                                </option>
                                <option value="manager">Manager</option>
                                <option value="customer">Customer</option>
                                <option value="special_customer">Special Customer</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="2" placeholder="Add a note about this user"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Mobile Number (Optional)</label>
                            <input type="tel" class="form-control" id="phone" name="phone" placeholder="Enter mobile number">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="action" value="add" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="manage_user.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <input type="hidden" id="edit_user_id" name="user_id">
                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" name="username" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="edit_phone" class="form-label">Mobile Number</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone" placeholder="Enter mobile number">
                        </div>
                        <div class="mb-3">
                            <label for="edit_note" class="form-label">Note</label>
                            <textarea class="form-control" id="edit_note" name="note" rows="2" placeholder="Add a note about this user"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="action" value="edit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function editUser(userId, username, phone, note) {
        document.getElementById('edit_user_id').value = userId;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_phone').value = phone;
        document.getElementById('edit_note').value = note || '';
        var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
        editModal.show();
    }

    function resetPassword(userId) {
        if (confirm('Are you sure you want to reset this user\'s password?')) {
            window.location.href = `manage_user.php?action=reset&id=${userId}`;
        }
    }

    function deleteUser(userId) {
        if (confirm('Are you sure you want to delete this user?')) {
            window.location.href = `manage_user.php?action=delete&id=${userId}`;
        }
    }
    </script>
</body>
</html>
