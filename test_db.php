<?php
try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nom VARCHAR(100))");
    // SQLITE doesn't support IF NOT EXISTS for add column, but we just want to test auth.php logic
} catch (Exception $e) {
    echo $e->getMessage();
}
