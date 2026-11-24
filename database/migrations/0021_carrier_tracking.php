<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('shipments', 'tracking_source')) {
        $db->exec("ALTER TABLE shipments ADD tracking_source ENUM('sandbox','carrier') NULL");
    }
    // Delivered parcels in older releases could only have received sandbox events.
    $db->exec("UPDATE shipments SET tracking_source='sandbox' WHERE tracking_source IS NULL AND delivered_at IS NOT NULL");
    if (!$migration->hasColumn('shipment_delivery_events', 'source')) {
        $db->exec("ALTER TABLE shipment_delivery_events
            ADD source ENUM('sandbox','carrier') NOT NULL DEFAULT 'sandbox',
            ADD event_type ENUM('in_transit','out_for_delivery','exception','delivered') NOT NULL DEFAULT 'delivered',
            ADD occurred_at DATETIME NULL,
            ADD payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            DROP INDEX delivery_events_source,
            ADD UNIQUE KEY delivery_events_source (source,carrier,external_reference),
            ADD KEY shipment_tracking_history (shipment_id,occurred_at,id)");
    }
};
