<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('users', 'member_level')) {
        $db->exec("ALTER TABLE users ADD COLUMN member_level ENUM('standard','plus') NOT NULL DEFAULT 'standard'");
    }
    if (!$migration->hasColumn('product_releases', 'member_price')) {
        $db->exec("ALTER TABLE product_releases ADD COLUMN member_price INT UNSIGNED NULL,
            ADD CONSTRAINT releases_member_price CHECK (member_price BETWEEN 1 AND price)");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS promotions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        enabled BOOLEAN NOT NULL DEFAULT TRUE
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS promotion_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        promotion_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        category_id BIGINT UNSIGNED NULL,
        threshold BIGINT UNSIGNED NOT NULL CHECK (threshold > 0),
        discount INT UNSIGNED NOT NULL,
        requires_claim BOOLEAN NOT NULL,
        stack_member_price BOOLEAN NOT NULL,
        ends_at DATETIME NULL,
        published_by BIGINT UNSIGNED NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY promotion_revisions_history (promotion_id,id),
        CONSTRAINT revisions_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id),
        CONSTRAINT revisions_category_fk FOREIGN KEY (category_id) REFERENCES product_categories(id),
        CONSTRAINT revisions_publisher_fk FOREIGN KEY (published_by) REFERENCES users(id),
        CONSTRAINT revisions_discount CHECK (discount BETWEEN 1 AND 100000000 AND discount <= threshold)
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('promotions', 'current_revision_id')) {
        $db->exec('ALTER TABLE promotions ADD COLUMN current_revision_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT promotions_current_fk FOREIGN KEY (current_revision_id) REFERENCES promotion_revisions(id)');
    }
    if (!$migration->hasColumn('orders', 'promotion_revision_id')) {
        $db->exec("ALTER TABLE orders ADD COLUMN promotion_revision_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT orders_promotion_fk FOREIGN KEY (promotion_revision_id) REFERENCES promotion_revisions(id)");
    }
    if (!$migration->hasColumn('order_items', 'list_price')) {
        $db->exec('ALTER TABLE order_items ADD COLUMN list_price INT UNSIGNED NULL');
    }
    $db->exec("ALTER TABLE payments MODIFY provider ENUM('demo','sandbox','promotion') NOT NULL DEFAULT 'demo'");
    $db->exec("CREATE TABLE IF NOT EXISTS coupon_claims (
        buyer_id BIGINT UNSIGNED NOT NULL,
        promotion_id BIGINT UNSIGNED NOT NULL,
        revision_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NULL UNIQUE,
        claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        redeemed_at DATETIME NULL,
        PRIMARY KEY (buyer_id,promotion_id),
        CONSTRAINT claims_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id),
        CONSTRAINT claims_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id),
        CONSTRAINT claims_revision_fk FOREIGN KEY (revision_id) REFERENCES promotion_revisions(id),
        CONSTRAINT claims_order_fk FOREIGN KEY (order_id) REFERENCES orders(id)
    ) ENGINE=InnoDB");

    $db->exec("INSERT INTO promotions (code) VALUES ('marketplace-opening') ON DUPLICATE KEY UPDATE code=code");
    $db->exec("INSERT INTO promotion_revisions (promotion_id,name,threshold,discount,requires_claim,stack_member_price)
        SELECT p.id,'跨店满 200 减 20',20000,2000,0,1 FROM promotions p
        LEFT JOIN promotion_revisions r ON r.promotion_id=p.id
        WHERE p.code='marketplace-opening' AND r.id IS NULL");
    $db->exec("UPDATE promotions p JOIN promotion_revisions r ON r.promotion_id=p.id
        SET p.current_revision_id=r.id WHERE p.code='marketplace-opening' AND p.current_revision_id IS NULL");
};
