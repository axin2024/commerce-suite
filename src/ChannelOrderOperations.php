<?php

declare(strict_types=1);

final class ChannelOrderError extends RuntimeException
{
    public function __construct(public readonly int $status, string $error)
    {
        parent::__construct($error);
    }
}

trait ChannelOrderOperations
{
    public function channelOrderAccess(int $merchant, int $channelId): array
    {
        $channel = $this->catalogChannelForOwner($merchant, $channelId);
        $access = $this->one('SELECT revision,token_sha256 IS NOT NULL AS enabled FROM channel_order_access WHERE channel_id=?',
            [$channel['id']]);
        return ['revision' => (int) ($access['revision'] ?? 0), 'enabled' => (bool) ($access['enabled'] ?? false)];
    }

    public function changeChannelOrderAccess(int $merchant, int $channelId, int $revision, bool $disable): ?string
    {
        return $this->transaction(function () use ($merchant, $channelId, $revision, $disable): ?string {
            $channel = $this->catalogChannelForOwner($merchant, $channelId, lock: true);
            $access = $this->one('SELECT buyer_id,revision,token_sha256 FROM channel_order_access WHERE channel_id=? FOR UPDATE',
                [$channelId]);
            if ($channel['revoked_at'] !== null || (int) ($access['revision'] ?? 0) !== $revision
                || ($disable && ($access['token_sha256'] ?? null) === null)) {
                throw new RuleViolation('渠道订单权限已变更，请刷新后再操作。');
            }
            $token = $disable ? null : 'co1_' . bin2hex(random_bytes(32));
            $digest = $token === null ? null : hash('sha256', $token);
            if ($access) {
                $this->query('UPDATE channel_order_access SET token_sha256=?,revision=revision+1 WHERE channel_id=?',
                    [$digest, $channelId]);
            } else {
                // An internal order principal keeps the existing order ownership and FK rules.
                $buyer = $this->insert("INSERT INTO users (email,password_hash,role) VALUES (?,?,'buyer')",
                    ['channel-' . bin2hex(random_bytes(16)) . '@orders.invalid', '!']);
                $this->query('INSERT INTO channel_order_access (channel_id,buyer_id,token_sha256) VALUES (?,?,?)',
                    [$channelId, $buyer, $digest]);
            }
            $this->audit($merchant, 'channel_order.access_changed', $channelId,
                ['enabled' => !$disable, 'revision' => $revision + 1]);
            return $token;
        });
    }

