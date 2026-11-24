<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('promotion_revisions', 'refund_coupon')) {
        $db->exec('ALTER TABLE promotion_revisions ADD refund_coupon BOOLEAN NOT NULL DEFAULT FALSE,
            ADD CONSTRAINT revisions_coupon_return CHECK (refund_coupon=0 OR requires_claim=1)');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS coupon_refund_restorations (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        refund_id BIGINT UNSIGNED NOT NULL UNIQUE,
        revision_id BIGINT UNSIGNED NOT NULL,
        restored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT coupon_restore_order_fk FOREIGN KEY (order_id) REFERENCES orders(id),
        CONSTRAINT coupon_restore_refund_fk FOREIGN KEY (refund_id) REFERENCES refunds(id),
        CONSTRAINT coupon_restore_revision_fk FOREIGN KEY (revision_id) REFERENCES promotion_revisions(id)
    ) ENGINE=InnoDB");
};
