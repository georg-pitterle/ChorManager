<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateFinanceGroupsAndLink extends AbstractMigration
{
    public function up(): void
    {
        // Canonical finance group table (single source of truth for group identity).
        $this->execute("CREATE TABLE IF NOT EXISTS finance_groups (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_finance_group_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Seed canonical groups from existing free-text values (finances + budget).
        $this->execute("INSERT IGNORE INTO finance_groups (name)
            SELECT DISTINCT group_name FROM finances
            WHERE group_name IS NOT NULL AND group_name <> '';");
        $this->execute("INSERT IGNORE INTO finance_groups (name)
            SELECT DISTINCT group_name FROM budget_categories
            WHERE group_name IS NOT NULL AND group_name <> '';");

        // Link finances (denormalized group_name mirror is kept for the Kassa UI/report).
        $this->execute("ALTER TABLE finances
            ADD COLUMN finance_group_id int(11) NULL DEFAULT NULL AFTER group_name,
            ADD KEY idx_finances_finance_group_id (finance_group_id),
            ADD CONSTRAINT fk_finances_finance_group
                FOREIGN KEY (finance_group_id) REFERENCES finance_groups (id)
                ON DELETE SET NULL;");
        $this->execute("UPDATE finances f
            JOIN finance_groups g ON g.name = f.group_name COLLATE utf8mb4_general_ci
            SET f.finance_group_id = g.id;");

        // Link budget categories and backfill before dropping the old string column.
        $this->execute("ALTER TABLE budget_categories
            ADD COLUMN finance_group_id int(11) NULL DEFAULT NULL AFTER fiscal_year_start,
            ADD KEY idx_budget_categories_finance_group_id (finance_group_id),
            ADD CONSTRAINT fk_budget_categories_finance_group
                FOREIGN KEY (finance_group_id) REFERENCES finance_groups (id)
                ON DELETE CASCADE;");
        $this->execute("UPDATE budget_categories b
            JOIN finance_groups g ON g.name = b.group_name COLLATE utf8mb4_general_ci
            SET b.finance_group_id = g.id;");

        // Swap the uniqueness constraint to the FK and drop the legacy string column.
        $this->execute("ALTER TABLE budget_categories DROP INDEX uq_budget_category;");
        $this->execute("ALTER TABLE budget_categories DROP COLUMN group_name;");
        $this->execute("ALTER TABLE budget_categories
            ADD UNIQUE KEY uq_budget_category (fiscal_year_start, finance_group_id, type);");
    }

    public function down(): void
    {
        // Restore the legacy string column on budget_categories.
        $this->execute("ALTER TABLE budget_categories DROP INDEX uq_budget_category;");
        $this->execute("ALTER TABLE budget_categories
            ADD COLUMN group_name varchar(255) NOT NULL DEFAULT '' AFTER fiscal_year_start;");
        $this->execute("UPDATE budget_categories b
            JOIN finance_groups g ON g.id = b.finance_group_id
            SET b.group_name = g.name COLLATE utf8mb4_unicode_ci;");
        $this->execute("ALTER TABLE budget_categories
            ADD UNIQUE KEY uq_budget_category (fiscal_year_start, group_name, type);");

        $this->execute("ALTER TABLE budget_categories
            DROP FOREIGN KEY fk_budget_categories_finance_group;");
        $this->execute("ALTER TABLE budget_categories
            DROP COLUMN finance_group_id;");

        $this->execute("ALTER TABLE finances DROP FOREIGN KEY fk_finances_finance_group;");
        $this->execute("ALTER TABLE finances DROP COLUMN finance_group_id;");

        $this->execute("DROP TABLE IF EXISTS finance_groups;");
    }
}
