<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cart_lines (
        buyer_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity TINYINT UNSIGNED NOT NULL CHECK (quantity BETWEEN 1 AND 99),
        selected BOOLEAN NOT NULL DEFAULT TRUE,
        revision CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (buyer_id,product_id),
        CONSTRAINT cart_lines_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id),
        CONSTRAINT cart_lines_product_fk FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB");
};
