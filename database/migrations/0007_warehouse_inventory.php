<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS warehouses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        name VARCHAR(100) NOT NULL,
        address VARCHAR(250) NULL,
        UNIQUE KEY warehouses_shop_code (shop_id,code),
        CONSTRAINT warehouses_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS warehouse_stock (
        product_id BIGINT UNSIGNED NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        stock INT UNSIGNED NOT NULL,
        reserved INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (product_id,warehouse_id),
        KEY warehouse_stock_location (warehouse_id,product_id),
        CONSTRAINT stock_product_fk FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT stock_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
        CONSTRAINT stock_reserved_limit CHECK (reserved <= stock)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS order_inventory_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_item_id BIGINT UNSIGNED NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        quantity SMALLINT UNSIGNED NOT NULL CHECK (quantity BETWEEN 1 AND 99),
        state ENUM('held','consumed','released') NOT NULL DEFAULT 'held',
        UNIQUE KEY allocations_item_warehouse (order_item_id,warehouse_id),
        CONSTRAINT allocations_item_fk FOREIGN KEY (order_item_id) REFERENCES order_items(id),
        CONSTRAINT allocations_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
    ) ENGINE=InnoDB");

    $db->exec("INSERT INTO warehouses (shop_id,code,name)
        SELECT s.id,'MAIN','主仓' FROM shops s LEFT JOIN warehouses w ON w.shop_id=s.id AND w.code='MAIN'
        WHERE w.id IS NULL");
    $db->exec("INSERT INTO warehouse_stock (product_id,warehouse_id,stock,reserved)
        SELECT p.id,w.id,p.stock,p.reserved FROM products p JOIN warehouses w ON w.shop_id=p.shop_id AND w.code='MAIN'
        LEFT JOIN warehouse_stock b ON b.product_id=p.id AND b.warehouse_id=w.id WHERE b.product_id IS NULL");
    $db->exec("INSERT INTO order_inventory_allocations (order_item_id,warehouse_id,quantity,state)
        SELECT i.id,w.id,i.quantity,
            CASE o.status WHEN 'paid' THEN 'consumed' WHEN 'cancelled' THEN 'released' ELSE 'held' END
        FROM order_items i JOIN merchant_orders m ON m.id=i.merchant_order_id JOIN orders o ON o.id=m.order_id
        JOIN warehouses w ON w.shop_id=m.shop_id AND w.code='MAIN'
        LEFT JOIN order_inventory_allocations a ON a.order_item_id=i.id AND a.warehouse_id=w.id WHERE a.id IS NULL");
};
