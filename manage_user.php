<?php
require_once 'db.php';
require_once 'auth.php';

// Ensure only super admin can access
checkPermission('super_admin');

function generateRandomPassword() {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'), 0, 12);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] ?? '';
        $phone = trim($_POST['phone'] ?? ''); // Optional phone number
        $note = trim($_POST['note'] ?? '');

        // Helper to redirect with error
        $redirectError = function($msg) {
            header('Location: admin_dashboard.php?error=' . urlencode($msg));
            exit();
        };

        // Basic validation
        if ($username === '' || $password === '') {
            $redirectError('Username and password are required');
        }
        if (strlen($username) < 3) {
            $redirectError('Username too short');
        }

        // Validate role
        if (!in_array($role, ['admin', 'manager', 'customer', 'special_customer'])) {
            $redirectError('Invalid role specified');
        }

        try {
            // Limit admin users to 3
            if ($role === 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount >= 3) {
                    $redirectError('Admin limit reached (max 3 admins)');
                }
            }

            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $redirectError('Username already exists');
            }

                // Check if phone column exists and whether DB role enum contains 'customer'
                $colStmt = $pdo->query("SHOW COLUMNS FROM users");
                $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
                $colNames = array_column($cols, 'Field');
                $hasPhoneCol = in_array('phone', $colNames);
                $hasNoteCol = in_array('note', $colNames);

                // Determine actual DB role value to insert: some DBs may still use enum('super_admin','manager','support')
                $roleColumn = null;
                foreach ($cols as $c) {
                    if ($c['Field'] === 'role') { $roleColumn = $c; break; }
                }
                $dbRole = $role;
                if ($roleColumn && isset($roleColumn['Type'])) {
                $type = $roleColumn['Type']; // e.g. enum('super_admin','admin','manager','support')
                if (strpos($type, "'customer'") === false && strpos($type, "'support'") !== false) {
                    // DB doesn't accept 'customer' yet — map to legacy 'support' for storage
                    if ($role === 'customer') {
                        $dbRole = 'support';
                    }
                }
            }

            // Use secure password hashing
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Add new user - include initial credits (25) and profiles_viewed = 0
            if ($hasPhoneCol && $phone !== '' && $hasNoteCol) {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, phone, note, credits, profiles_viewed) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $dbRole, $phone, $note, 25, 0]);
            } elseif ($hasPhoneCol && $phone !== '') {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, phone, credits, profiles_viewed) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $dbRole, $phone, 25, 0]);
            } elseif ($hasNoteCol) {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, note, credits, profiles_viewed) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $dbRole, $note, 25, 0]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, credits, profiles_viewed) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $dbRole, 25, 0]);
            }

            header('Location: admin_dashboard.php?success=created');
            exit();
        } catch (PDOException $e) {
            $redirectError('Database error: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'edit') {
        $userId = $_POST['user_id'];
        $phone = trim($_POST['phone'] ?? '');
        $note = trim($_POST['note'] ?? '');

        // Check if phone column exists
        $colStmt = $pdo->query("SHOW COLUMNS FROM users");
        $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'Field');
        $hasPhoneCol = in_array('phone', $colNames);
        $hasNoteCol = in_array('note', $colNames);

        // Update user phone if column exists
        if ($hasPhoneCol && $hasNoteCol) {
            $stmt = $pdo->prepare("UPDATE users SET phone = ?, note = ? WHERE id = ?");
            $stmt->execute([$phone, $note, $userId]);
        } elseif ($hasPhoneCol) {
            $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $stmt->execute([$phone, $userId]);
        } elseif ($hasNoteCol) {
            $stmt = $pdo->prepare("UPDATE users SET note = ? WHERE id = ?");
            $stmt->execute([$note, $userId]);
        }

        header('Location: admin_dashboard.php?success=updated');
        exit();
    }
}

if (isset($_GET['action'])) {
    $userId = $_GET['id'] ?? 0;

    // Verify user exists and is not super_admin
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role != 'super_admin'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        die('Invalid user');
    }

    switch ($_GET['action']) {
        case 'reset':
            $newPassword = generateRandomPassword();
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            
            // Show the new password to the admin
            echo "New password for {$user['username']}: {$newPassword}";
            echo "<br><a href='admin_dashboard.php'>Back to Dashboard</a>";
            exit();

        case 'delete':
            // Don't allow deleting if it's the last manager
            if ($user['role'] === 'manager') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'manager'");
                $stmt->execute();
                if ($stmt->fetchColumn() <= 1) {
                    die('Cannot delete the last manager account');
                }
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            header('Location: admin_dashboard.php?success=deleted');
            exit();
    }
}

// If we get here, something went wrong
header('Location: admin_dashboard.php?error=invalid_action');
exit();
?>
