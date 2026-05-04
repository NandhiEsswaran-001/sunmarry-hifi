<?php
require_once 'auth.php';
requireLogin();

// Handle POST actions: restore or permanent delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid request');
    }
    
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    if ($id) {
        if ($action === 'restore') {
            $stmt = $pdo->prepare('UPDATE profiles SET deleted_at = NULL WHERE id = ?');
            $stmt->execute([$id]);
        } elseif ($action === 'permanent') {
            // fetch file paths to delete
            $stmt = $pdo->prepare('SELECT profile_photo, file_upload FROM profiles WHERE id = ?');
            $stmt->execute([$id]);
            $profile = $stmt->fetch();
            if ($profile) {
                if (!empty($profile['profile_photo'])) {
                    $path = __DIR__ . '/' . $profile['profile_photo'];
                    if (file_exists($path)) @unlink($path);
                }
                if (!empty($profile['file_upload'])) {
                    $path = __DIR__ . '/' . $profile['file_upload'];
                    if (file_exists($path)) @unlink($path);
                }
                $del = $pdo->prepare('DELETE FROM profiles WHERE id = ?');
                $del->execute([$id]);
            }
        }
    }

    header('Location: recycle.php');
    exit();
}

// List deleted profiles
$stmt = $pdo->prepare('SELECT * FROM profiles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC');
$stmt->execute();
$deleted = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recycle Bin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <h2>Recycle Bin</h2>
        <p class="text-muted">Deleted profiles can be restored or permanently removed.</p>

        <?php if (empty($deleted)): ?>
            <div class="alert alert-info">No deleted profiles.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Deleted At</th>
                            <th>Photo</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deleted as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['id']); ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo htmlspecialchars($p['deleted_at']); ?></td>
                            <td>
                                <?php if (!empty($p['profile_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($p['profile_photo']); ?>" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <button class="btn btn-sm btn-success" onclick="return confirm('Restore this profile?');">Restore</button>
                                </form>
                                <form method="POST" class="d-inline ms-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <input type="hidden" name="action" value="permanent">
                                    <button class="btn btn-sm btn-danger" onclick="return confirm('Permanently delete this profile? This cannot be undone.');">Delete Permanently</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
