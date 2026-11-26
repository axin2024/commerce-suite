<?php

declare(strict_types=1);

return static function (PDO $db,Migrator $migration): void {
    if (!$migration->hasColumn('procurement_members','duty')) {
        $db->exec("ALTER TABLE procurement_members ADD duty ENUM('purchaser','receiver') NOT NULL DEFAULT 'purchaser'");
    }
    if (!$migration->hasColumn('procurement_companies','accepting_requests')) {
        $db->exec("ALTER TABLE procurement_companies ADD accepting_requests BOOLEAN NOT NULL DEFAULT 1");
    }
};
