<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_budgets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        cost_center VARCHAR(80) NOT NULL,
        period DATE NOT NULL,
        limit_minor BIGINT UNSIGNED NOT NULL,
        review_above_minor BIGINT UNSIGNED NOT NULL,
        used_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY (company_id,cost_center,period),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        CHECK (used_minor<=limit_minor)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_awards (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        rfq_id BIGINT UNSIGNED NOT NULL,
        bid_id BIGINT UNSIGNED NOT NULL,
        ship_to BIGINT UNSIGNED NOT NULL,
        budget_id BIGINT UNSIGNED NOT NULL,
        requester BIGINT UNSIGNED NOT NULL,
        request_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
        status ENUM('pending','approved','rejected','ordered') NOT NULL,
        total_minor BIGINT UNSIGNED NOT NULL,
        decided_by BIGINT UNSIGNED NULL,
        decision_reason VARCHAR(200) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (company_id,request_key),
        KEY (rfq_id,status),
        FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(id),
        FOREIGN KEY (bid_id) REFERENCES procurement_bids(id),
        FOREIGN KEY (ship_to) REFERENCES procurement_stores(id),
        FOREIGN KEY (budget_id) REFERENCES procurement_budgets(id),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (requester) REFERENCES users(id),
        FOREIGN KEY (decided_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_award_lines (
        award_id BIGINT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        requested_qty INT UNSIGNED NOT NULL,
        approved_qty INT UNSIGNED NOT NULL,
        price_minor INT UNSIGNED NOT NULL,
        PRIMARY KEY (award_id,line_no),
        FOREIGN KEY (award_id) REFERENCES procurement_awards(id),
        CHECK (approved_qty<=requested_qty)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        award_id BIGINT UNSIGNED NOT NULL UNIQUE,
        billing_name VARCHAR(120) NOT NULL,
        ship_address VARCHAR(255) NOT NULL,
        total_minor BIGINT UNSIGNED NOT NULL,
        confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (award_id) REFERENCES procurement_awards(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_credit_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        source_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount_minor BIGINT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (company_id,source_key),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (order_id) REFERENCES procurement_orders(id)
    ) ENGINE=InnoDB");
};
