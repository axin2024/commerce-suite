<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("CREATE TABLE IF NOT EXISTS product_categories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS product_families (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        UNIQUE KEY families_shop_name (shop_id,name),
        CONSTRAINT families_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS product_releases (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        family_id BIGINT UNSIGNED NULL,
        category_id BIGINT UNSIGNED NULL,
        name VARCHAR(160) NOT NULL,
        price INT UNSIGNED NOT NULL CHECK (price BETWEEN 1 AND 100000000),
        color VARCHAR(40) NOT NULL DEFAULT '',
        size VARCHAR(40) NOT NULL DEFAULT '',
        delivery BOOLEAN NOT NULL,
        pickup BOOLEAN NOT NULL,
        state ENUM('legacy','pending','approved','rejected','withdrawn') NOT NULL,
        pending_product_id BIGINT UNSIGNED GENERATED ALWAYS AS (
            CASE WHEN state='pending' THEN product_id ELSE NULL END
        ) STORED,
        submitted_by BIGINT UNSIGNED NULL,
        reviewed_by BIGINT UNSIGNED NULL,
        review_reason VARCHAR(250) NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        UNIQUE KEY releases_one_pending (pending_product_id),
        KEY releases_product (product_id,id),
        KEY releases_state (state,id),
        KEY releases_family_variant (family_id,color,size),
        CONSTRAINT releases_product_fk FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT releases_family_fk FOREIGN KEY (family_id) REFERENCES product_families(id),
        CONSTRAINT releases_category_fk FOREIGN KEY (category_id) REFERENCES product_categories(id),
        CONSTRAINT releases_submitter_fk FOREIGN KEY (submitted_by) REFERENCES users(id),
        CONSTRAINT releases_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id),
        CONSTRAINT releases_channel CHECK (delivery OR pickup)
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('products', 'release_id')) {
        $db->exec("ALTER TABLE products ADD COLUMN release_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT products_release_fk FOREIGN KEY (release_id) REFERENCES product_releases(id)");
    }
    if (!$migration->hasColumn('order_items', 'product_release_id')) {
        $db->exec("ALTER TABLE order_items ADD COLUMN product_release_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT order_items_release_fk FOREIGN KEY (product_release_id) REFERENCES product_releases(id)");
    }
    $db->exec("INSERT INTO product_releases (product_id,name,price,delivery,pickup,state)
        SELECT p.id,p.name,p.price,1,1,'legacy' FROM products p
        LEFT JOIN product_releases r ON r.product_id=p.id AND r.state='legacy'
        WHERE p.status IN ('active','archived') AND r.id IS NULL");
    $db->exec("UPDATE products p JOIN product_releases r ON r.product_id=p.id AND r.state='legacy'
        SET p.release_id=r.id WHERE p.release_id IS NULL");
};
