<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_billing_settings (
        company_id BIGINT UNSIGNED PRIMARY KEY,
        time_zone VARCHAR(64) NOT NULL,
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_statements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        period DATE NOT NULL,
        closed_by BIGINT UNSIGNED NOT NULL,
        closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (company_id,period),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (closed_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_statement_lines (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        receipt_id BIGINT UNSIGNED NOT NULL,
        statement_id BIGINT UNSIGNED NULL,
        period DATE NOT NULL,
        kind ENUM('goods','freight','return') NOT NULL,
        source_ref VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        amount_minor BIGINT NOT NULL,
        posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recorded_by BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY (receipt_id,kind,source_ref),
        KEY (company_id,period,statement_id),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (receipt_id) REFERENCES procurement_receipts(id),
        FOREIGN KEY (statement_id) REFERENCES procurement_statements(id),
        FOREIGN KEY (recorded_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_bank_receipts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        bank_ref VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        value_date DATE NOT NULL,
        amount_minor BIGINT UNSIGNED NOT NULL,
        imported_by BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY (company_id,bank_ref),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (imported_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bank_id BIGINT UNSIGNED NOT NULL,
        line_id BIGINT UNSIGNED NOT NULL,
        request_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount_minor BIGINT UNSIGNED NOT NULL,
        allocated_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (bank_id,request_key),
        KEY (line_id),
        FOREIGN KEY (bank_id) REFERENCES procurement_bank_receipts(id),
        FOREIGN KEY (line_id) REFERENCES procurement_statement_lines(id),
        FOREIGN KEY (allocated_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
};
