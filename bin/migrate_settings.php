<?php
$pdo = new PDO('mysql:host=db;dbname=db', 'db', 'db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    "CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` varchar(50) NOT NULL,
        `setting_value` text DEFAULT NULL,
        `binary_content` longblob DEFAULT NULL,
        `mime_type` varchar(100) DEFAULT NULL,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES ('app_name', 'Chorkuma App')"
];

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Success: " . substr($q, 0, 50) . "...\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
