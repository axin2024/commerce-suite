<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('orders', 'payment_token')) {
        $db->exec("ALTER TABLE orders
            ADD COLUMN payment_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD UNIQUE KEY orders_payment_token (payment_token)");
    }
    $db->exec("UPDATE orders SET payment_token=REPLACE(UUID(),'-','') WHERE payment_token IS NULL");
    $db->exec("ALTER TABLE orders MODIFY payment_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL");
    if (!$migration->hasColumn('payments', 'provider')) {
        $db->exec("ALTER TABLE payments
            ADD COLUMN provider ENUM('demo','sandbox') NOT NULL DEFAULT 'demo',
            DROP INDEX reference,
            ADD UNIQUE KEY payments_provider_reference (provider,reference)");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        payment_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status ENUM('processing','accepted','refund_required','needs_review','refunded') NOT NULL DEFAULT 'processing',
        failure_code VARCHAR(40) CHARACTER SET ascii NULL,
        order_id BIGINT UNSIGNED NULL,
        payment_id BIGINT UNSIGNED NULL,
        refund_reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
        resolved_by BIGINT UNSIGNED NULL,
        resolved_at DATETIME NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY payment_receipts_status (status,id),
        CONSTRAINT payment_receipts_order_fk FOREIGN KEY (order_id) REFERENCES orders(id),
        CONSTRAINT payment_receipts_payment_fk FOREIGN KEY (payment_id) REFERENCES payments(id),
        CONSTRAINT payment_receipts_finance_fk FOREIGN KEY (resolved_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
};
