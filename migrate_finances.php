<?php
$pdo = new PDO('mysql:host=db;dbname=db', 'db', 'db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    "ALTER TABLE roles 
        ADD COLUMN IF NOT EXISTS can_manage_users tinyint(1) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS can_edit_users tinyint(1) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS can_manage_project_members tinyint(1) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS can_manage_finances tinyint(1) NOT NULL DEFAULT 0",
    "ALTER TABLE finances ADD COLUMN IF NOT EXISTS group_name varchar(100) DEFAULT NULL AFTER description",
    "CREATE TABLE IF NOT EXISTS `finances` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `running_number` int(11) NOT NULL,
        `invoice_date` date NOT NULL,
        `payment_date` date DEFAULT NULL,
        `description` varchar(255) NOT NULL,
        `type` enum('income', 'expense') NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `payment_method` enum('cash', 'bank_transfer') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `running_number` (`running_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` varchar(50) NOT NULL,
        `setting_value` varchar(255) NOT NULL,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('fiscal_year_start', '01.09.')"
];

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Success: " . substr($q, 0, 50) . "...\n";
    }
    catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
