CREATE TABLE IF NOT EXISTS shops (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
    commission_bps INT UNSIGNED NOT NULL DEFAULT 1000 CHECK (commission_bps <= 10000),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('buyer','merchant','admin','finance') NOT NULL,
    shop_id BIGINT UNSIGNED NULL,
    CONSTRAINT users_shop FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    price INT UNSIGNED NOT NULL CHECK (price BETWEEN 1 AND 100000000),
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    reserved INT UNSIGNED NOT NULL DEFAULT 0,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    UNIQUE KEY products_sku (shop_id, sku),
    KEY products_catalog (status, id),
    CONSTRAINT products_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
    CONSTRAINT products_reservation CHECK (reserved <= stock)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotes (
    token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    buyer_id BIGINT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    expires_at DATETIME NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    KEY quotes_buyer (buyer_id, expires_at),
    CONSTRAINT quotes_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('web','store') NOT NULL,
    status ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    subtotal BIGINT UNSIGNED NOT NULL,
    discount BIGINT UNSIGNED NOT NULL,
    shipping BIGINT UNSIGNED NOT NULL,
    total BIGINT UNSIGNED NOT NULL,
    destination VARCHAR(250) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    KEY orders_expiry (status, expires_at, id),
    KEY orders_buyer (buyer_id, id),
    CONSTRAINT orders_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    status ENUM('unpaid','ready','fulfilled','cancelled') NOT NULL DEFAULT 'unpaid',
    total BIGINT UNSIGNED NOT NULL,
    shipping BIGINT UNSIGNED NOT NULL,
    tracking VARCHAR(120) NULL,
    fulfilled_at DATETIME NULL,
    UNIQUE KEY merchant_order (order_id, shop_id),
    KEY merchant_shop (shop_id, id),
    CONSTRAINT merchant_order_fk FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT merchant_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    discount BIGINT UNSIGNED NOT NULL,
    commission BIGINT UNSIGNED NOT NULL,
    refunded_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY order_product (merchant_order_id, product_id),
    CONSTRAINT item_order FOREIGN KEY (merchant_order_id) REFERENCES merchant_orders(id),
    CONSTRAINT item_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT item_refunded_quantity CHECK (refunded_quantity <= quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    amount BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT payments_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    reason VARCHAR(250) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY refund_request (buyer_id, request_key),
    KEY item_refunds (item_id, status),
    CONSTRAINT refund_item FOREIGN KEY (item_id) REFERENCES order_items(id),
    CONSTRAINT refund_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT refund_decider FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    amount BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY settlements_shop (shop_id, id),
    CONSTRAINT settlements_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id),
    CONSTRAINT settlements_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    merchant_order_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    kind ENUM('sale','refund') NOT NULL,
    gross BIGINT NOT NULL,
    commission BIGINT NOT NULL,
    settlement_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ledger_settlement (shop_id, settlement_id, id),
    CONSTRAINT ledger_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
    CONSTRAINT ledger_order FOREIGN KEY (merchant_order_id) REFERENCES merchant_orders(id),
    CONSTRAINT ledger_batch FOREIGN KEY (settlement_id) REFERENCES settlements(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    details JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY audit_action (action, entity_id),
    CONSTRAINT audit_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
