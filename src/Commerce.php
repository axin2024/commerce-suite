<?php

declare(strict_types=1);

final class RuleViolation extends RuntimeException {}

final class Commerce
{
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
        return $this->actor($id);
    }

    public function authenticate(string $email, string $password): ?int
    {
        $user = $this->one('SELECT id,password_hash FROM users WHERE email=?', [strtolower(trim($email))]);
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

    public function register(string $email, string $password, string $shopName = ''): int
    {
        $email = self::validateAccount($email, $password);
        $shopName = trim($shopName);
        if (strlen($shopName) > 100) {
            throw new RuleViolation('店铺名称过长。');
        }
        try {
            return $this->transaction(function () use ($email, $password, $shopName): int {
                $shop = $shopName === '' ? null : $this->insert('INSERT INTO shops (name) VALUES (?)', [$shopName]);
                $id = $this->insert('INSERT INTO users (email,password_hash,role,shop_id) VALUES (?,?,?,?)',
                    [$email, password_hash($password, PASSWORD_DEFAULT), $shop ? 'merchant' : 'buyer', $shop]);
                if ($shop) {
                    $this->audit($id, 'shop.applied', $shop);
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
            $status = $approve ? 'active' : 'rejected';
            $changed = $this->query("UPDATE shops SET status=? WHERE id=? AND status='pending'", [$status, $shop]);
            if (!$changed->rowCount()) {
                throw new RuleViolation('该店铺已处理或不存在。');
            }
            $this->audit($admin, 'shop.reviewed', $shop, ['status' => $status]);
        });
    }

    public function saveProduct(
        int $merchant, int $id, string $sku, string $name, int $price, int $stock, string $status, ?int $version = null,
    ): int
    {
        $actor = $this->actor($merchant, 'merchant');
        $sku = trim($sku);
        $name = trim($name);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku) || $name === '' || strlen($name) > 160
            || $price < 1 || $price > 100_000_000 || $stock < 0 || $stock > 1_000_000_000
            || !in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new RuleViolation('请检查 SKU、名称、价格、库存和商品状态。');
        }
        try {
            return $this->transaction(function () use ($actor, $merchant, $id, $sku, $name, $price, $stock, $status, $version): int {
                $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
                if (!$shop || $shop['status'] !== 'active') {
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
            $shop = $product ? $this->one("SELECT id,commission_bps FROM shops WHERE id=? AND status='active'",
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
        $snapshot = $this->priceCart($cart, $channel, $destination, false);
        $token = bin2hex(random_bytes(16));
        $this->query('INSERT INTO quotes (token,buyer_id,snapshot,expires_at)
            VALUES (?,?,?,UTC_TIMESTAMP() + INTERVAL 10 MINUTE)',
            [$token, $buyer, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        return ['token' => $token] + $snapshot;
    }

    public function place(int $buyer, string $token): int
    {
        $this->actor($buyer, 'buyer');
        return $this->transaction(function () use ($buyer, $token): int {
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
            $current = $this->priceCart($snapshot['cart'], $snapshot['channel'], $snapshot['destination'], true);
            // MySQL JSON normalizes object key order.
            if ($snapshot != $current) {
                throw new RuleViolation('商品或价格已变化，请重新报价。');
            }
            $order = $this->insert('INSERT INTO orders (buyer_id,channel,subtotal,discount,shipping,total,destination,expires_at)
                VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP() + INTERVAL 15 MINUTE)', [
                $buyer, $current['channel'], $current['subtotal'], $current['discount'], $current['shipping'],
                $current['total'], $current['destination'],
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
            $paid = $this->one('SELECT id,reference FROM payments WHERE order_id=?', [$orderId]);
            if ($paid) {
                if ($paid['reference'] !== $reference) {
                    throw new RuleViolation('该订单已使用其他流水支付。');
                }
                return (int) $paid['id'];
            }
            if ($order['status'] !== 'unpaid' || $order['expired']) {
                throw new RuleViolation('该订单已失效。');
            }
            try {
                $payment = $this->insert('INSERT INTO payments (order_id,reference,amount) VALUES (?,?,?)',
                    [$orderId, $reference, $amount]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                    throw new RuleViolation('支付流水已使用。');
                }
                throw $error;
            }
            $this->orderInventory($orderId, true);
            $this->query("UPDATE orders SET status='paid' WHERE id=?", [$orderId]);
            $this->query("UPDATE merchant_orders SET status='ready' WHERE order_id=?", [$orderId]);
            $this->audit($buyer, 'payment.recorded', $orderId, ['payment_id' => $payment, 'amount' => $amount]);
            return $payment;
        });
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
            $this->orderInventory($orderId, false);
            $this->query("UPDATE orders SET status='cancelled' WHERE id=?", [$orderId]);
            $this->query("UPDATE merchant_orders SET status='cancelled' WHERE order_id=?", [$orderId]);
            $this->audit($buyer, 'order.cancelled', $orderId, ['expired' => (bool) $order['expired']]);
            return true;
        });
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
        $actor = $this->actor($merchant, 'merchant');
        $tracking = trim($tracking);
        if ($tracking === '' || strlen($tracking) > 120) {
            throw new RuleViolation('请填写物流单号或自提核验编号。');
        }
        $this->transaction(function () use ($actor, $merchant, $id, $tracking): void {
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
        $actor = $this->actor($merchant, 'merchant');
        return $this->transaction(function () use ($actor, $merchant, $id, $approve): int {
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
