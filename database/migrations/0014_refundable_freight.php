<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if (!$migration->hasColumn('refunds', 'freight_amount')) {
        $db->exec("ALTER TABLE refunds ADD freight_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD freight_payer ENUM('merchant','platform') NULL,
            ADD freight_note VARCHAR(250) NULL,
            ADD freight_decided_by BIGINT UNSIGNED NULL,
            ADD freight_decided_at DATETIME NULL,
            ADD CONSTRAINT refund_freight_decider_fk FOREIGN KEY (freight_decided_by) REFERENCES users(id),
            ADD CONSTRAINT refund_freight_decision CHECK ((freight_amount=0 AND freight_payer IS NULL)
                OR (freight_amount>0 AND freight_payer IS NOT NULL AND freight_note IS NOT NULL
                    AND freight_decided_by IS NOT NULL AND freight_decided_at IS NOT NULL))");
    }
    if (!$migration->hasColumn('ledger', 'platform_freight')) {
        $db->exec("ALTER TABLE ledger ADD platform_freight BIGINT NOT NULL DEFAULT 0,
            ADD CONSTRAINT ledger_freight_credit CHECK (platform_freight=0
                OR (kind='refund' AND platform_freight>0 AND platform_freight<=-gross))");
    }
};
