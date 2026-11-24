<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pickup_locations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        name VARCHAR(100) NOT NULL,
        address VARCHAR(250) NULL,
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        UNIQUE KEY pickup_location_code (shop_id,code),
        UNIQUE KEY pickup_location_warehouse (warehouse_id),
        KEY pickup_location_public (shop_id,enabled,id),
        CONSTRAINT pickup_location_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id),
        CONSTRAINT pickup_location_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('shop_members', 'pickup_location_id')) {
        $db->exec("ALTER TABLE shop_members
            MODIFY position ENUM('owner','catalog','fulfillment','support','finance','pickup') NOT NULL,
            ADD COLUMN pickup_location_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT member_pickup_location_fk FOREIGN KEY (pickup_location_id) REFERENCES pickup_locations(id),
            ADD CONSTRAINT member_pickup_assignment CHECK ((position='pickup') = (pickup_location_id IS NOT NULL))");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS order_pickups (
        merchant_order_id BIGINT UNSIGNED PRIMARY KEY,
        pickup_location_id BIGINT UNSIGNED NOT NULL,
        location_name VARCHAR(100) NOT NULL,
        location_address VARCHAR(250) NOT NULL,
        prepared_at DATETIME NULL,
        prepared_by BIGINT UNSIGNED NULL,
        code_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        code_expires_at DATETIME NULL,
        used_at DATETIME NULL,
        used_by BIGINT UNSIGNED NULL,
        KEY order_pickups_location (pickup_location_id,merchant_order_id),
        CONSTRAINT pickup_order_fk FOREIGN KEY (merchant_order_id) REFERENCES merchant_orders(id),
        CONSTRAINT pickup_location_fk FOREIGN KEY (pickup_location_id) REFERENCES pickup_locations(id),
        CONSTRAINT pickup_preparer_fk FOREIGN KEY (prepared_by) REFERENCES users(id),
        CONSTRAINT pickup_redeemer_fk FOREIGN KEY (used_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("INSERT INTO pickup_locations (shop_id,warehouse_id,code,name,enabled)
        SELECT s.id,w.id,'LEGACY','旧版自提登记',0
        FROM shops s JOIN warehouses w ON w.shop_id=s.id AND w.code='MAIN'
        WHERE NOT EXISTS (SELECT 1 FROM pickup_locations l WHERE l.warehouse_id=w.id)
          AND EXISTS (SELECT 1 FROM merchant_orders m JOIN orders o ON o.id=m.order_id
              WHERE m.shop_id=s.id AND o.channel='store')");
    $db->exec("INSERT INTO order_pickups (merchant_order_id,pickup_location_id,location_name,location_address,used_at)
        SELECT m.id,l.id,'旧版自提登记',o.destination,m.fulfilled_at
        FROM merchant_orders m JOIN orders o ON o.id=m.order_id AND o.channel='store'
        JOIN warehouses w ON w.shop_id=m.shop_id AND w.code='MAIN'
        JOIN pickup_locations l ON l.warehouse_id=w.id
        LEFT JOIN order_pickups p ON p.merchant_order_id=m.id WHERE p.merchant_order_id IS NULL");
};
