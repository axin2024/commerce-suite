<?php

declare(strict_types=1);

final class RuleViolation extends RuntimeException {}

final class Commerce
{
    public const AGREEMENT_VERSION = 'marketplace-1';
    public const STAFF_POSITIONS = [
        'catalog' => '商品运营',
        'fulfillment' => '订单履约',
        'support' => '售后客服',
        'finance' => '店铺财务',
    ];

    public function __construct(private readonly PDO $db) {}

    public function rows(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    private function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function one(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    private function insert(string $sql, array $params): int
    {
        $this->query($sql, $params);
        return (int) $this->db->lastInsertId();
    }

    private function transaction(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->db->beginTransaction();
            try {
                $result = $work();
                $this->db->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ($attempt === 3 || !$error instanceof PDOException
                    || !in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $error;
                }
                usleep(random_int(10_000, 70_000));
            }
        }
    }

    private function audit(?int $actor, string $action, int $entity, array $details = []): void
    {
        $this->query('INSERT INTO audit_log (actor_id,action,entity_id,details) VALUES (?,?,?,?)',
            [$actor, $action, $entity, json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    private function actor(int $id, string ...$roles): array
    {
        $actor = $this->one('SELECT id,role,shop_id,email FROM users WHERE id=?', [$id]);
        if (!$actor || ($roles && !in_array($actor['role'], $roles, true))) {
            throw new RuleViolation('当前账号无权执行该操作。');
        }
        return $actor;
    }

    public function profile(int $id): array
    {
        $actor = $this->actor($id);
        if ($actor['role'] !== 'merchant') {
            return $actor;
        }
        $member = $this->one('SELECT position,enabled,access_version FROM shop_members WHERE user_id=? AND shop_id=?',
            [$id, $actor['shop_id']]);
        return $actor + ['position' => $member['position'] ?? null, 'enabled' => !empty($member['enabled']),
            'access_version' => (int) ($member['access_version'] ?? 0)];
    }

    public static function canManage(array $actor, string $position): bool
    {
        return !empty($actor['enabled']) && in_array($actor['position'], ['owner', $position], true);
    }

    private function shopActor(int $id, string $position): array
    {
        $actor = $this->one("SELECT u.id,m.shop_id,m.position,m.enabled FROM users u
            JOIN shop_members m ON m.user_id=u.id AND m.shop_id=u.shop_id
            WHERE u.id=? AND u.role='merchant'" . ($this->db->inTransaction() ? ' FOR SHARE' : ''), [$id]);
        if (!$actor || !self::canManage($actor, $position)) {
            throw new RuleViolation('当前店铺岗位无权执行该操作。');
        }
        return $actor;
    }

    public function authenticate(string $email, string $password): ?int
    {
        $user = $this->one("SELECT u.id,u.password_hash FROM users u
            LEFT JOIN shop_members m ON m.user_id=u.id AND m.shop_id=u.shop_id
            WHERE u.email=? AND (u.role<>'merchant' OR m.enabled=1)", [strtolower(trim($email))]);
        return $user && password_verify($password, $user['password_hash']) ? (int) $user['id'] : null;
    }

    private static function validateAccount(string $email, string $password): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuleViolation('请输入有效邮箱及 12–72 字节的密码。');
        }
        return $email;
    }

    public function register(string $email, string $password, string $shopName = '', string $payee = ''): int
    {
        return $this->createAccount($email, $password, $shopName, $payee, null);
    }

    public function createPlatformShop(int $admin, string $email, string $password, string $shopName, string $payee): int
    {
        $this->actor($admin, 'admin');
        if (trim($shopName) === '') {
            throw new RuleViolation('请填写自营店铺名称。');
        }
        return $this->createAccount($email, $password, $shopName, $payee, $admin);
    }

    private function createAccount(string $email, string $password, string $shopName, string $payee, ?int $admin): int
    {
        $email = self::validateAccount($email, $password);
        $shopName = trim($shopName);
        $payee = trim($payee);
        if (strlen($shopName) > 100 || strlen($payee) > 160) {
            throw new RuleViolation('店铺名称或收款主体名称过长。');
        }
        if ($shopName === '' && $payee !== '') {
            throw new RuleViolation('请同时填写店铺名称。');
        }
        try {
            return $this->transaction(function () use ($email, $password, $shopName, $payee, $admin): int {
                $shop = $shopName === '' ? null : $this->insert('INSERT INTO shops (name,kind,payee_name,agreement_version)
                    VALUES (?,?,?,?)', [$shopName, $admin === null ? 'marketplace' : 'platform',
                        $payee === '' ? null : $payee, $payee === '' ? null : self::AGREEMENT_VERSION]);
                $id = $this->insert('INSERT INTO users (email,password_hash,role,shop_id) VALUES (?,?,?,?)',
                    [$email, password_hash($password, PASSWORD_DEFAULT), $shop ? 'merchant' : 'buyer', $shop]);
                if ($shop) {
                    $this->query("INSERT INTO shop_members (user_id,shop_id,position) VALUES (?,?,'owner')", [$id, $shop]);
                    $this->audit($admin ?? $id, 'shop.applied', $shop, ['owner_id' => $id]);
                }
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('邮箱已注册。');
            }
            throw $error;
        }
    }

    public function provisionOperator(string $email, string $password, string $role): int
    {
        if (!in_array($role, ['admin', 'finance'], true)) {
            throw new RuleViolation('运营角色须为 admin 或 finance。');
        }
        return $this->insert('INSERT INTO users (email,password_hash,role) VALUES (?,?,?)',
            [self::validateAccount($email, $password), password_hash($password, PASSWORD_DEFAULT), $role]);
    }

    public function reviewShop(int $admin, int $shop, bool $approve): void
    {
        $this->actor($admin, 'admin');
        $this->transaction(function () use ($admin, $shop, $approve): void {
            $application = $this->one("SELECT kind,payee_name,agreement_version FROM shops
                WHERE id=? AND status='pending' FOR UPDATE", [$shop]);
            if (!$application) {
                throw new RuleViolation('该店铺已处理或不存在。');
            }
            if ($approve && (!$application['payee_name'] || !$application['agreement_version'])) {
                throw new RuleViolation('店主须先填写收款主体并确认结算协议。');
            }
            $status = $approve ? 'active' : 'rejected';
            $this->query('UPDATE shops SET status=? WHERE id=?', [$status, $shop]);
            $this->audit($admin, 'shop.reviewed', $shop, ['status' => $status] + $application);
        });
    }

    public function submitShopAgreement(int $owner, string $payee): void
    {
        $payee = trim($payee);
        if ($payee === '' || strlen($payee) > 160) {
            throw new RuleViolation('请填写有效的收款主体名称。');
        }
        $this->transaction(function () use ($owner, $payee): void {
            $actor = $this->shopActor($owner, 'owner');
            $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
            if (!in_array($shop['status'], ['pending', 'rejected'], true)) {
                throw new RuleViolation('已审核店铺的收款主体及协议不可直接变更。');
            }
            $this->query("UPDATE shops SET status='pending',payee_name=?,agreement_version=? WHERE id=?",
                [$payee, self::AGREEMENT_VERSION, $actor['shop_id']]);
            $this->audit($owner, 'shop.agreement_submitted', (int) $actor['shop_id'], ['version' => self::AGREEMENT_VERSION]);
        });
    }

    public function setShopSuspended(int $admin, int $shop, bool $suspended, string $reason): void
    {
        $this->actor($admin, 'admin');
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 250) {
            throw new RuleViolation('请填写经营状态变更原因。');
        }
        $this->transaction(function () use ($admin, $shop, $suspended, $reason): void {
            $from = $suspended ? 'active' : 'suspended';
            $to = $suspended ? 'suspended' : 'active';
            $update = $this->query('UPDATE shops SET status=? WHERE id=? AND status=?', [$to, $shop, $from]);
            if (!$update->rowCount()) {
                throw new RuleViolation('店铺状态已变化，请刷新后重试。');
            }
            $this->audit($admin, 'shop.status_changed', $shop, ['from' => $from, 'to' => $to, 'reason' => $reason]);
        });
    }

    public function addShopMember(int $owner, string $email, string $password, string $position): int
    {
        $email = self::validateAccount($email, $password);
        if (!isset(self::STAFF_POSITIONS[$position])) {
            throw new RuleViolation('请选择有效的店铺岗位。');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        try {
            return $this->transaction(function () use ($owner, $email, $passwordHash, $position): int {
                $actor = $this->shopActor($owner, 'owner');
                $id = $this->insert("INSERT INTO users (email,password_hash,role,shop_id) VALUES (?,?,'merchant',?)",
                    [$email, $passwordHash, $actor['shop_id']]);
                $this->query('INSERT INTO shop_members (user_id,shop_id,position) VALUES (?,?,?)',
                    [$id, $actor['shop_id'], $position]);
                $this->audit($owner, 'shop.member_added', (int) $actor['shop_id'], ['user_id' => $id, 'position' => $position]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('邮箱已注册。');
            }
            throw $error;
        }
    }

    public function setShopMemberEnabled(int $owner, int $member, bool $enabled): void
    {
        $this->transaction(function () use ($owner, $member, $enabled): void {
            $actor = $this->shopActor($owner, 'owner');
            $target = $this->one("SELECT enabled FROM shop_members
                WHERE user_id=? AND shop_id=? AND position<>'owner' FOR UPDATE", [$member, $actor['shop_id']]);
            if (!$target) {
                throw new RuleViolation('店铺成员不存在或不可停用。');
            }
            if ((bool) $target['enabled'] !== $enabled) {
                $this->query('UPDATE shop_members SET enabled=?,access_version=access_version+1 WHERE user_id=?',
                    [(int) $enabled, $member]);
                $this->audit($owner, 'shop.member_access_changed', (int) $actor['shop_id'],
                    ['user_id' => $member, 'enabled' => $enabled]);
            }
        });
    }

    public function merchantLedger(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'finance');
        return $this->rows('SELECT id,merchant_order_id,kind,gross,commission,settlement_id,created_at
            FROM ledger WHERE shop_id=? ORDER BY id DESC LIMIT 100', [$actor['shop_id']]);
    }

    public function saveProduct(
        int $merchant, int $id, string $sku, string $name, int $price, int $stock, string $status, ?int $version = null,
    ): int
    {
        $sku = trim($sku);
        $name = trim($name);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku) || $name === '' || strlen($name) > 160
            || $price < 1 || $price > 100_000_000 || $stock < 0 || $stock > 1_000_000_000
            || !in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new RuleViolation('请检查 SKU、名称、价格、库存和商品状态。');
        }
        try {
            return $this->transaction(function () use ($merchant, $id, $sku, $name, $price, $stock, $status, $version): int {
                $actor = $this->shopActor($merchant, 'catalog');
                $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
                if (!$shop || !in_array($shop['status'], ['active', 'suspended'], true)) {
                    throw new RuleViolation('店铺通过审核后可维护商品。');
                }
                if ($id) {
                    $previous = $this->one('SELECT reserved,version FROM products WHERE id=? AND shop_id=? FOR UPDATE',
                        [$id, $actor['shop_id']]);
                    if (!$previous || $stock < (int) $previous['reserved']) {
                        throw new RuleViolation('商品不存在，或库存低于已预留数量。');
                    }
                    if ($version !== null && $version !== (int) $previous['version']) {
                        throw new RuleViolation('商品或库存已更新，请重新打开表单。');
                    }
                    $this->query('UPDATE products SET sku=?,name=?,price=?,stock=?,status=?,version=version+1 WHERE id=?',
                        [$sku, $name, $price, $stock, $status, $id]);
                } else {
                    $id = $this->insert('INSERT INTO products (shop_id,sku,name,price,stock,status) VALUES (?,?,?,?,?,?)',
                        [$actor['shop_id'], $sku, $name, $price, $stock, $status]);
                }
                $this->audit($merchant, 'product.saved', $id, ['stock' => $stock, 'status' => $status]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('店铺内已存在该 SKU。');
            }
            throw $error;
        }
    }

    public function catalog(string $search = ''): array
    {
        $pattern = '%' . strtr(trim($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        return $this->rows("SELECT p.*,s.name AS shop_name FROM products p JOIN shops s ON s.id=p.shop_id
            WHERE p.status='active' AND s.status='active' AND (p.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!')
            ORDER BY p.id DESC LIMIT 100", [$pattern, $pattern]);
    }

    public function cartLines(int $buyer): array
    {
        $this->actor($buyer, 'buyer');
        return $this->rows("SELECT c.product_id,c.quantity,c.selected,c.revision,p.sku,p.name,p.price,s.name AS shop_name,
            p.stock-p.reserved AS available,(p.status='active' AND s.status='active') AS on_sale
            FROM cart_lines c JOIN products p ON p.id=c.product_id JOIN shops s ON s.id=p.shop_id
            WHERE c.buyer_id=? ORDER BY c.product_id", [$buyer]);
    }

    public function setCartLine(int $buyer, int $product, int $quantity, bool $selected, ?string $revision = null): void
    {
        $this->actor($buyer, 'buyer');
        if ($quantity < 0 || $quantity > 99) {
            throw new RuleViolation('每种 SKU 的购买数量须为 1–99。');
        }
        $this->transaction(function () use ($buyer, $product, $quantity, $selected, $revision): void {
            $this->lockCart($buyer);
            $line = $this->one('SELECT revision FROM cart_lines WHERE buyer_id=? AND product_id=?', [$buyer, $product]);
            if ($revision !== null && ($line['revision'] ?? null) !== $revision) {
                throw new RuleViolation('购物车已在其他页面更新，请刷新后重试。');
            }
            if ($quantity === 0) {
                $this->query('DELETE FROM cart_lines WHERE buyer_id=? AND product_id=?', [$buyer, $product]);
                return;
            }
            if (!$line) {
                $availability = $this->one("SELECT p.id FROM products p JOIN shops s ON s.id=p.shop_id
                    WHERE p.id=? AND p.status='active' AND s.status='active'", [$product]);
                if (!$availability) {
                    throw new RuleViolation('该商品目前不可加入购物车。');
                }
                $count = (int) $this->one('SELECT COUNT(*) AS total FROM cart_lines WHERE buyer_id=?', [$buyer])['total'];
                if ($count >= 30) {
                    throw new RuleViolation('购物车最多容纳 30 种 SKU。');
                }
                $this->query('INSERT INTO cart_lines (buyer_id,product_id,quantity,selected,revision) VALUES (?,?,?,?,?)',
                    [$buyer, $product, $quantity, (int) $selected, bin2hex(random_bytes(16))]);
                return;
            }
            $this->query('UPDATE cart_lines SET quantity=?,selected=?,revision=? WHERE buyer_id=? AND product_id=?',
                [$quantity, (int) $selected, bin2hex(random_bytes(16)), $buyer, $product]);
        });
    }

    public function importSessionCart(int $buyer, array $cart): void
    {
        $this->actor($buyer, 'buyer');
        $this->transaction(function () use ($buyer, $cart): void {
            $this->lockCart($buyer);
            $remaining = 30 - (int) $this->one('SELECT COUNT(*) AS total FROM cart_lines WHERE buyer_id=?', [$buyer])['total'];
            foreach ($cart as $product => $quantity) {
                if ($remaining <= 0) {
                    break;
                }
                if (!is_int($product) || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                    continue;
                }
                $insert = $this->query('INSERT INTO cart_lines (buyer_id,product_id,quantity,revision)
                    SELECT ?,p.id,?,? FROM products p LEFT JOIN cart_lines c ON c.product_id=p.id AND c.buyer_id=?
                    WHERE p.id=? AND c.product_id IS NULL', [$buyer, $quantity, bin2hex(random_bytes(16)), $buyer, $product]);
                $remaining -= $insert->rowCount();
            }
        });
    }

    private function lockCart(int $buyer): void
    {
        $this->one('SELECT id FROM users WHERE id=? FOR UPDATE', [$buyer]);
    }

    private function selectedCart(int $buyer): array
    {
        $lines = $this->rows('SELECT product_id,quantity,revision FROM cart_lines
            WHERE buyer_id=? AND selected=1 ORDER BY product_id', [$buyer]);
        return [array_map('intval', array_column($lines, 'quantity', 'product_id')),
            array_column($lines, 'revision', 'product_id')];
    }

    public static function allocate(int $amount, array $weights): array
    {
        if (!$amount) {
            return array_fill_keys(array_keys($weights), 0);
        }
        $total = array_sum($weights);
        $parts = $remainders = [];
        foreach ($weights as $id => $weight) {
            $scaled = $amount * $weight;
            $parts[$id] = intdiv($scaled, $total);
            $remainders[$id] = $scaled % $total;
        }
        uksort($remainders, static fn($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        foreach (array_slice(array_keys($remainders), 0, $amount - array_sum($parts)) as $id) {
            $parts[$id]++;
        }
        return $parts;
    }

    private function priceCart(array $cart, string $channel, string $destination, bool $lock): array
    {
        $destination = trim($destination);
        if (!in_array($channel, ['web', 'store'], true) || $destination === ''
            || strlen($destination) > 250 || !$cart || count($cart) > 30) {
            throw new RuleViolation('请选择履约方式并填写地址或提货点，购物车最多容纳 30 种 SKU。');
        }
        ksort($cart, SORT_NUMERIC);
        $items = $weights = $shops = [];
        foreach ($cart as $id => $quantity) {
            if (!is_int($id) || $id < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                throw new RuleViolation('每种 SKU 的购买数量须为 1–99。');
            }
            $product = $this->one("SELECT * FROM products WHERE id=? AND status='active'"
                . ($lock ? ' FOR UPDATE' : ''), [$id]);
            $shop = $product ? $this->one("SELECT id,commission_bps FROM shops WHERE id=? AND status='active'"
                . ($lock ? ' FOR UPDATE' : ''),
                [$product['shop_id']]) : null;
            if (!$shop || (int) $product['stock'] - (int) $product['reserved'] < $quantity) {
                throw new RuleViolation('商品已下架或可用库存不足，请更新购物车。');
            }
            $shopId = (int) $shop['id'];
            $price = (int) $product['price'];
            $weights[$id] = $price * $quantity;
            $items[$id] = [
                'shop' => $shopId, 'sku' => $product['sku'], 'name' => $product['name'],
                'quantity' => $quantity, 'unit_price' => $price, 'fee_bps' => (int) $shop['commission_bps'],
            ];
            $shops[$shopId] = ['shipping' => $channel === 'web' ? 800 : 0, 'total' => 0];
        }
        $subtotal = array_sum($weights);
        $discount = count($shops) >= 2 && $subtotal >= 20_000 ? 2_000 : 0;
        $parts = self::allocate($discount, $weights);
        foreach ($items as $id => &$item) {
            $item['discount'] = $parts[$id];
            $net = $weights[$id] - $parts[$id];
            $item['commission'] = intdiv($net * $item['fee_bps'], 10_000);
            $shops[$item['shop']]['total'] += $net;
        }
        unset($item);
        $shipping = array_sum(array_column($shops, 'shipping'));
        foreach ($shops as &$shop) {
            $shop['total'] += $shop['shipping'];
        }
        unset($shop);
        ksort($shops);
        return [
            'cart' => $cart, 'channel' => $channel, 'destination' => $destination,
            'items' => $items, 'shops' => $shops, 'subtotal' => $subtotal,
            'discount' => $discount, 'shipping' => $shipping, 'total' => $subtotal - $discount + $shipping,
        ];
    }

    public function quote(int $buyer, array $cart, string $channel, string $destination): array
    {
        $this->actor($buyer, 'buyer');
        return $this->recordQuote($buyer, $this->priceCart($cart, $channel, $destination, false));
    }

    public function quoteCart(int $buyer, string $channel, string $destination): array
    {
        $this->actor($buyer, 'buyer');
        return $this->transaction(function () use ($buyer, $channel, $destination): array {
            $this->lockCart($buyer);
            [$cart, $revisions] = $this->selectedCart($buyer);
            if (!$cart) {
                throw new RuleViolation('请先勾选要结算的商品。');
            }
            $snapshot = $this->priceCart($cart, $channel, $destination, false);
            $snapshot['cart_revisions'] = $revisions;
            return $this->recordQuote($buyer, $snapshot);
        });
    }

    private function recordQuote(int $buyer, array $snapshot): array
    {
        $token = bin2hex(random_bytes(16));
        $this->query('INSERT INTO quotes (token,buyer_id,snapshot,expires_at)
            VALUES (?,?,?,UTC_TIMESTAMP() + INTERVAL 10 MINUTE)',
            [$token, $buyer, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        return ['token' => $token] + $snapshot;
    }

    public function place(int $buyer, string $token, bool $requireCartQuote = false): int
    {
        $this->actor($buyer, 'buyer');
        return $this->transaction(function () use ($buyer, $token, $requireCartQuote): int {
            $this->lockCart($buyer);
            $quote = $this->one('SELECT *, expires_at <= UTC_TIMESTAMP() AS expired
                FROM quotes WHERE token=? AND buyer_id=? FOR UPDATE', [$token, $buyer]);
            if (!$quote) {
                throw new RuleViolation('报价不存在。');
            }
            if ($quote['order_id']) {
                return (int) $quote['order_id'];
            }
            if ($quote['expired']) {
                throw new RuleViolation('报价已过期，请重新报价。');
            }
            $snapshot = json_decode($quote['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $cartRevisions = $snapshot['cart_revisions'] ?? null;
            unset($snapshot['cart_revisions']);
            if ($requireCartQuote && $cartRevisions === null) {
                throw new RuleViolation('请重新计算购物车费用。');
            }
            if ($cartRevisions !== null) {
                [, $revisions] = $this->selectedCart($buyer);
                ksort($cartRevisions, SORT_NUMERIC);
                if ($cartRevisions !== $revisions) {
                    throw new RuleViolation('购物车内容已变化，请重新计算费用。');
                }
            }
            $shopIds = array_keys($snapshot['shops']);
            sort($shopIds, SORT_NUMERIC);
            // Match catalog edits and shop suspension: lock shops before inventory.
            foreach ($shopIds as $shop) {
                $currentShop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$shop]);
                if (($currentShop['status'] ?? null) !== 'active') {
                    throw new RuleViolation('购物车内有店铺暂停营业，请更新购物车。');
                }
            }
            $current = $this->priceCart($snapshot['cart'], $snapshot['channel'], $snapshot['destination'], true);
            // MySQL JSON normalizes object key order.
            if ($snapshot != $current) {
                throw new RuleViolation('商品或价格已变化，请重新报价。');
            }
            $order = $this->insert('INSERT INTO orders (buyer_id,channel,subtotal,discount,shipping,total,destination,payment_token,expires_at)
                VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP() + INTERVAL 15 MINUTE)', [
                $buyer, $current['channel'], $current['subtotal'], $current['discount'], $current['shipping'],
                $current['total'], $current['destination'], bin2hex(random_bytes(16)),
            ]);
            $merchantOrders = [];
            foreach ($current['shops'] as $shop => $totals) {
                $merchantOrders[$shop] = $this->insert('INSERT INTO merchant_orders (order_id,shop_id,total,shipping)
                    VALUES (?,?,?,?)', [$order, $shop, $totals['total'], $totals['shipping']]);
            }
            foreach ($current['items'] as $product => $item) {
                $this->query('UPDATE products SET reserved=reserved+?,version=version+1 WHERE id=?', [$item['quantity'], $product]);
                $this->query('INSERT INTO order_items
                    (merchant_order_id,product_id,sku,name,quantity,unit_price,discount,commission) VALUES (?,?,?,?,?,?,?,?)',
                    [$merchantOrders[$item['shop']], $product, $item['sku'], $item['name'], $item['quantity'],
                        $item['unit_price'], $item['discount'], $item['commission']]);
            }
            if ($cartRevisions !== null) {
                $this->query('DELETE FROM cart_lines WHERE buyer_id=? AND selected=1', [$buyer]);
            }
            $this->query('UPDATE quotes SET order_id=? WHERE token=?', [$order, $token]);
            $this->audit($buyer, 'order.placed', $order, ['channel' => $current['channel'], 'total' => $current['total']]);
            return $order;
        });
    }

    private function orderInventory(int $order, bool $consume): void
    {
        $items = $this->rows('SELECT i.product_id,i.quantity FROM order_items i
            JOIN merchant_orders m ON m.id=i.merchant_order_id WHERE m.order_id=? ORDER BY i.product_id', [$order]);
        foreach ($items as $item) {
            $this->query('UPDATE products SET reserved=reserved-?, stock=stock-?, version=version+1 WHERE id=?',
                [$item['quantity'], $consume ? $item['quantity'] : 0, $item['product_id']]);
        }
    }

    public function demoPay(int $buyer, int $orderId, string $reference, int $amount): int
    {
        if (!demoEnabled()) {
            throw new RuleViolation('演示支付未启用。');
        }
        $this->actor($buyer, 'buyer');
        if (!preg_match('/^[a-z0-9_-]{1,80}$/iD', $reference)) {
            throw new RuleViolation('支付流水编号无效。');
        }
        return $this->transaction(function () use ($buyer, $orderId, $reference, $amount): int {
            $order = $this->one('SELECT *, expires_at <= UTC_TIMESTAMP() AS expired
                FROM orders WHERE id=? AND buyer_id=? FOR UPDATE', [$orderId, $buyer]);
            if (!$order || (int) $order['total'] !== $amount) {
                throw new RuleViolation('订单或支付金额无效。');
            }
            $paid = $this->one('SELECT id,reference,provider FROM payments WHERE order_id=?', [$orderId]);
            if ($paid) {
                if ($paid['provider'] !== 'demo' || $paid['reference'] !== $reference) {
                    throw new RuleViolation('该订单已使用其他流水支付。');
                }
                return (int) $paid['id'];
            }
            if ($order['status'] !== 'unpaid' || $order['expired']) {
                throw new RuleViolation('该订单已失效。');
            }
            return $this->confirmPayment($orderId, $reference, $amount, 'demo', $buyer);
        });
    }

    private function confirmPayment(int $order, string $reference, int $amount, string $provider, ?int $actor): int
    {
        try {
            $payment = $this->insert('INSERT INTO payments (order_id,reference,amount,provider) VALUES (?,?,?,?)',
                [$order, $reference, $amount, $provider]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('支付流水已使用。');
            }
            throw $error;
        }
        $this->orderInventory($order, true);
        $this->query("UPDATE orders SET status='paid' WHERE id=?", [$order]);
        $this->query("UPDATE merchant_orders SET status='ready' WHERE order_id=?", [$order]);
        $this->audit($actor, 'payment.recorded', $order, ['payment_id' => $payment, 'amount' => $amount, 'provider' => $provider]);
        return $payment;
    }

    public static function validSandboxFields(string $token, string $reference, string $currency, int $amount): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $token) === 1
            && preg_match('/^[a-z0-9_-]{1,80}$/iD', $reference) === 1
            && preg_match('/^[A-Z]{3}$/D', $currency) === 1
            && $amount >= 1 && $amount <= 1_000_000_000_000;
    }

    public function recordSandboxReceipt(string $token, string $reference, string $currency, int $amount, string $digest): array
    {
        if (!demoEnabled() || !self::validSandboxFields($token, $reference, $currency, $amount)
            || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new RuleViolation('支付回执格式无效或沙箱未启用。');
        }
        return $this->transaction(function () use ($token, $reference, $currency, $amount, $digest): array {
            $this->query('INSERT INTO payment_receipts (reference,payment_token,currency,amount,payload_sha256)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id', [$reference, $token, $currency, $amount, $digest]);
            $receipt = $this->one('SELECT * FROM payment_receipts WHERE reference=? FOR UPDATE', [$reference]);
            if (!hash_equals($receipt['payload_sha256'], $digest) || $receipt['payment_token'] !== $token
                || $receipt['currency'] !== $currency || (int) $receipt['amount'] !== $amount) {
                throw new RuleViolation('该渠道流水对应的回执内容已存在。');
            }
            $id = (int) $receipt['id'];
            if ($receipt['status'] !== 'processing') {
                return $this->receiptResult($id, $receipt);
            }

            $order = $this->one('SELECT id,status,total,expires_at<=UTC_TIMESTAMP() AS expired
                FROM orders WHERE payment_token=? FOR UPDATE', [$token]);
            $orderId = $order ? (int) $order['id'] : null;
            $payment = null;
            $reason = null;
            switch (true) {
                case !$order:
                    $status = 'needs_review';
                    $reason = 'unknown_intent';
                    break;
                case $currency !== 'CNY' || (int) $order['total'] !== $amount:
                    $status = 'needs_review';
                    $reason = 'amount_or_currency_mismatch';
                    break;
                case $order['status'] === 'paid':
                    $status = 'refund_required';
                    $reason = 'duplicate_charge';
                    break;
                case $order['status'] === 'cancelled' || $order['expired']:
                    if ($order['status'] === 'unpaid') {
                        $this->closeUnpaidOrder($orderId, null, true);
                    }
                    $status = 'refund_required';
                    $reason = 'late_capture';
                    break;
                default:
                    $status = 'accepted';
                    $payment = $this->confirmPayment($orderId, $reference, $amount, 'sandbox', null);
                    break;
            }
            $this->query('UPDATE payment_receipts SET status=?,failure_code=?,order_id=?,payment_id=? WHERE id=?',
                [$status, $reason, $orderId, $payment, $id]);
            if ($reason !== null) {
                $this->audit(null, 'payment.receipt_exception', $id, ['reason' => $reason, 'order_id' => $orderId]);
            }
            return $this->receiptResult($id, ['status' => $status, 'payment_id' => $payment]);
        });
    }

    private function receiptResult(int $id, array $receipt): array
    {
        return ['receipt_id' => $id, 'status' => $receipt['status'],
            'payment_id' => $receipt['payment_id'] === null ? null : (int) $receipt['payment_id']];
    }

    public function recordSandboxRefund(int $finance, int $receipt, string $refundReference): void
    {
        $this->actor($finance, 'finance');
        if (!demoEnabled() || !preg_match('/^[a-z0-9_-]{1,80}$/iD', $refundReference)) {
            throw new RuleViolation('请填写有效的沙箱退款回执号。');
        }
        try {
            $this->transaction(function () use ($finance, $receipt, $refundReference): void {
                $row = $this->one('SELECT status,refund_reference FROM payment_receipts WHERE id=? FOR UPDATE', [$receipt]);
                if ($row && $row['status'] === 'refunded' && $row['refund_reference'] === $refundReference) {
                    return;
                }
                if (!$row || $row['status'] !== 'refund_required') {
                    throw new RuleViolation('回执不存在或当前无需登记退款。');
                }
                $this->query("UPDATE payment_receipts SET status='refunded',refund_reference=?,resolved_at=UTC_TIMESTAMP(),
                    resolved_by=? WHERE id=?", [$refundReference, $finance, $receipt]);
                $this->audit($finance, 'payment.exception_refunded', $receipt, ['reference' => $refundReference]);
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('沙箱退款回执号已使用。');
            }
            throw $error;
        }
    }

    public function cancel(int $orderId, ?int $buyer = null): bool
    {
        if ($buyer !== null) {
            $this->actor($buyer, 'buyer');
        }
        return $this->transaction(function () use ($orderId, $buyer): bool {
            $order = $this->one('SELECT buyer_id,status,expires_at <= UTC_TIMESTAMP() AS expired
                FROM orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order || ($buyer !== null && (int) $order['buyer_id'] !== $buyer)) {
                throw new RuleViolation('订单不存在。');
            }
            if ($order['status'] !== 'unpaid' || ($buyer === null && !$order['expired'])) {
                return false;
            }
            $this->closeUnpaidOrder($orderId, $buyer, (bool) $order['expired']);
            return true;
        });
    }

    private function closeUnpaidOrder(int $order, ?int $actor, bool $expired): void
    {
        $this->orderInventory($order, false);
        $this->query("UPDATE orders SET status='cancelled' WHERE id=?", [$order]);
        $this->query("UPDATE merchant_orders SET status='cancelled' WHERE order_id=?", [$order]);
        $this->audit($actor, 'order.cancelled', $order, ['expired' => $expired]);
    }

    public function expireOrders(): int
    {
        $count = 0;
        foreach ($this->rows("SELECT id FROM orders WHERE status='unpaid' AND expires_at<=UTC_TIMESTAMP()
            ORDER BY expires_at,id LIMIT 200") as $order) {
            $count += (int) $this->cancel((int) $order['id']);
        }
        return $count;
    }

    public function fulfill(int $merchant, int $id, string $tracking): void
    {
        $tracking = trim($tracking);
        if ($tracking === '' || strlen($tracking) > 120) {
            throw new RuleViolation('请填写物流单号或自提核验编号。');
        }
        $this->transaction(function () use ($merchant, $id, $tracking): void {
            $actor = $this->shopActor($merchant, 'fulfillment');
            $this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
            $order = $this->one('SELECT * FROM merchant_orders WHERE id=? AND shop_id=? FOR UPDATE',
                [$id, $actor['shop_id']]);
            if (!$order || $order['status'] !== 'ready') {
                throw new RuleViolation('子订单无权访问或当前状态不可履约。');
            }
            $commission = (int) $this->one('SELECT SUM(commission) AS total FROM order_items WHERE merchant_order_id=?',
                [$id])['total'];
            $this->query("UPDATE merchant_orders SET status='fulfilled',tracking=?,fulfilled_at=UTC_TIMESTAMP() WHERE id=?",
                [$tracking, $id]);
            $this->query("INSERT INTO ledger (shop_id,merchant_order_id,event_key,kind,gross,commission)
                VALUES (?,?,?,'sale',?,?)", [$actor['shop_id'], $id, 'sale-' . $id, $order['total'], $commission]);
            $this->audit($merchant, 'order.fulfilled', $id);
        });
    }

    public function requestRefund(int $buyer, int $itemId, int $quantity, string $reason, string $key): int
    {
        $this->actor($buyer, 'buyer');
        $reason = trim($reason);
        if ($quantity < 1 || $reason === '' || strlen($reason) > 250 || !preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new RuleViolation('请填写有效的售后数量和原因。');
        }
        return $this->transaction(function () use ($buyer, $itemId, $quantity, $reason, $key): int {
            $this->one('SELECT id FROM users WHERE id=? FOR UPDATE', [$buyer]);
            $existing = $this->one('SELECT id,item_id,quantity,reason FROM refunds WHERE buyer_id=? AND request_key=?',
                [$buyer, $key]);
            if ($existing) {
                if ((int) $existing['item_id'] !== $itemId || (int) $existing['quantity'] !== $quantity
                    || $existing['reason'] !== $reason) {
                    throw new RuleViolation('售后申请编号已使用。');
                }
                return (int) $existing['id'];
            }
            $item = $this->one("SELECT i.* FROM order_items i JOIN merchant_orders m ON m.id=i.merchant_order_id
                JOIN orders o ON o.id=m.order_id WHERE i.id=? AND o.buyer_id=? AND m.status='fulfilled' FOR UPDATE",
                [$itemId, $buyer]);
            if (!$item) {
                throw new RuleViolation('订单明细不存在或尚未履约。');
            }
            $pending = $this->rows("SELECT quantity FROM refunds WHERE item_id=? AND status='pending' FOR UPDATE",
                [$itemId]);
            if ($quantity > (int) $item['quantity'] - (int) $item['refunded_quantity'] - array_sum(array_column($pending, 'quantity'))) {
                throw new RuleViolation('当前可申请的数量不足。');
            }
            $id = $this->insert('INSERT INTO refunds (item_id,buyer_id,request_key,quantity,reason) VALUES (?,?,?,?,?)',
                [$itemId, $buyer, $key, $quantity, $reason]);
            $this->audit($buyer, 'refund.requested', $id, ['quantity' => $quantity]);
            return $id;
        });
    }

    public function decideRefund(int $merchant, int $id, bool $approve): int
    {
        return $this->transaction(function () use ($merchant, $id, $approve): int {
            $actor = $this->shopActor($merchant, 'support');
            $this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
            $refund = $this->one('SELECT r.*,i.merchant_order_id,i.quantity AS item_quantity,i.unit_price,
                i.discount,i.commission,i.refunded_quantity
                FROM refunds r JOIN order_items i ON i.id=r.item_id JOIN merchant_orders m ON m.id=i.merchant_order_id
                WHERE r.id=? AND m.shop_id=? FOR UPDATE', [$id, $actor['shop_id']]);
            if (!$refund) {
                throw new RuleViolation('售后申请不存在。');
            }
            $status = $approve ? 'approved' : 'rejected';
            if ($refund['status'] === $status) {
                return (int) $refund['amount'];
            }
            if ($refund['status'] !== 'pending') {
                throw new RuleViolation('售后申请已处理。');
            }
            $amount = 0;
            if ($approve) {
                $qty = (int) $refund['item_quantity'];
                $before = (int) $refund['refunded_quantity'];
                $after = $before + (int) $refund['quantity'];
                if ($after > $qty) {
                    throw new RuleViolation('退款数量超过购买数量。');
                }
                $net = $qty * (int) $refund['unit_price'] - (int) $refund['discount'];
                $amount = intdiv($net * $after, $qty) - intdiv($net * $before, $qty);
                $commission = intdiv((int) $refund['commission'] * $after, $qty)
                    - intdiv((int) $refund['commission'] * $before, $qty);
                $this->query('UPDATE order_items SET refunded_quantity=? WHERE id=?', [$after, $refund['item_id']]);
                $this->query("INSERT INTO ledger (shop_id,merchant_order_id,event_key,kind,gross,commission)
                    VALUES (?,?,?,'refund',?,?)", [
                    $actor['shop_id'], $refund['merchant_order_id'], 'refund-' . $id, -$amount, -$commission,
                ]);
            }
            $this->query('UPDATE refunds SET status=?,amount=?,decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=?',
                [$status, $amount, $merchant, $id]);
            $this->audit($merchant, 'refund.decided', $id, ['status' => $status, 'amount' => $amount]);
            return $amount;
        });
    }

    public function settle(int $finance, int $shop): int
    {
        $this->actor($finance, 'finance', 'admin');
        return $this->transaction(function () use ($finance, $shop): int {
            if (!$this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$shop])) {
                throw new RuleViolation('店铺不存在。');
            }
            $total = $this->one('SELECT COUNT(*) AS entry_count,COALESCE(SUM(gross-commission),0) AS net
                FROM ledger WHERE shop_id=? AND settlement_id IS NULL', [$shop]);
            if (!(int) $total['entry_count'] || (int) $total['net'] < 0) {
                throw new RuleViolation('当前没有可结算的分录或净额为负，负余额将继续结转。');
            }
            $id = $this->insert('INSERT INTO settlements (shop_id,created_by,amount) VALUES (?,?,?)',
                [$shop, $finance, $total['net']]);
            $this->query('UPDATE ledger SET settlement_id=? WHERE shop_id=? AND settlement_id IS NULL', [$id, $shop]);
            $this->audit($finance, 'settlement.created', $id, ['net' => (int) $total['net']]);
            return $id;
        });
    }
}
