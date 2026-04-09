<?php
require 'db.php';
echo "<h2>DB Test</h2>";
echo "Connected: " . (isset($pdo) ? 'YES' : 'NO') . "<br>";
try {
  $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
  $row = $stmt->fetch();
  echo "Users table: " . $row['count'] . " rows";
} catch (Exception $e) {
  echo "Query error: " . $e->getMessage();
}
?>

