<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if ($migration->hasColumn('refunds', 'return_warehouse_id')) {
        return;
    }
    $db->exec("ALTER TABLE refunds
        MODIFY status ENUM('pending','approved','rejected','return_authorized','return_in_transit',
            'refund_pending','refunded') NOT NULL DEFAULT 'pending',
        ADD return_warehouse_id BIGINT UNSIGNED NULL,
        ADD return_address VARCHAR(250) NULL,
        ADD review_note VARCHAR(250) NULL,
        ADD carrier VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD tracking VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD dispatched_at DATETIME NULL,
        ADD accepted_quantity SMALLINT UNSIGNED NULL,
        ADD restocked_quantity SMALLINT UNSIGNED NULL,
        ADD inspection_note VARCHAR(250) NULL,
        ADD inspected_by BIGINT UNSIGNED NULL,
        ADD inspected_at DATETIME NULL,
        ADD commission BIGINT UNSIGNED NULL,
        ADD refund_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD refund_reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD confirmed_at DATETIME NULL,
        ADD UNIQUE KEY refund_parcel (carrier,tracking),
        ADD UNIQUE KEY refund_intent (refund_token),
        ADD UNIQUE KEY refund_channel_reference (refund_reference),
        ADD KEY refund_queue (status,id),
        ADD CONSTRAINT refund_return_warehouse_fk FOREIGN KEY (return_warehouse_id) REFERENCES warehouses(id),
        ADD CONSTRAINT refund_inspector_fk FOREIGN KEY (inspected_by) REFERENCES users(id),
        ADD CONSTRAINT refund_inspection_quantities CHECK (restocked_quantity <= accepted_quantity AND accepted_quantity <= quantity)");
};
