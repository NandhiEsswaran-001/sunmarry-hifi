<?php
require_once 'db.php';

try {
    // Add role column and credits to users table
    $pdo->exec("ALTER TABLE users 
                ADD COLUMN role ENUM('super_admin', 'manager', 'customer', 'special_customer') NOT NULL DEFAULT 'customer',
                ADD COLUMN credits INT DEFAULT 25,
                ADD COLUMN last_login DATETIME DEFAULT NULL,
                ADD COLUMN profiles_viewed INT DEFAULT 0");

    // Clear existing users
    $pdo->exec("DELETE FROM users");

    // Insert admin users with hashed passwords
    $admins = [
        ['admin1', 'super_admin', 'Admin123!'],
        ['admin2', 'manager', 'Manager123!'],
        ['admin3', 'customer', 'Support123!']
    ];

    // Use password_hash() for secure password storage instead of md5()
    $stmt = $pdo->prepare("INSERT INTO users (username, role, password) VALUES (?, ?, ?)");

    foreach ($admins as $admin) {
        $stmt->execute([$admin[0], $admin[1], password_hash($admin[2], PASSWORD_DEFAULT)]);
    }

    echo "Migration completed successfully! Admin users created.\n";
    // For security, do NOT echo plaintext passwords in production. Print usernames only.
    echo "Created users: ";
    $names = array_column($admins, 0);
    echo implode(', ', $names) . "\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
?>
