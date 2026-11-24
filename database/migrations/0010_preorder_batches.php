<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("CREATE TABLE IF NOT EXISTS preorder_batches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state ENUM('open','closed','received') NOT NULL DEFAULT 'open',
        quota INT UNSIGNED NOT NULL CHECK (quota BETWEEN 1 AND 1000000),
        held INT UNSIGNED NOT NULL DEFAULT 0,
        sold INT UNSIGNED NOT NULL DEFAULT 0,
        closes_at DATETIME NOT NULL,
        expected_on DATE NOT NULL,
        received_units INT UNSIGNED NULL,
        received_at DATETIME NULL,
        UNIQUE KEY preorder_product_code (product_id,code),
        KEY preorder_catalog (product_id,state,closes_at),
        CONSTRAINT preorder_capacity CHECK (held+sold <= quota),
        CONSTRAINT preorder_dates CHECK (DATE(closes_at) <= expected_on),
        CONSTRAINT preorder_receipt CHECK ((state='received') = (received_at IS NOT NULL AND received_units IS NOT NULL)),
        CONSTRAINT preorder_product_fk FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT preorder_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
    ) ENGINE=InnoDB");
    if (!$migration->hasColumn('cart_lines', 'preorder_batch_id')) {
        $db->exec('ALTER TABLE cart_lines ADD COLUMN preorder_batch_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT cart_preorder_batch_fk FOREIGN KEY (preorder_batch_id) REFERENCES preorder_batches(id)');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS order_preorders (
        order_item_id BIGINT UNSIGNED PRIMARY KEY,
        batch_id BIGINT UNSIGNED NOT NULL,
        quantity SMALLINT UNSIGNED NOT NULL CHECK (quantity BETWEEN 1 AND 99),
        state ENUM('held','paid','released') NOT NULL DEFAULT 'held',
        KEY order_preorders_batch (batch_id,state,order_item_id),
        CONSTRAINT preorder_item_fk FOREIGN KEY (order_item_id) REFERENCES order_items(id),
        CONSTRAINT preorder_batch_fk FOREIGN KEY (batch_id) REFERENCES preorder_batches(id)
    ) ENGINE=InnoDB");
};
