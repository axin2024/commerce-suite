<?php

declare(strict_types=1);

trait ShippingOperations
{
    public function dispatchShipment(
        int $merchant, int $orderId, int $warehouse, string $carrier, string $tracking, array $quantities, string $key,
    ): int {
        $carrier = strtolower(trim($carrier));
        $tracking = trim($tracking);
        if (!self::validTracking($carrier, $tracking) || !preg_match('/^[a-f0-9]{32}$/D', $key)
            || !$quantities || count($quantities) > 30) {
            throw new RuleViolation('请填写承运商、运单和有效的包裹明细。');
        }
        foreach ($quantities as $allocation => $count) {
            if (!is_int($allocation) || $allocation < 1 || !is_int($count) || $count < 1 || $count > 99) {
                throw new RuleViolation('包裹明细数量须为 1–99。');
            }
        }
        ksort($quantities, SORT_NUMERIC);
        $digest = hash('sha256', json_encode([$warehouse, $carrier, $tracking, $quantities], JSON_THROW_ON_ERROR));
        try {
            return $this->transaction(function () use ($merchant, $orderId, $warehouse, $carrier, $tracking, $quantities, $key, $digest): int {
                $actor = $this->shopActor($merchant, 'fulfillment');
                $order = $this->lockDeliveryOrder($orderId, (int) $actor['shop_id']);
                $existing = $this->one('SELECT id,request_hash FROM shipments WHERE merchant_order_id=? AND request_key=?',
                    [$orderId, $key]);
                if ($existing) {
                    if (!hash_equals($existing['request_hash'], $digest)) {
                        throw new RuleViolation('包裹提交编号已用于其他内容。');
                    }
                    return (int) $existing['id'];
                }
                if (!in_array($order['status'], ['ready', 'partial'], true)) {
                    throw new RuleViolation('当前订单无待发件。');
                }
                $remaining = array_column($this->unshippedLines($orderId, $warehouse), 'remaining', 'id');
                foreach ($quantities as $allocation => $count) {
                    if ($count > (int) ($remaining[$allocation] ?? 0)) {
                        throw new RuleViolation('发货数量超过本仓该订单的待发数量。');
                    }
                }
                $source = $this->carrierAdapters->supports($carrier) ? 'carrier' : null;
                $id = $this->insert('INSERT INTO shipments
                    (merchant_order_id,warehouse_id,request_key,request_hash,carrier,tracking,dispatched_by,tracking_source)
                    VALUES (?,?,?,?,?,?,?,?)', [$orderId, $warehouse, $key, $digest, $carrier, $tracking, $merchant, $source]);
                foreach ($quantities as $allocation => $count) {
                    $this->query('INSERT INTO shipment_items (shipment_id,allocation_id,quantity) VALUES (?,?,?)',
                        [$id, $allocation, $count]);
                }
                $status = $this->unshippedLines($orderId) || $this->hasPendingPreorders($orderId) ? 'partial' : 'shipped';
                $this->query('UPDATE merchant_orders SET status=? WHERE id=?', [$status, $orderId]);
                $this->audit($merchant, 'shipping.dispatched', $id, ['merchant_order_id' => $orderId, 'warehouse_id' => $warehouse]);
                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('该承运商的运单号已使用。');
            }
            throw $error;
        }
    }

    public function unshippedLines(int $order, ?int $warehouse = null): array
    {
        $filter = $warehouse === null ? '' : ' AND a.warehouse_id=?';
        $args = $warehouse === null ? [$order] : [$order, $warehouse];
        return $this->rows("SELECT a.id,a.warehouse_id,w.name AS warehouse_name,i.sku,i.name,
            a.quantity-COALESCE(SUM(si.quantity),0) AS remaining
            FROM order_inventory_allocations a JOIN order_items i ON i.id=a.order_item_id
            JOIN warehouses w ON w.id=a.warehouse_id LEFT JOIN shipment_items si ON si.allocation_id=a.id
            WHERE i.merchant_order_id=? AND a.state='consumed'" . $filter
            . ' GROUP BY a.id,w.name,i.sku,i.name HAVING remaining>0 ORDER BY a.warehouse_id,a.id', $args);
    }

    public function shipments(int $order, ?int $after = null): array
    {
        $sql = 'SELECT s.id,s.carrier,s.tracking,s.dispatched_at,s.delivered_at,w.name AS warehouse_name,
            (SELECT SUM(si.quantity) FROM shipment_items si WHERE si.shipment_id=s.id) AS units,
            latest.event_type,latest.occurred_at
            FROM shipments s JOIN warehouses w ON w.id=s.warehouse_id
            LEFT JOIN LATERAL (SELECT event_type,occurred_at FROM shipment_delivery_events
                WHERE shipment_id=s.id AND occurred_at IS NOT NULL ORDER BY occurred_at DESC,id DESC LIMIT 1) latest ON TRUE
            WHERE s.merchant_order_id=?';
        return $after === null ? $this->rows($sql . ' ORDER BY s.id', [$order])
            : $this->rows($sql . ' AND s.id>? ORDER BY s.id LIMIT 51', [$order, $after]);
    }

    public static function validTracking(string $carrier, string $tracking): bool
    {
        return preg_match('/^[a-z0-9_-]{1,32}$/D', $carrier) === 1
            && preg_match('/^[a-z0-9_-]{1,80}$/iD', $tracking) === 1;
    }

    public static function validDeliveryEvent(string $carrier, string $tracking, string $eventReference): bool
    {
        return self::validTracking($carrier, $tracking) && preg_match('/^[a-z0-9_-]{1,80}$/iD', $eventReference) === 1;
    }

    public function recordDelivery(string $carrier, string $tracking, string $eventReference): array
    {
        if (!demoEnabled() || !self::validDeliveryEvent($carrier, $tracking, $eventReference)) {
            throw new RuleViolation('签收事件无效或沙箱未启用。');
        }
        return $this->storeParcelEvent('sandbox', $carrier, $tracking, $eventReference, 'delivered', null, null);
    }

    public function receiveCarrierEvent(string $carrier, string $body, array $headers): array
    {
        $event = $this->carrierAdapters->verifiedEvent($carrier, $body, $headers);
        if ($event === null) {
            return ['status' => 'ignored'];
        }
        $type = $event['type'] ?? null;
        $tracking = $event['tracking'] ?? null;
        $reference = $event['reference'] ?? null;
        $epoch = $event['occurred_at'] ?? null;
        if (count($event) !== 4 || !in_array($type, ['in_transit', 'out_for_delivery', 'exception', 'delivered'], true)
            || !is_string($tracking) || !is_string($reference) || !self::validDeliveryEvent($carrier, $tracking, $reference)
            || !is_int($epoch) || $epoch < 0 || $epoch > time() + 300) {
            throw new UnexpectedValueException('Invalid carrier event fields.');
        }
        $digest = hash('sha256', json_encode([$type, $tracking, $epoch], JSON_THROW_ON_ERROR));
        return $this->storeParcelEvent('carrier', $carrier, $tracking, $reference, $type, gmdate('Y-m-d H:i:s', $epoch), $digest);
    }

    private function storeParcelEvent(
        string $source, string $carrier, string $tracking, string $eventReference, string $type,
        ?string $occurredAt, ?string $digest,
    ): array {
        return $this->transaction(function () use ($source, $carrier, $tracking, $eventReference, $type, $occurredAt, $digest): array {
            $identity = $this->one('SELECT s.id,s.merchant_order_id,m.shop_id FROM shipments s
                JOIN merchant_orders m ON m.id=s.merchant_order_id WHERE s.carrier=? AND s.tracking=?', [$carrier, $tracking]);
            if (!$identity) {
                throw new RuleViolation('未找到对应的运单。');
            }
            $orderId = (int) $identity['merchant_order_id'];
            $order = $this->lockDeliveryOrder($orderId, (int) $identity['shop_id']);
            $shipment = $this->one('SELECT delivered_at,tracking_source FROM shipments WHERE id=? FOR UPDATE', [$identity['id']]);
            $bound = $shipment['tracking_source'];
            if (($bound !== null && $bound !== $source)
                || ($bound === null && $source === 'sandbox' && $this->carrierAdapters->supports($carrier))) {
                throw new RuleViolation('运单已绑定其他回执来源。');
            }
            $this->query('INSERT INTO shipment_delivery_events
                (source,carrier,external_reference,shipment_id,event_type,occurred_at,payload_sha256) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE id=id', [$source, $carrier, $eventReference, $identity['id'], $type, $occurredAt, $digest]);
            $event = $this->one('SELECT id,shipment_id,payload_sha256 FROM shipment_delivery_events
                WHERE source=? AND carrier=? AND external_reference=? FOR UPDATE', [$source, $carrier, $eventReference]);
            if ((int) $event['shipment_id'] !== (int) $identity['id'] || $event['payload_sha256'] !== $digest) {
                throw new RuleViolation('回执事件号已用于其他运单或内容。');
            }
            if ($bound === null) {
                $this->query('UPDATE shipments SET tracking_source=? WHERE id=?', [$source, $identity['id']]);
            }
            if ($type === 'delivered' && !$shipment['delivered_at']) {
                $this->query('UPDATE shipments SET delivered_at=UTC_TIMESTAMP() WHERE id=?', [$identity['id']]);
                $this->audit(null, 'shipping.delivered', (int) $identity['id'], ['event_id' => (int) $event['id']]);
                if ($order['status'] === 'shipped' && !$this->one(
                    'SELECT id FROM shipments WHERE merchant_order_id=? AND delivered_at IS NULL LIMIT 1 FOR SHARE', [$orderId],
                )) {
                    $this->completeMerchantOrder($order, null);
                }
            }
            return ['event_id' => (int) $event['id'], 'shipment_id' => (int) $identity['id'], 'status' => $type];
        });
    }

    private function lockDeliveryOrder(int $order, int $shop): array
    {
        $this->one('SELECT id FROM shops WHERE id=? FOR UPDATE', [$shop]);
        $row = $this->one("SELECT m.* FROM merchant_orders m JOIN orders o ON o.id=m.order_id
            WHERE m.id=? AND m.shop_id=? AND o.channel='web' FOR UPDATE", [$order, $shop]);
        if (!$row) {
            throw new RuleViolation('配送订单不存在或无权操作。');
        }
        return $row;
    }
}
