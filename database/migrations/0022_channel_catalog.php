<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS catalog_channels (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        label VARCHAR(80) NOT NULL,
        token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        generation INT UNSIGNED NOT NULL DEFAULT 1,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY catalog_channel_shop_code (shop_id,code),
        UNIQUE KEY catalog_channel_token (token_sha256),
        CONSTRAINT catalog_channel_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS catalog_channel_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        revision INT UNSIGNED NOT NULL DEFAULT 1,
        UNIQUE KEY catalog_channel_product (channel_id,product_id),
        KEY catalog_channel_listing (channel_id,id),
        CONSTRAINT catalog_entry_channel_fk FOREIGN KEY (channel_id) REFERENCES catalog_channels(id),
        CONSTRAINT catalog_entry_product_fk FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB");
};
