<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('shops', 'kind')) {
        $db->exec("ALTER TABLE shops
            MODIFY status ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending',
            ADD COLUMN kind ENUM('marketplace','platform') NOT NULL DEFAULT 'marketplace',
            ADD COLUMN payee_name VARCHAR(160) NULL,
            ADD COLUMN agreement_version VARCHAR(30) NULL");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS shop_members (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        position ENUM('owner','catalog','fulfillment','support','finance') NOT NULL,
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        access_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY shop_members_shop (shop_id, user_id),
        CONSTRAINT shop_members_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
        CONSTRAINT shop_members_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("INSERT INTO shop_members (user_id,shop_id,position)
        SELECT u.id,u.shop_id,'owner' FROM users u LEFT JOIN shop_members m ON m.user_id=u.id
        WHERE u.role='merchant' AND u.shop_id IS NOT NULL AND m.user_id IS NULL");
};
