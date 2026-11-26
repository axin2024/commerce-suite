<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_receipts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        receiver_id BIGINT UNSIGNED NOT NULL,
        delivery_ref VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        received_at DATETIME NOT NULL,
        accepted_qty INT UNSIGNED NOT NULL,
        damaged_qty INT UNSIGNED NOT NULL,
        open_qty INT UNSIGNED NOT NULL,
        amount_minor BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY procurement_delivery_line (order_id,line_no,delivery_ref),
        KEY (received_at,order_id),
        FOREIGN KEY (order_id) REFERENCES procurement_orders(id),
        FOREIGN KEY (receiver_id) REFERENCES users(id),
        CHECK (accepted_qty+damaged_qty>0)
    ) ENGINE=InnoDB");
};
