<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_rfqs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        store_id BIGINT UNSIGNED NOT NULL,
        creator_id BIGINT UNSIGNED NOT NULL,
        subject VARCHAR(120) NOT NULL,
        deadline DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (store_id,deadline),
        FOREIGN KEY (store_id) REFERENCES procurement_stores(id),
        FOREIGN KEY (creator_id) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_rfq_lines (
        rfq_id BIGINT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        specification VARCHAR(160) NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        PRIMARY KEY (rfq_id,line_no),
        FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_invites (
        rfq_id BIGINT UNSIGNED NOT NULL,
        shop_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (rfq_id,shop_id),
        KEY (shop_id,rfq_id),
        FOREIGN KEY (rfq_id) REFERENCES procurement_rfqs(id),
        FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_bids (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id BIGINT UNSIGNED NOT NULL,
        shop_id BIGINT UNSIGNED NOT NULL,
        revision INT UNSIGNED NOT NULL,
        author_id BIGINT UNSIGNED NOT NULL,
        late BOOLEAN NOT NULL,
        freight_minor INT UNSIGNED NOT NULL,
        total_minor BIGINT UNSIGNED NOT NULL,
        submitted_at DATETIME NOT NULL,
        UNIQUE KEY (rfq_id,shop_id,revision),
        FOREIGN KEY (rfq_id,shop_id) REFERENCES procurement_invites(rfq_id,shop_id),
        FOREIGN KEY (author_id) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_bid_lines (
        bid_id BIGINT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        agreement_id BIGINT UNSIGNED NOT NULL,
        price_minor INT UNSIGNED NOT NULL,
        PRIMARY KEY (bid_id,line_no),
        FOREIGN KEY (bid_id) REFERENCES procurement_bids(id),
        FOREIGN KEY (agreement_id) REFERENCES procurement_agreements(id)
    ) ENGINE=InnoDB");
};
