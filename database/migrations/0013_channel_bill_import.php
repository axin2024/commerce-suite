<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS channel_bill_imports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uploaded_by BIGINT UNSIGNED NOT NULL,
        shop_id BIGINT UNSIGNED NULL,
        file_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        row_count SMALLINT UNSIGNED NOT NULL CHECK (row_count BETWEEN 1 AND 1000),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY bill_import_replay (uploaded_by,file_sha256),
        KEY bill_import_shop (shop_id,id),
        CONSTRAINT bill_import_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id),
        CONSTRAINT bill_import_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS channel_bill_rows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        import_id BIGINT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        kind ENUM('capture','return_refund','exception_refund') NULL,
        order_id BIGINT UNSIGNED NULL,
        reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
        amount BIGINT UNSIGNED NULL,
        status ENUM('queued','invalid','duplicate','conflict','awaiting_receipt','matched','mismatch','receipt_exception','outside_shop') NOT NULL,
        result_code VARCHAR(40) CHARACTER SET ascii NULL,
        local_id BIGINT UNSIGNED NULL,
        checked_at DATETIME NULL,
        UNIQUE KEY bill_row_number (import_id,line_no),
        KEY bill_row_queue (status,id),
        CONSTRAINT bill_row_import_fk FOREIGN KEY (import_id) REFERENCES channel_bill_imports(id)
    ) ENGINE=InnoDB");
};
