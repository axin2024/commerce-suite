<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("ALTER TABLE merchant_orders MODIFY status
        ENUM('unpaid','ready','fulfilled','cancelled','partial','shipped') NOT NULL DEFAULT 'unpaid'");
    $db->exec("CREATE TABLE IF NOT EXISTS shipments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        merchant_order_id BIGINT UNSIGNED NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        carrier VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        tracking VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        dispatched_by BIGINT UNSIGNED NOT NULL,
        dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivered_at DATETIME NULL,
        UNIQUE KEY shipments_request (merchant_order_id,request_key),
        UNIQUE KEY shipments_tracking (carrier,tracking),
        KEY shipments_order_warehouse (merchant_order_id,warehouse_id,id),
        CONSTRAINT shipments_order_fk FOREIGN KEY (merchant_order_id) REFERENCES merchant_orders(id),
        CONSTRAINT shipments_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
        CONSTRAINT shipments_actor_fk FOREIGN KEY (dispatched_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS shipment_items (
        shipment_id BIGINT UNSIGNED NOT NULL,
        allocation_id BIGINT UNSIGNED NOT NULL,
        quantity SMALLINT UNSIGNED NOT NULL CHECK (quantity BETWEEN 1 AND 99),
        PRIMARY KEY (shipment_id,allocation_id),
        KEY shipment_items_allocation (allocation_id,shipment_id),
        CONSTRAINT shipment_items_shipment_fk FOREIGN KEY (shipment_id) REFERENCES shipments(id),
        CONSTRAINT shipment_items_allocation_fk FOREIGN KEY (allocation_id) REFERENCES order_inventory_allocations(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS shipment_delivery_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        carrier VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        external_reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        shipment_id BIGINT UNSIGNED NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY delivery_events_source (carrier,external_reference),
        KEY delivery_events_shipment (shipment_id,id),
        CONSTRAINT delivery_events_shipment_fk FOREIGN KEY (shipment_id) REFERENCES shipments(id)
    ) ENGINE=InnoDB");
};
