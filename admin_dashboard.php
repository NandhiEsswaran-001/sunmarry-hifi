<?php
require_once 'db.php';
require_once 'auth.php';

// Ensure only super admin can access
checkPermission('super_admin');

// Get statistics
$stats = [
    'total_profiles' => $pdo->query("SELECT COUNT(*) FROM profiles")->fetchColumn(),
    'total_admins' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'total_managers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn(),
    'total_customer' => $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('customer','special_customer','support')")->fetchColumn(),
    'active_profiles' => $pdo->query("SELECT COUNT(*) FROM profiles WHERE deleted_at IS NULL")->fetchColumn()
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

// Get all admin users except super admin, with search
if (!empty($params)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} else {
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll();
}
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
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            Add New User
                        </button>
                    </div>
                    <div class="card-body">
                        <table class="table">
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
                                    <?php if ($hasCreatedAtCol): ?><td><?php echo $user['created_at'] ? date('Y-m-d H:i', strtotime($user['created_at'])) : 'N/A'; ?></td><?php endif; ?>
                                    <td><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <?php if ($hasProfilesViewedCol): ?><td><?php echo $user['profiles_viewed'] ?? '0'; ?></td><?php endif; ?>
                                    <td><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['note'] ?? '', ENT_QUOTES); ?>')">
                                            Edit
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="resetPassword(<?php echo $user['id']; ?>)">
                                            Reset Password
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
