-- Zahltag – Datenbankschema
-- Charset: utf8mb4, Collation: utf8mb4_unicode_ci
-- Copyright: Niko Winckel – n-Systeme.de, GNU GPL v3

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Superadmin
CREATE TABLE IF NOT EXISTS `admin` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `totp_secret`   VARCHAR(64) NULL,
    `totp_last_ts`  INT NULL COMMENT 'Last accepted TOTP timestamp for replay protection',
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gruppen
CREATE TABLE IF NOT EXISTS `groups` (
    `id`                      INT AUTO_INCREMENT PRIMARY KEY,
    `name`                    VARCHAR(100) NOT NULL,
    `currency`                VARCHAR(3) DEFAULT 'EUR',
    `admin_token`             VARCHAR(64) NOT NULL UNIQUE,
    `share_token`             VARCHAR(64) NOT NULL UNIQUE,
    `pin_required`            TINYINT(1) DEFAULT 0,
    `categories_enabled`      TINYINT(1) DEFAULT 0,
    `categories_required`     TINYINT(1) DEFAULT 0,
    `show_preset_categories`  TINYINT(1) DEFAULT 1,
    `cover_image`             VARCHAR(255) NULL,
    `cover_image_position`    VARCHAR(50) NULL,
    `pdf_margin_top`          DECIMAL(3,1) NULL,
    `pdf_margin_right`        DECIMAL(3,1) NULL,
    `pdf_margin_bottom`       DECIMAL(3,1) NULL,
    `pdf_margin_left`         DECIMAL(3,1) NULL,
    `archived_at`             DATETIME NULL,
    `created_at`              DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mitglieder
CREATE TABLE IF NOT EXISTS `members` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`          INT NOT NULL,
    `name`              VARCHAR(100) NOT NULL,
    `pin_hash`          VARCHAR(255) NULL,
    `payment_paypal`    VARCHAR(255) NULL,
    `payment_wero`      VARCHAR(255) NULL,
    `payment_iban`      VARCHAR(34) NULL,
    `payment_iban_name` VARCHAR(100) NULL,
    `active`            TINYINT(1) DEFAULT 1,
    `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_name_per_group` (`group_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benutzerdefinierte Kategorien pro Gruppe
CREATE TABLE IF NOT EXISTS `custom_categories` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`   INT NOT NULL,
    `name`       VARCHAR(100) NOT NULL,
    `sort_order` INT DEFAULT 0,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausgaben
CREATE TABLE IF NOT EXISTS `expenses` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`         INT NOT NULL,
    `paid_by`          INT NOT NULL,
    `description`      VARCHAR(255) NOT NULL,
    `amount`           DECIMAL(10,2) NOT NULL,
    `currency`         VARCHAR(3) DEFAULT 'EUR',
    `exchange_rate`    DECIMAL(12,6) NULL,
    `category_preset`  VARCHAR(50) NULL,
    `category_custom_id` INT NULL,
    `expense_date`     DATE NOT NULL,
    `receipt_path`     VARCHAR(255) NULL,
    `receipt_number`   VARCHAR(100) NULL,
    `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`paid_by`) REFERENCES `members`(`id`),
    FOREIGN KEY (`category_custom_id`) REFERENCES `custom_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aufteilung pro Ausgabe
CREATE TABLE IF NOT EXISTS `expense_splits` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `expense_id`   INT NOT NULL,
    `member_id`    INT NOT NULL,
    `share_amount` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zahlungsbestätigungen
CREATE TABLE IF NOT EXISTS `payments` (
    `id`                   INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`             INT NOT NULL,
    `from_member_id`       INT NOT NULL,
    `to_member_id`         INT NOT NULL,
    `amount`               DECIMAL(10,2) NOT NULL,
    `confirmed_by_recipient` TINYINT(1) DEFAULT 0,
    `created_at`           DATETIME DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`         DATETIME NULL,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`from_member_id`) REFERENCES `members`(`id`),
    FOREIGN KEY (`to_member_id`) REFERENCES `members`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wechselkurs-Cache
CREATE TABLE IF NOT EXISTS `exchange_rates` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `base_currency`   VARCHAR(3) NOT NULL,
    `target_currency` VARCHAR(3) NOT NULL,
    `rate`            DECIMAL(12,6) NOT NULL,
    `rate_date`       DATE NOT NULL,
    `fetched_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_pair_date` (`base_currency`, `target_currency`, `rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E-Mail-Einstellungen
CREATE TABLE IF NOT EXISTS `email_settings` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `method`          ENUM('smtp','sendmail','brevo') DEFAULT 'smtp',
    `smtp_host`       VARCHAR(255) NULL,
    `smtp_port`       INT NULL,
    `smtp_user`       VARCHAR(255) NULL,
    `smtp_pass`       VARCHAR(255) NULL,
    `smtp_encryption` ENUM('tls','ssl','none') DEFAULT 'tls',
    `brevo_api_key`   VARCHAR(255) NULL,
    `from_email`      VARCHAR(255) NULL,
    `from_name`       VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Site-Einstellungen
CREATE TABLE IF NOT EXISTS `site_settings` (
    `setting_key`   VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Änderungsprotokoll
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`   INT NOT NULL,
    `member_id`  INT NULL,
    `action`     VARCHAR(50) NOT NULL,
    `details`    TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passkeys für SuperAdmin
CREATE TABLE IF NOT EXISTS `admin_passkeys` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`      INT NOT NULL,
    `credential_id` VARCHAR(512) NOT NULL,
    `public_key`    TEXT NOT NULL,
    `sign_counter`  INT UNSIGNED NOT NULL DEFAULT 0,
    `device_name`   VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_credential` (`credential_id`(191)),
    FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notfallcodes für SuperAdmin
CREATE TABLE IF NOT EXISTS `admin_recovery_codes` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`   INT NOT NULL,
    `code_hash`  VARCHAR(255) NOT NULL,
    `used_at`    DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-Force-Schutz für Admin-Login
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address`  VARCHAR(45) NOT NULL,
    `attempted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default-Datensätze für E-Mail-Einstellungen
INSERT IGNORE INTO `email_settings` (`id`, `method`, `smtp_port`, `smtp_encryption`)
VALUES (1, 'smtp', 587, 'tls');
