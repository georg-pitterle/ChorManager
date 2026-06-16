<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateBudgetTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS budget_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            fiscal_year_start int(11) NOT NULL,
            group_name varchar(255) NOT NULL,
            type enum('income','expense') NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_budget_category (fiscal_year_start, group_name, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS budget_items (
            id int(11) NOT NULL AUTO_INCREMENT,
            budget_category_id int(11) NOT NULL,
            description varchar(255) NOT NULL,
            planned_amount decimal(10,2) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_budget_items_category_id (budget_category_id),
            CONSTRAINT fk_budget_items_category
                FOREIGN KEY (budget_category_id)
                REFERENCES budget_categories (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS budget_items;");
        $this->execute("DROP TABLE IF EXISTS budget_categories;");
    }
}