    public function quoteChannelOrder(string $token, array $request): array
    {
        [$items, $destination] = self::channelOrderInput($request);
        return $this->transaction(function () use ($token, $items, $destination): array {
            $channel = $this->channelOrderCredential($token, lock: true);
            $priced = $this->priceChannelOrder($channel, $items, $destination);
            $lines = [];
            foreach ($priced['items'] as $item) {
                $lines[] = ['sku' => $item['sku'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price']];
            }
            return ['currency' => 'CNY', 'items' => $lines, 'subtotal_minor' => $priced['subtotal'],
                'shipping_minor' => $priced['shipping'], 'total_minor' => $priced['total'],
                'quote_version' => self::channelOrderDigest($priced)];
        });
    }

    public function placeChannelOrder(string $token, array $request): array
    {
        [$items, $destination] = self::channelOrderInput($request, placing: true);
        $reference = self::channelOrderReference($request['reference'] ?? null);
        $version = $request['quote_version'] ?? null;
        if (!is_string($version) || !preg_match('/^[a-f0-9]{64}$/D', $version)) {
            throw new ChannelOrderError(422, 'invalid_quote_version');
        }
        $digest = self::channelOrderDigest([$items, $destination, $version]);
        return $this->transaction(function () use ($token, $items, $destination, $reference, $version, $digest): array {
            $channel = $this->channelOrderCredential($token, lock: true);
            $existing = $this->channelOrderRow($channel, $reference, lock: true);
            if ($existing) {
                if (!hash_equals($existing['request_sha256'], $digest)) {
                    throw new ChannelOrderError(409, 'reference_conflict');
                }
                return [false, self::channelOrderState($existing)];
            }
            if ($this->paymentChannels->checkoutProvider === null) {
                throw new ChannelOrderError(503, 'checkout_unavailable');
            }
            $priced = $this->priceChannelOrder($channel, $items, $destination);
            if (!hash_equals(self::channelOrderDigest($priced), $version)) {
                throw new ChannelOrderError(409, 'quote_changed');
            }
            $order = $this->storeOrder((int) $channel['buyer_id'], $priced);
            $this->query('INSERT INTO channel_orders (channel_id,reference,request_sha256,order_id) VALUES (?,?,?,?)',
                [$channel['id'], $reference, $digest, $order]);
            $this->audit(null, 'channel_order.placed', $order, ['channel_id' => (int) $channel['id'], 'total' => $priced['total']]);
            return [true, self::channelOrderState($this->channelOrderRow($channel, $reference, lock: true))];
        });
    }

    public function getChannelOrder(string $token, mixed $reference): array
    {
        $reference = self::channelOrderReference($reference);
        $channel = $this->channelOrderCredential($token);
        return self::channelOrderState($this->requiredChannelOrder($channel, $reference));
    }

    public function channelShipmentPage(string $token, mixed $reference, ?int $shipment, int $after): array
    {
        $reference = self::channelOrderReference($reference);
        $channel = $this->channelOrderCredential($token);
        $order = $this->requiredChannelOrder($channel, $reference);
        $merchantOrder = (int) $order['merchant_order_id'];
        if ($shipment === null) {
            $rows = $this->shipments($merchantOrder, $after);
            return ['reference' => $reference]
                + self::channelShipmentResult($rows, 'shipments', self::channelShipmentState(...));
        }

        $parcel = $this->one('SELECT carrier,tracking FROM shipments WHERE id=? AND merchant_order_id=?', [$shipment, $merchantOrder]);
        if (!$parcel) {
            throw new ChannelOrderError(404, 'shipment_not_found');
        }
        $events = $this->rows('SELECT id,event_type,UNIX_TIMESTAMP(COALESCE(occurred_at,received_at)) AS occurred_at,
            UNIX_TIMESTAMP(received_at) AS received_at FROM shipment_delivery_events
            WHERE shipment_id=? AND id>? ORDER BY id LIMIT 51', [$shipment, $after]);
        return ['reference' => $reference, 'shipment_id' => $shipment, 'carrier' => $parcel['carrier'], 'tracking' => $parcel['tracking']]
            + self::channelShipmentResult($events, 'events', static fn(array $event): array => [
                'event_id' => (int) $event['id'], 'status' => $event['event_type'],
                'occurred_at' => (int) $event['occurred_at'], 'received_at' => (int) $event['received_at'],
            ]);
    }

    private static function channelShipmentResult(array $rows, string $field, Closure $format): array
    {
        $hasMore = count($rows) > 50;
        if ($hasMore) {
            array_pop($rows);
        }
        return [$field => array_map($format, $rows), 'next_after' => $hasMore ? (int) end($rows)['id'] : null];
    }

    private static function channelShipmentState(array $parcel): array
    {
        return ['shipment_id' => (int) $parcel['id'], 'carrier' => $parcel['carrier'], 'tracking' => $parcel['tracking'],
            'units' => (int) $parcel['units'],
            'status' => $parcel['delivered_at'] ? 'delivered' : ($parcel['event_type'] ?? 'dispatched'),
            'dispatched_at' => strtotime($parcel['dispatched_at'] . ' UTC'),
            'delivered_at' => $parcel['delivered_at'] === null ? null : strtotime($parcel['delivered_at'] . ' UTC'),
            'latest_event_at' => $parcel['occurred_at'] === null ? null : strtotime($parcel['occurred_at'] . ' UTC')];
    }

    public function checkoutChannelOrder(string $token, mixed $reference): array
    {
        $reference = self::channelOrderReference($reference);
        $channel = $this->channelOrderCredential($token);
        $order = $this->requiredChannelOrder($channel, $reference);
        try {
            $url = $this->paymentCheckoutUrl((int) $channel['buyer_id'], (int) $order['order_id']);
        } catch (RuleViolation) {
            throw new ChannelOrderError(409, 'order_not_payable');
        }
        return ['reference' => $reference, 'checkout_url' => $url];
    }

    public function cancelChannelOrder(string $token, mixed $reference): array
    {
        $reference = self::channelOrderReference($reference);
        return $this->transaction(function () use ($token, $reference): array {
            $channel = $this->channelOrderCredential($token, lock: true);
            $order = $this->requiredChannelOrder($channel, $reference, lock: true);
            if ($order['order_status'] === 'paid') {
                throw new ChannelOrderError(409, 'order_already_paid');
            }
            if ($order['order_status'] === 'unpaid') {
                $this->closeUnpaidOrder((int) $order['order_id'], null, (bool) $order['expired']);
                $order = $this->requiredChannelOrder($channel, $reference, lock: true);
            }
            return self::channelOrderState($order);
        });
    }

    private function channelOrderCredential(string $token, bool $lock = false): array
    {
        if (!preg_match('/^co1_[a-f0-9]{64}$/D', $token)) {
            throw new ChannelOrderError(401, 'invalid_credential');
        }
        $channel = $this->one('SELECT c.id,c.shop_id,a.buyer_id FROM channel_order_access a
            JOIN catalog_channels c ON c.id=a.channel_id WHERE a.token_sha256=? AND c.revoked_at IS NULL'
            . ($lock ? ' FOR UPDATE' : ''), [hash('sha256', $token)]);
        if (!$channel) {
            throw new ChannelOrderError(401, 'invalid_credential');
        }
        return $channel;
    }

    private function priceChannelOrder(array $channel, array $items, string $destination): array
    {
        $shop = $this->one('SELECT status FROM shops WHERE id=? FOR UPDATE', [$channel['shop_id']]);
        if (($shop['status'] ?? null) !== 'active') {
            throw new ChannelOrderError(409, 'shop_unavailable');
        }
        $cart = [];
        foreach ($items as $sku => $quantity) {
            $product = $this->one("SELECT p.id FROM products p JOIN catalog_channel_entries e ON e.product_id=p.id
                JOIN product_releases r ON r.id=p.release_id AND r.product_id=p.id
                WHERE e.channel_id=? AND e.enabled=1 AND p.shop_id=? AND p.sku=? AND p.status='active'
                    AND r.delivery=1 AND r.state IN ('approved','legacy') FOR SHARE",
                [$channel['id'], $channel['shop_id'], (string) $sku]);
            if (!$product) {
                throw new ChannelOrderError(409, 'sku_unavailable');
            }
            $cart[(int) $product['id']] = $quantity;
        }
        try {
            return $this->priceCart(null, $cart, 'web', $destination, null, true, [], [], 0);
        } catch (RuleViolation) {
            throw new ChannelOrderError(409, 'order_unavailable');
        }
    }

    private function channelOrderRow(array $channel, string $reference, bool $lock): ?array
    {
        return $this->one('SELECT c.reference,c.order_id,c.request_sha256,o.subtotal,o.shipping,o.total,o.status AS order_status,
            m.id AS merchant_order_id,m.status AS fulfillment_status,UNIX_TIMESTAMP(o.expires_at) AS expires_at,
            o.expires_at<=UTC_TIMESTAMP() AS expired
            FROM channel_orders c JOIN orders o ON o.id=c.order_id AND o.buyer_id=?
            JOIN merchant_orders m ON m.order_id=o.id AND m.shop_id=?
            WHERE c.channel_id=? AND c.reference=?' . ($lock ? ' FOR UPDATE OF o' : ''),
            [$channel['buyer_id'], $channel['shop_id'], $channel['id'], $reference]);
    }

    private function requiredChannelOrder(array $channel, string $reference, bool $lock = false): array
    {
        return $this->channelOrderRow($channel, $reference, $lock)
            ?? throw new ChannelOrderError(404, 'order_not_found');
    }

    private static function channelOrderState(array $order): array
    {
        return ['reference' => $order['reference'], 'order_id' => (int) $order['order_id'],
            'merchant_order_id' => (int) $order['merchant_order_id'], 'status' => $order['order_status'],
            'fulfillment_status' => $order['fulfillment_status'], 'currency' => 'CNY',
            'subtotal_minor' => (int) $order['subtotal'], 'shipping_minor' => (int) $order['shipping'],
            'total_minor' => (int) $order['total'], 'expires_at' => (int) $order['expires_at']];
    }

    private static function channelOrderInput(array $request, bool $placing = false): array
    {
        $allowed = $placing ? ['items', 'destination', 'reference', 'quote_version'] : ['items', 'destination'];
        $lines = $request['items'] ?? null;
        $destination = $request['destination'] ?? null;
        if (array_diff(array_keys($request), $allowed) || !is_array($lines) || !array_is_list($lines)
            || !$lines || count($lines) > 30 || !is_string($destination) || !preg_match('//u', $destination)
            || preg_match('/[\x00-\x1f\x7f]/', $destination) || trim($destination) === '' || strlen($destination) > 250) {
            throw new ChannelOrderError(422, 'invalid_order');
        }
        $items = [];
        foreach ($lines as $line) {
            $sku = is_array($line) ? ($line['sku'] ?? null) : null;
            $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
            if (!is_string($sku) || !preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku) || !is_int($quantity)
                || $quantity < 1 || $quantity > 99 || count($line) !== 2) {
                throw new ChannelOrderError(422, 'invalid_items');
            }
            $sku = strtoupper($sku);
            if (isset($items[$sku])) {
                throw new ChannelOrderError(422, 'duplicate_sku');
            }
            $items[$sku] = $quantity;
        }
        ksort($items, SORT_STRING);
        return [$items, trim($destination)];
    }

    private static function channelOrderReference(mixed $reference): string
    {
        if (!is_string($reference) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $reference)) {
            throw new ChannelOrderError(422, 'invalid_reference');
        }
        return $reference;
    }

    private static function channelOrderDigest(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
