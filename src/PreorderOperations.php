<?php

declare(strict_types=1);

trait PreorderOperations
{
    public function createPreorder(
        int $merchant, int $product, int $warehouse, string $code, int $quota, string $cutoff, string $expected,
    ): int {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) || $quota < 1 || $quota > 1_000_000
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $cutoff) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expected)) {
            throw new RuleViolation('请填写有效的批次编号、额度、截单时间和预计到货日。');
        }
        $utc = new DateTimeZone('UTC');
        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $cutoff, $utc);
        $arrival = DateTimeImmutable::createFromFormat('!Y-m-d', $expected, $utc);
        if (!$deadline || $deadline->format('Y-m-d\TH:i') !== $cutoff || $deadline->getTimestamp() <= time()
            || !$arrival || $arrival->format('Y-m-d') !== $expected || $expected < $deadline->format('Y-m-d')) {
            throw new RuleViolation('请填写有效的批次编号、额度、截单时间和预计到货日。');
        }
        try {
            return $this->transaction(function () use ($merchant, $product, $warehouse, $code, $quota, $deadline, $expected): int {
                $shop = $this->lockMerchantCatalog($merchant);
                $sku = $this->one("SELECT id FROM products WHERE id=? AND shop_id=? AND status='active' FOR UPDATE", [$product, $shop]);
                $location = $this->one('SELECT id FROM warehouses WHERE id=? AND shop_id=?', [$warehouse, $shop]);
                if (!$sku || !$location) {
                    throw new RuleViolation('请选择本店的在售 SKU 和库存仓。');
                }
                $batch = $this->insert('INSERT INTO preorder_batches (product_id,warehouse_id,code,quota,closes_at,expected_on)
                    VALUES (?,?,?,?,?,?)', [$product, $warehouse, $code, $quota, $deadline->format('Y-m-d H:i:s'), $expected]);
                $this->audit($merchant, 'preorder.created', $batch, ['product_id' => $product, 'warehouse_id' => $warehouse, 'quota' => $quota]);
                return $batch;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('该 SKU 已存在同编号的预售批次。');
            }
            throw $error;
        }
    }

    public function merchantPreorders(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'catalog');
        return $this->rows("SELECT b.*,p.sku,w.name AS warehouse_name,b.closes_at<=UTC_TIMESTAMP() AS ended
            FROM preorder_batches b JOIN products p ON p.id=b.product_id JOIN warehouses w ON w.id=b.warehouse_id
            WHERE p.shop_id=? ORDER BY b.id DESC LIMIT 100", [$actor['shop_id']]);
    }

    public function catalogPreorders(array $products, string $channel): array
    {
        if (!$products) {
            return [];
        }
        $markers = implode(',', array_fill(0, count($products), '?'));
        $pickup = 'r.pickup=1 AND l.id IS NOT NULL';
        $channelFilter = match ($channel) {
            'store' => ' AND ' . $pickup,
            'web' => ' AND r.delivery=1',
            default => ' AND (r.delivery=1 OR (' . $pickup . '))',
        };
        return $this->rows("SELECT b.id,b.product_id,b.code,b.expected_on,b.closes_at,b.quota-b.held-b.sold AS available,
            CASE WHEN r.pickup=1 THEN l.name END AS pickup_name
            FROM preorder_batches b JOIN products p ON p.id=b.product_id JOIN product_releases r ON r.id=p.release_id
            JOIN shops s ON s.id=p.shop_id LEFT JOIN pickup_locations l ON l.warehouse_id=b.warehouse_id AND l.enabled=1
            WHERE b.product_id IN ($markers) AND b.state='open' AND b.closes_at>UTC_TIMESTAMP()
                AND b.held+b.sold<b.quota AND p.status='active' AND s.status='active'"
            . $channelFilter . ' ORDER BY b.expected_on,b.id', $products);
    }

    private function lockPreorderBatch(int $merchant, int $batch): array
    {
        $shop = $this->lockMerchantCatalog($merchant);
        $identity = $this->one('SELECT b.product_id FROM preorder_batches b JOIN products p ON p.id=b.product_id
            WHERE b.id=? AND p.shop_id=?', [$batch, $shop]);
        if (!$identity) {
            throw new RuleViolation('预售批次不存在或无权操作。');
        }
        $product = $this->one('SELECT id,stock FROM products WHERE id=? FOR UPDATE', [$identity['product_id']]);
        $row = $this->one('SELECT * FROM preorder_batches WHERE id=? FOR UPDATE', [$batch]);
        return $row + ['product_stock' => (int) $product['stock']];
    }

    public function closePreorder(int $merchant, int $batch): void
    {
        $this->transaction(function () use ($merchant, $batch): void {
            $row = $this->lockPreorderBatch($merchant, $batch);
            if ($row['state'] === 'open') {
                $this->query("UPDATE preorder_batches SET state='closed' WHERE id=?", [$batch]);
                $this->audit($merchant, 'preorder.closed', $batch);
            }
        });
    }

    public function receivePreorder(int $merchant, int $batch, int $units): void
    {
        if ($units < 1 || $units > 1_000_000_000) {
            throw new RuleViolation('请填写有效的到货数量。');
        }
        $this->transaction(function () use ($merchant, $batch, $units): void {
            $row = $this->lockPreorderBatch($merchant, $batch);
            if ($row['state'] === 'received') {
                if ($row['received_units'] !== $units) {
                    throw new RuleViolation('该批次已经入库，实收数量不可重复登记。');
                }
                return;
            }
            if ($units < $row['held'] + $row['sold']) {
                throw new RuleViolation('到货数量不足以覆盖本批已付款及待付款；请补足后再整批入库。');
            }
            $physical = $units - $row['sold'];
            if ($row['product_stock'] + $physical > 1_000_000_000) {
                throw new RuleViolation('各仓库存合计超出上限。');
            }
            $this->query("INSERT INTO order_inventory_allocations (order_item_id,warehouse_id,quantity,state)
                SELECT order_item_id,?,quantity,CASE state WHEN 'paid' THEN 'consumed' ELSE 'held' END
                FROM order_preorders WHERE batch_id=? AND state IN ('held','paid')", [$row['warehouse_id'], $batch]);
            $this->query('INSERT INTO warehouse_stock (product_id,warehouse_id,stock,reserved) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE stock=stock+?,reserved=reserved+?',
                [$row['product_id'], $row['warehouse_id'], $physical, $row['held'], $physical, $row['held']]);
            $this->query('UPDATE products SET stock=stock+?,reserved=reserved+?,version=version+1 WHERE id=?',
                [$physical, $row['held'], $row['product_id']]);
            $this->query("UPDATE preorder_batches SET state='received',received_units=?,received_at=UTC_TIMESTAMP() WHERE id=?", [$units, $batch]);
            $this->audit($merchant, 'preorder.received', $batch,
                ['units' => $units, 'paid' => (int) $row['sold'], 'held' => (int) $row['held'], 'available' => $physical - $row['held']]);
        });
    }

    private function selectPreorder(int $product, int $batch, int $quantity, bool $lock): array
    {
        $row = $this->one("SELECT id,warehouse_id,code,expected_on,quota-held-sold AS available
            FROM preorder_batches WHERE id=? AND product_id=? AND state='open' AND closes_at>UTC_TIMESTAMP()"
            . ($lock ? ' FOR UPDATE' : ''), [$batch, $product]);
        if (!$row || $row['available'] < $quantity) {
            throw new RuleViolation('预售批次已停止接单或剩余额度不足。');
        }
        unset($row['available']);
        return $row;
    }

    private function reservePreorder(int $item, int $batch, int $quantity): void
    {
        $updated = $this->query("UPDATE preorder_batches SET held=held+?
            WHERE id=? AND state='open' AND closes_at>UTC_TIMESTAMP() AND quota-held-sold>=?", [$quantity, $batch, $quantity]);
        if ($updated->rowCount() !== 1) {
            throw new RuleViolation('预售额度已变化，请重新报价。');
        }
        $this->query('INSERT INTO order_preorders (order_item_id,batch_id,quantity) VALUES (?,?,?)', [$item, $batch, $quantity]);
    }

    private function settlePreorderAllocation(int $item, bool $consume): bool
    {
        $row = $this->one('SELECT r.batch_id,r.quantity,r.state,b.state AS batch_state
            FROM order_preorders r JOIN preorder_batches b ON b.id=r.batch_id
            WHERE r.order_item_id=? FOR UPDATE', [$item]);
        if (!$row) {
            return true;
        }
        if ($row['state'] !== 'held') {
            throw new LogicException('Order preorder allocation is no longer held.');
        }
        $this->query('UPDATE preorder_batches SET held=held-?,sold=sold+? WHERE id=?',
            [$row['quantity'], $consume ? $row['quantity'] : 0, $row['batch_id']]);
        $this->query('UPDATE order_preorders SET state=? WHERE order_item_id=?', [$consume ? 'paid' : 'released', $item]);
        return $row['batch_state'] === 'received';
    }

    public function hasPendingPreorders(int $order): bool
    {
        return $this->one("SELECT r.order_item_id FROM order_items i
            JOIN order_preorders r ON r.order_item_id=i.id JOIN preorder_batches b ON b.id=r.batch_id
            WHERE i.merchant_order_id=? AND r.state='paid' AND b.state<>'received' LIMIT 1", [$order]) !== null;
    }
}
