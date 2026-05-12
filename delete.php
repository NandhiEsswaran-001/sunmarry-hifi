<?php
require_once 'auth.php';
requireLogin();

// Only allow super_admin and manager roles
if (isCustomerRole()) {
    header('Location: access_denied.php');
    exit();
}

// Only allow POST requests for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profiles.php');
    exit();
}

// CSRF protection
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid request');
}

// Support bulk deletion via ids[] or single id
$ids = [];
if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    // sanitize to integers
    foreach ($_POST['ids'] as $v) {
        $ids[] = (int)$v;
    }
} elseif (!empty($_POST['id'])) {
    $ids[] = (int)$_POST['id'];
}

if (empty($ids)) {
    header('Location: profiles.php');
    exit();
}

// Perform soft-delete for each id
$upd = $pdo->prepare('UPDATE profiles SET deleted_at = NOW() WHERE id = ?');
foreach ($ids as $deleteId) {
    if ($deleteId <= 0) continue;
    // ensure profile exists (optional)
    $stmt = $pdo->prepare('SELECT id FROM profiles WHERE id = ?');
    $stmt->execute([$deleteId]);
    $profileExists = $stmt->fetchColumn();
    if ($profileExists) {
        $upd->execute([$deleteId]);
    }
}

// Redirect back to profiles list with success message
header('Location: profiles.php?deleted=1');
exit();
