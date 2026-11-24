<?php

declare(strict_types=1);

trait PickupOperations
{
    public function savePickupLocation(
        int $owner, int $location, int $warehouse, string $code, string $name, string $address, bool $enabled,
    ): int {
        $code = strtoupper(trim($code));
        $name = trim($name);
        $address = trim($address);
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) || $name === '' || strlen($name) > 100
            || $address === '' || strlen($address) > 250) {
            throw new RuleViolation('请填写有效的门店编号、名称和地址。');
        }
        try {
            return $this->transaction(function () use ($owner, $location, $warehouse, $code, $name, $address, $enabled): int {
                $actor = $this->shopActor($owner, 'owner');
                $shop = $this->one('SELECT id,status FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
                if (!in_array($shop['status'], ['active', 'suspended'], true)) {
                    throw new RuleViolation('店铺通过审核后可设置自提门店。');
                }
                if ($location === 0) {
                    if (!$this->one('SELECT id FROM warehouses WHERE id=? AND shop_id=?', [$warehouse, $shop['id']])) {
                        throw new RuleViolation('请选择本店仓库。');
                    }
                    $location = $this->insert('INSERT INTO pickup_locations (shop_id,warehouse_id,code,name,address,enabled)
                        VALUES (?,?,?,?,?,?)', [$shop['id'], $warehouse, $code, $name, $address, (int) $enabled]);
                } else {
                    $old = $this->one('SELECT id FROM pickup_locations WHERE id=? AND shop_id=? FOR UPDATE',
                        [$location, $shop['id']]);
                    if (!$old) {
                        throw new RuleViolation('自提门店不存在。');
                    }
                    $this->query('UPDATE pickup_locations SET code=?,name=?,address=?,enabled=? WHERE id=?',
                        [$code, $name, $address, (int) $enabled, $location]);
                }
                $this->audit($owner, 'pickup.location_saved', $location, ['shop_id' => $shop['id'], 'enabled' => $enabled]);
                return $location;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('本店门店编号已使用，或仓库已绑定自提门店。');
            }
            throw $error;
        }
    }

    public function merchantPickupLocations(int $owner): array
    {
        $actor = $this->shopActor($owner, 'owner');
        return $this->rows('SELECT l.*,w.name AS warehouse_name FROM pickup_locations l JOIN warehouses w ON w.id=l.warehouse_id
            WHERE l.shop_id=? ORDER BY l.id', [$actor['shop_id']]);
    }

    public function checkoutPickupLocations(int $buyer): array
    {
        $this->actor($buyer, 'buyer');
        return $this->rows("SELECT DISTINCT l.id,l.shop_id,l.name,l.address,s.name AS shop_name
            FROM cart_lines c JOIN products p ON p.id=c.product_id JOIN shops s ON s.id=p.shop_id
            JOIN pickup_locations l ON l.shop_id=s.id AND l.enabled=1
            WHERE c.buyer_id=? AND c.selected=1 AND s.status='active' ORDER BY l.shop_id,l.id", [$buyer]);
    }

    private function pickupSelection(int $shop, int $location, bool $lock): array
    {
        $selected = $this->one('SELECT id,warehouse_id,name,address FROM pickup_locations
            WHERE id=? AND shop_id=? AND enabled=1' . ($lock ? ' FOR SHARE' : ''), [$location, $shop]);
        if (!$selected) {
            throw new RuleViolation('请选择该店可用的自提门店。');
        }
        return $selected;
    }

    public function merchantPickupQueue(int $merchant, ?int $order = null): array
    {
        $actor = $this->shopActor($merchant, 'pickup');
        $scope = ['m.shop_id=?', "m.status IN ('ready','fulfilled')"];
        $params = [$actor['shop_id']];
        if ($actor['position'] === 'pickup') {
            $scope[] = 'p.pickup_location_id=?';
            $params[] = $actor['pickup_location_id'];
        }
        if ($order !== null) {
            $scope[] = 'm.id=?';
            $params[] = $order;
        }
        return $this->rows("SELECT m.id,m.status,p.pickup_location_id,p.location_name,p.location_address,p.prepared_at,p.used_at
            FROM order_pickups p JOIN merchant_orders m ON m.id=p.merchant_order_id WHERE "
            . implode(' AND ', $scope) . " ORDER BY (m.status='ready') DESC,m.id DESC LIMIT 50", $params);
    }

    private function lockPickupForMerchant(int $merchant, int $order, int $location): array
    {
        $actor = $this->shopActor($merchant, 'pickup');
        if ($actor['position'] === 'pickup' && (int) $actor['pickup_location_id'] !== $location) {
            throw new RuleViolation('当前岗位只能核验所属门店的自提单。');
        }
        $this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
        $record = $this->one('SELECT m.id,m.shop_id,m.total,m.status,p.prepared_at,p.code_digest,p.used_at,
            p.code_expires_at<=UTC_TIMESTAMP() AS expired
            FROM merchant_orders m JOIN order_pickups p ON p.merchant_order_id=m.id
            WHERE m.id=? AND m.shop_id=? AND p.pickup_location_id=? FOR UPDATE',
            [$order, $actor['shop_id'], $location]);
        if (!$record) {
            throw new RuleViolation('该门店无此自提单。');
        }
        return $record;
    }

    public function preparePickup(int $merchant, int $order, int $location): void
    {
        $this->transaction(function () use ($merchant, $order, $location): void {
            $record = $this->lockPickupForMerchant($merchant, $order, $location);
            if ($record['status'] !== 'ready') {
                throw new RuleViolation('订单尚未付款或已完成。');
            }
            if (!$record['prepared_at']) {
                $this->query('UPDATE order_pickups SET prepared_at=UTC_TIMESTAMP(),prepared_by=? WHERE merchant_order_id=?',
                    [$merchant, $order]);
                $this->audit($merchant, 'pickup.prepared', $order, ['location_id' => $location]);
            }
        });
    }

    public function issuePickupCode(int $buyer, int $order): string
    {
        $this->actor($buyer, 'buyer');
        return $this->transaction(function () use ($buyer, $order): string {
            $record = $this->one("SELECT p.prepared_at,p.pickup_location_id FROM order_pickups p
                JOIN merchant_orders m ON m.id=p.merchant_order_id JOIN orders o ON o.id=m.order_id
                WHERE m.id=? AND o.buyer_id=? AND m.status='ready' AND p.used_at IS NULL FOR UPDATE", [$order, $buyer]);
            if (!$record || !$record['prepared_at']) {
                throw new RuleViolation('该自提单不可生成提货码。');
            }
            $code = strtoupper(bin2hex(random_bytes(8)));
            $this->query('UPDATE order_pickups SET code_digest=?,code_expires_at=UTC_TIMESTAMP()+INTERVAL 48 HOUR
                WHERE merchant_order_id=?', [hash('sha256', $code), $order]);
            $this->audit($buyer, 'pickup.code_issued', $order, ['location_id' => $record['pickup_location_id']]);
            return $code;
        });
    }

    public function redeemPickup(int $merchant, int $order, int $location, string $submitted): bool
    {
        $code = strtoupper(str_replace([' ', '-'], '', trim($submitted)));
        if (!preg_match('/^[A-F0-9]{16}$/D', $code)) {
            throw new RuleViolation('请输入 16 位有效提货码。');
        }
        return $this->transaction(function () use ($merchant, $order, $location, $code): bool {
            $record = $this->lockPickupForMerchant($merchant, $order, $location);
            if (!$record['code_digest'] || !hash_equals($record['code_digest'], hash('sha256', $code))) {
                throw new RuleViolation('提货码无效，可请买家重新生成。');
            }
            if ($record['used_at']) {
                return false;
            }
            if ($record['status'] !== 'ready' || !$record['prepared_at'] || $record['expired']) {
                throw new RuleViolation('提货码已过期或订单当前不可核销。');
            }
            $this->query('UPDATE order_pickups SET used_at=UTC_TIMESTAMP(),used_by=? WHERE merchant_order_id=?',
                [$merchant, $order]);
            $this->completeMerchantOrder($record, $merchant);
            $this->audit($merchant, 'pickup.redeemed', $order, ['location_id' => $location]);
            return true;
        });
    }
}
