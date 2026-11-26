<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_suppliers (
        company_id BIGINT UNSIGNED NOT NULL,
        shop_id BIGINT UNSIGNED NOT NULL,
        enabled BOOLEAN NOT NULL,
        PRIMARY KEY (company_id,shop_id),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_agreements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        revision INT UNSIGNED NOT NULL,
        valid_from DATE NOT NULL,
        valid_until DATE NOT NULL,
        author_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY procurement_agreement_revision (company_id,product_id,revision),
        KEY procurement_agreement_effective (company_id,product_id,valid_from,id),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id),
        FOREIGN KEY (product_id) REFERENCES products(id),
        FOREIGN KEY (author_id) REFERENCES users(id),
        CHECK (valid_until >= valid_from)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_price_tiers (
        agreement_id BIGINT UNSIGNED NOT NULL,
        quantity_min INT UNSIGNED NOT NULL,
        quantity_max INT UNSIGNED NOT NULL,
        priority SMALLINT UNSIGNED NOT NULL,
        price_minor INT UNSIGNED NOT NULL,
        PRIMARY KEY (agreement_id,priority,quantity_min),
        FOREIGN KEY (agreement_id) REFERENCES procurement_agreements(id),
        CHECK (quantity_min > 0 AND quantity_max >= quantity_min AND price_minor > 0)
    ) ENGINE=InnoDB");
};
