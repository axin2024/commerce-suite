<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS channel_order_access (
        channel_id BIGINT UNSIGNED PRIMARY KEY,
        buyer_id BIGINT UNSIGNED NOT NULL UNIQUE,
        token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
        revision INT UNSIGNED NOT NULL DEFAULT 1,
        CONSTRAINT order_access_channel_fk FOREIGN KEY (channel_id) REFERENCES catalog_channels(id),
        CONSTRAINT order_access_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS channel_orders (
        channel_id BIGINT UNSIGNED NOT NULL,
        reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL UNIQUE,
        PRIMARY KEY (channel_id,reference),
        CONSTRAINT channel_order_source_fk FOREIGN KEY (channel_id) REFERENCES catalog_channels(id),
        CONSTRAINT channel_order_order_fk FOREIGN KEY (order_id) REFERENCES orders(id)
    ) ENGINE=InnoDB");
};
