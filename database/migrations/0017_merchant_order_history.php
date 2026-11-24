<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('merchant_orders', 'placed_at')) {
        $db->exec('ALTER TABLE merchant_orders ADD placed_at DATETIME NULL,
            ADD KEY merchant_order_history (shop_id,placed_at DESC,id DESC)');
    }
    $db->exec('UPDATE merchant_orders m JOIN orders o ON o.id=m.order_id
        SET m.placed_at=o.created_at WHERE m.placed_at IS NULL');
    $db->exec('ALTER TABLE merchant_orders MODIFY placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
};
