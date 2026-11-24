<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS channel_returns (
        channel_id BIGINT UNSIGNED NOT NULL,
        reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        refund_id BIGINT UNSIGNED NOT NULL UNIQUE,
        PRIMARY KEY (channel_id,reference),
        CONSTRAINT channel_return_source_fk FOREIGN KEY (channel_id) REFERENCES catalog_channels(id),
        CONSTRAINT channel_return_refund_fk FOREIGN KEY (refund_id) REFERENCES refunds(id)
    ) ENGINE=InnoDB");
};
