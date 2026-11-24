<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('orders', 'payment_provider')) {
        $db->exec('ALTER TABLE orders ADD payment_provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL');
    }
    $db->exec("ALTER TABLE payments MODIFY provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'demo'");
    if (!$migration->hasColumn('payment_receipts', 'provider')) {
        $db->exec("ALTER TABLE payment_receipts
            ADD provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sandbox',
            DROP INDEX reference,
            DROP INDEX refund_reference,
            ADD UNIQUE KEY channel_capture_reference (provider,reference),
            ADD UNIQUE KEY channel_exception_refund_reference (provider,refund_reference)");
    }
    if (!$migration->hasColumn('refunds', 'refund_provider')) {
        $db->exec("ALTER TABLE refunds
            ADD refund_provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sandbox',
            DROP INDEX refund_channel_reference,
            ADD UNIQUE KEY refund_channel_reference (refund_provider,refund_reference)");
    }
    if (!$migration->hasColumn('channel_bill_rows', 'provider')) {
        $db->exec("ALTER TABLE channel_bill_rows
            ADD provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sandbox'");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS external_refund_receipts (
        provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        event_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (provider,reference)
    ) ENGINE=InnoDB");
};
