<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserMailAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS user_mail_accounts (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            imap_host varchar(255) NOT NULL,
            imap_port int(11) NOT NULL,
            imap_encryption enum('ssl', 'tls', 'none') NOT NULL,
            imap_username varchar(255) NOT NULL,
            imap_password_enc text NOT NULL,
            imap_enabled tinyint(1) NOT NULL DEFAULT 0,
            mail_badge_enabled tinyint(1) NOT NULL DEFAULT 0,
            mail_last_unseen_count int(11) NULL DEFAULT NULL,
            mail_last_uid_seen varchar(255) NULL DEFAULT NULL,
            mail_last_checked_at datetime NULL DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_mail_accounts_user_id (user_id),
            CONSTRAINT fk_user_mail_accounts_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE user_mail_accounts DROP FOREIGN KEY fk_user_mail_accounts_user;");
        $this->execute("DROP TABLE IF EXISTS user_mail_accounts;");
    }
}
