<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("CREATE TABLE IF NOT EXISTS loyalty_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        earn_per_yuan TINYINT UNSIGNED NOT NULL CHECK (earn_per_yuan<=10),
        redeem_percent TINYINT UNSIGNED NOT NULL CHECK (redeem_percent<=100),
        return_spent BOOLEAN NOT NULL,
        published_by BIGINT UNSIGNED NOT NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT loyalty_publisher_fk FOREIGN KEY (published_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('orders', 'loyalty_revision_id')) {
        $db->exec('ALTER TABLE orders ADD loyalty_revision_id BIGINT UNSIGNED NULL,
            ADD points_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD points_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD CONSTRAINT order_loyalty_revision_fk FOREIGN KEY (loyalty_revision_id) REFERENCES loyalty_revisions(id)');
    }
    if (!$migration->hasColumn('order_items', 'points_spent')) {
        $db->exec('ALTER TABLE order_items ADD points_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD points_earned BIGINT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!$migration->hasColumn('refunds', 'points_spent')) {
        $db->exec('ALTER TABLE refunds ADD points_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD points_restored BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD points_reversed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD CONSTRAINT refund_points_returned CHECK (points_restored<=points_spent)');
    }
    if (!$migration->hasColumn('ledger', 'platform_points')) {
        $db->exec("ALTER TABLE ledger ADD platform_points BIGINT NOT NULL DEFAULT 0,
            ADD CONSTRAINT ledger_points_direction CHECK ((kind='sale' AND platform_points>=0)
                OR (kind='refund' AND platform_points<=0))");
    }
    $db->exec('CREATE TABLE IF NOT EXISTS loyalty_accounts (
        buyer_id BIGINT UNSIGNED PRIMARY KEY,
        balance BIGINT NOT NULL DEFAULT 0,
        held BIGINT NOT NULL DEFAULT 0 CHECK (held>=0),
        CONSTRAINT loyalty_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id)
    ) ENGINE=InnoDB');
    $db->exec("CREATE TABLE IF NOT EXISTS loyalty_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        order_id BIGINT UNSIGNED NOT NULL,
        refund_id BIGINT UNSIGNED NULL,
        kind ENUM('hold','cancel','spend','earn','restore','reverse') NOT NULL,
        balance_delta BIGINT NOT NULL,
        held_delta BIGINT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY loyalty_order_entries (order_id,id),
        CONSTRAINT loyalty_entry_order_fk FOREIGN KEY (order_id) REFERENCES orders(id),
        CONSTRAINT loyalty_entry_refund_fk FOREIGN KEY (refund_id) REFERENCES refunds(id)
    ) ENGINE=InnoDB");
};
