<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    $db->exec("ALTER TABLE users MODIFY role ENUM('buyer','merchant','admin','finance','payout') NOT NULL");
    if (!$migration->hasColumn('shops', 'legacy_payee_request')) {
        $db->exec('ALTER TABLE shops ADD legacy_payee_request VARCHAR(160) NULL');
    }
    if (!$migration->hasColumn('settlements', 'period')) {
        $db->exec("ALTER TABLE settlements
            ADD period DATE NULL,
            ADD currency CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'CNY',
            ADD state ENUM('legacy','review_pending','payable','cleared','paid') NOT NULL DEFAULT 'legacy',
            ADD payee_name VARCHAR(160) NULL,
            ADD agreement_version VARCHAR(30) NULL,
            ADD preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD reviewed_by BIGINT UNSIGNED NULL,
            ADD reviewed_at DATETIME NULL,
            ADD paid_by BIGINT UNSIGNED NULL,
            ADD paid_at DATETIME NULL,
            ADD transfer_reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD UNIQUE KEY settlements_shop_period (shop_id,currency,period),
            ADD UNIQUE KEY settlements_transfer (transfer_reference),
            ADD KEY settlements_work_queue (state,id),
            ADD CONSTRAINT settlements_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id),
            ADD CONSTRAINT settlements_payer_fk FOREIGN KEY (paid_by) REFERENCES users(id),
            ADD CONSTRAINT settlements_separate_operators CHECK (paid_by IS NULL OR paid_by<>reviewed_by)");
    }
    if (!$migration->hasColumn('ledger', 'currency')) {
        $db->exec("ALTER TABLE ledger ADD currency CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'CNY',
            ADD KEY ledger_period (shop_id,currency,settlement_id,created_at,id),
            ADD KEY ledger_batch_entries (settlement_id,id)");
    }
};
