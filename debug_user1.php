<?php
require_once 'db.php';
$stmt = getDB()->prepare('SELECT id,username,email,role,otp_code,otp_expires FROM users WHERE id = 1');
$stmt->execute();
$u = $stmt->fetch(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($u);
