<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_companies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        billing_name VARCHAR(120) NOT NULL,
        manager_id BIGINT UNSIGNED NOT NULL,
        credit_limit_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
        FOREIGN KEY (manager_id) REFERENCES users(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_stores (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        store_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        name VARCHAR(100) NOT NULL,
        cost_center VARCHAR(80) NOT NULL,
        delivery_address VARCHAR(255) NOT NULL,
        UNIQUE KEY procurement_store_code (company_id,store_code),
        FOREIGN KEY (company_id) REFERENCES procurement_companies(id)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS procurement_members (
        store_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (store_id,user_id),
        KEY procurement_user_stores (user_id,store_id),
        FOREIGN KEY (store_id) REFERENCES procurement_stores(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB");
};
