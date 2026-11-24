<?php

declare(strict_types=1);

return static function (PDO $db, Migrator $migration): void {
    if ($migration->hasColumn('promotion_revisions', 'discount_strategy')) {
        return;
    }
    $db->exec("ALTER TABLE promotion_revisions
        ADD discount_strategy VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'fixed_v1',
        ADD rate_bps SMALLINT UNSIGNED NULL,
        ADD strategy_label VARCHAR(120) NULL,
        DROP CHECK revisions_discount,
        ADD CONSTRAINT revisions_discount CHECK (discount BETWEEN 1 AND 100000000
            AND (discount_strategy<>'fixed_v1' OR (discount<=threshold AND rate_bps IS NULL))
            AND (discount_strategy<>'percentage_v1' OR rate_bps IS NOT NULL)
            AND (rate_bps IS NULL OR rate_bps BETWEEN 1 AND 10000))");
};
