<?php

declare(strict_types=1);

trait InventoryOperations
{
    public function createWarehouse(int $merchant, string $code, string $name, string $address): int
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        $address = trim($address);
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) || $name === '' || strlen($name) > 100
            || $address === '' || strlen($address) > 250) {
            throw new RuleViolation('请填写有效的仓库编号、名称和地址。');
        }
        try {
            return $this->transaction(function () use ($merchant, $code, $name, $address): int {
                $shop = $this->lockMerchantCatalog($merchant);
                $warehouse = $this->insert('INSERT INTO warehouses (shop_id,code,name,address) VALUES (?,?,?,?)',
                    [$shop, $code, $name, $address]);
                $this->audit($merchant, 'inventory.warehouse_created', $warehouse, ['code' => $code, 'shop_id' => $shop]);
                return $warehouse;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('本店已存在这个仓库编号。');
            }
            throw $error;
        }
    }

    public function merchantWarehouses(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'catalog');
        return $this->rows("SELECT id,code,name,address FROM warehouses WHERE shop_id=? ORDER BY (code='MAIN') DESC,id",
            [$actor['shop_id']]);
    }

    public function merchantStock(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'catalog');
        return $this->rows('SELECT b.* FROM warehouse_stock b JOIN products p ON p.id=b.product_id WHERE p.shop_id=?
            ORDER BY b.product_id,b.warehouse_id', [$actor['shop_id']]);
    }

    private function mainWarehouse(int $shop): int
    {
        $warehouse = $this->one("SELECT id FROM warehouses WHERE shop_id=? AND code='MAIN'", [$shop]);
        if (!$warehouse) {
            throw new LogicException('Shop has no main warehouse.');
        }
        return (int) $warehouse['id'];
    }

    public function adjustStock(int $merchant, int $product, int $stock, int $version, ?int $warehouse = null): void
    {
        if ($stock < 0 || $stock > 1_000_000_000) {
            throw new RuleViolation('请检查库存数量。');
        }
        $this->transaction(function () use ($merchant, $product, $stock, $version, $warehouse): void {
            $shop = $this->lockMerchantCatalog($merchant);
            $productRow = $this->one('SELECT stock,version FROM products WHERE id=? AND shop_id=? FOR UPDATE', [$product, $shop]);
            $location = $warehouse ?? $this->mainWarehouse($shop);
            if (!$productRow || !$this->one('SELECT id FROM warehouses WHERE id=? AND shop_id=?', [$location, $shop])) {
                throw new RuleViolation('商品或仓库不存在。');
            }
            if ($version !== (int) $productRow['version']) {
                throw new RuleViolation('库存已更新，请刷新后再调整。');
            }
            $balance = $this->one('SELECT stock,reserved FROM warehouse_stock WHERE product_id=? AND warehouse_id=? FOR UPDATE',
                [$product, $location]);
            if ($stock < (int) ($balance['reserved'] ?? 0)) {
                throw new RuleViolation('库存低于该仓已预留数量。');
            }
            $before = (int) ($balance['stock'] ?? 0);
            $total = (int) $productRow['stock'] - $before + $stock;
            if ($total > 1_000_000_000) {
                throw new RuleViolation('各仓库存合计超出上限。');
            }
            if ($balance) {
                $this->query('UPDATE warehouse_stock SET stock=? WHERE product_id=? AND warehouse_id=?', [$stock, $product, $location]);
            } else {
                $this->query('INSERT INTO warehouse_stock (product_id,warehouse_id,stock) VALUES (?,?,?)', [$product, $location, $stock]);
            }
            $this->query('UPDATE products SET stock=?,version=version+1 WHERE id=?', [$total, $product]);
            $this->audit($merchant, 'inventory.stock_adjusted', $product, ['warehouse_id' => $location, 'before' => $before, 'after' => $stock]);
        });
    }

    private function allocateWarehouses(int $product, int $quantity, ?int $pickupWarehouse, bool $lock): array
    {
        $stock = $this->rows("SELECT b.warehouse_id,b.stock-b.reserved AS available FROM warehouse_stock b
            JOIN warehouses w ON w.id=b.warehouse_id WHERE b.product_id=?"
            . ($pickupWarehouse === null ? '' : ' AND b.warehouse_id=?')
            . " ORDER BY (w.code='MAIN') DESC,b.warehouse_id" . ($lock ? ' FOR UPDATE' : ''),
            $pickupWarehouse === null ? [$product] : [$product, $pickupWarehouse]);
        $allocation = [];
        foreach ($stock as $balance) {
            $take = min($quantity, (int) $balance['available']);
            if ($take > 0) {
                $allocation[(int) $balance['warehouse_id']] = $take;
                $quantity -= $take;
            }
            if ($quantity === 0) {
                return $allocation;
            }
        }
        throw new RuleViolation('选定履约方式的仓库库存不足，请调整数量或渠道。');
    }

    private function reserveInventory(int $item, int $product, array $allocation): void
    {
        $this->query('UPDATE products SET reserved=reserved+?,version=version+1 WHERE id=?', [array_sum($allocation), $product]);
        foreach ($allocation as $warehouse => $quantity) {
            $updated = $this->query('UPDATE warehouse_stock SET reserved=reserved+? WHERE product_id=? AND warehouse_id=?',
                [$quantity, $product, $warehouse]);
            if ($updated->rowCount() !== 1) {
                throw new LogicException('Warehouse inventory balance is missing.');
            }
            $this->query('INSERT INTO order_inventory_allocations (order_item_id,warehouse_id,quantity) VALUES (?,?,?)',
                [$item, $warehouse, $quantity]);
        }
    }

    private function orderInventory(int $order, bool $consume): void
    {
        $lines = $this->rows('SELECT i.id,i.product_id,i.quantity FROM order_items i
            JOIN merchant_orders m ON m.id=i.merchant_order_id WHERE m.order_id=? ORDER BY i.product_id', [$order]);
        foreach ($lines as $line) {
            $allocations = $this->rows("SELECT id,warehouse_id,quantity FROM order_inventory_allocations
                WHERE order_item_id=? AND state='held' ORDER BY warehouse_id", [$line['id']]);
            if (array_sum(array_column($allocations, 'quantity')) !== (int) $line['quantity']) {
                throw new LogicException('Order inventory holds do not match order quantities.');
            }
            $this->query('UPDATE products SET reserved=reserved-?,stock=stock-?,version=version+1 WHERE id=?',
                [$line['quantity'], $consume ? $line['quantity'] : 0, $line['product_id']]);
            foreach ($allocations as $held) {
                $updated = $this->query('UPDATE warehouse_stock SET reserved=reserved-?,stock=stock-? WHERE product_id=? AND warehouse_id=?',
                    [$held['quantity'], $consume ? $held['quantity'] : 0, $line['product_id'], $held['warehouse_id']]);
                if ($updated->rowCount() !== 1) {
                    throw new LogicException('Warehouse inventory balance is missing.');
                }
            }
            $this->query("UPDATE order_inventory_allocations SET state=? WHERE order_item_id=? AND state='held'",
                [$consume ? 'consumed' : 'released', $line['id']]);
        }
    }
}
