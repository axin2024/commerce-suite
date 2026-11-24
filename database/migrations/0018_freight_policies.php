<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("CREATE TABLE IF NOT EXISTS freight_policy_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        strategy VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        base_fee INT UNSIGNED NOT NULL,
        free_threshold BIGINT UNSIGNED NULL,
        published_by BIGINT UNSIGNED NOT NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY freight_shop_revision (shop_id,id),
        CONSTRAINT freight_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id),
        CONSTRAINT freight_publisher_fk FOREIGN KEY (published_by) REFERENCES users(id),
        CONSTRAINT freight_amounts CHECK (base_fee<=1000000 AND (free_threshold IS NULL OR free_threshold>0))
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('shops', 'freight_policy_revision_id')) {
        $db->exec('ALTER TABLE shops ADD freight_policy_revision_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT shop_freight_policy_fk FOREIGN KEY (id,freight_policy_revision_id)
                REFERENCES freight_policy_revisions(shop_id,id)');
    }
    if (!$migration->hasColumn('merchant_orders', 'freight_policy_revision_id')) {
        $db->exec('ALTER TABLE merchant_orders ADD freight_policy_revision_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT merchant_order_freight_policy_fk FOREIGN KEY (shop_id,freight_policy_revision_id)
                REFERENCES freight_policy_revisions(shop_id,id)');
    }
};
