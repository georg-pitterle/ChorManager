<?php
$pdo = new PDO('mysql:host=db;dbname=db', 'db', 'db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    "ALTER TABLE roles ADD COLUMN IF NOT EXISTS can_manage_master_data tinyint(1) NOT NULL DEFAULT 0",
    "UPDATE roles SET can_manage_master_data = 1 WHERE can_manage_users = 1",
    "UPDATE roles SET can_manage_master_data = 1 WHERE name = 'Admin'"
];

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Success: " . substr($q, 0, 50) . "...\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
