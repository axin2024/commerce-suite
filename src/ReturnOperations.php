<?php

declare(strict_types=1);

trait ReturnOperations
{
    public const OPEN_RETURN_QUANTITY = "CASE
        WHEN r.status IN ('pending','return_authorized','return_in_transit') THEN r.quantity
        WHEN r.status='refund_pending' THEN r.accepted_quantity
        ELSE 0 END";

    public const FREIGHT_PAYERS = ['merchant' => '商家承担', 'platform' => '平台承担'];

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
                JOIN orders o ON o.id=m.order_id WHERE i.id=? AND o.buyer_id=? AND m.status='fulfilled' FOR UPDATE OF i",
                [$itemId, $buyer]);
            if (!$item) {
                throw new RuleViolation('订单明细不存在或尚未履约。');
            }
            $claims = $this->rows('SELECT ' . self::OPEN_RETURN_QUANTITY . ' AS units FROM refunds r
                WHERE r.item_id=? FOR UPDATE', [$itemId]);
            $remaining = (int) $item['quantity'] - (int) $item['refunded_quantity'] - array_sum(array_column($claims, 'units'));
            if ($quantity > $remaining) {
                throw new RuleViolation('当前可申请的数量不足。');
            }
            $id = $this->insert('INSERT INTO refunds (item_id,buyer_id,request_key,quantity,reason) VALUES (?,?,?,?,?)',
                [$itemId, $buyer, $key, $quantity, $reason]);
            $this->audit($buyer, 'refund.requested', $id, ['quantity' => $quantity]);
            return $id;
        });
    }

    public function reviewReturn(int $merchant, int $id, ?int $warehouse, string $address, string $note): void
    {
        $address = trim($address);
        $note = trim($note);
        if (strlen($address) > 250 || strlen($note) > 250 || ($warehouse === null && $note === '')) {
            throw new RuleViolation('请填写有效的退货地址或拒绝原因。');
        }
        $this->transaction(function () use ($merchant, $id, $warehouse, $address, $note): void {
            $shop = (int) $this->shopActor($merchant, 'support')['shop_id'];
            $refund = $this->lockReturn($id, $shop);
            if ($refund['status'] !== 'pending') {
                if ($refund['review_note'] === $note && ($warehouse !== null
                    ? (int) $refund['return_warehouse_id'] === $warehouse && ($address === '' || $refund['return_address'] === $address)
                    : $refund['status'] === 'rejected' && $refund['return_warehouse_id'] === null)) {
                    return;
                }
                throw new RuleViolation('售后申请已审核。');
            }
            if ($warehouse !== null) {
                $location = $this->one('SELECT address FROM warehouses WHERE id=? AND shop_id=?', [$warehouse, $shop]);
                if (!$location) {
                    throw new RuleViolation('请选择本店退货仓。');
                }
                $address = $address !== '' ? $address : trim((string) $location['address']);
                if ($address === '') {
                    throw new RuleViolation('请填写买家寄回时使用的地址。');
                }
            }
            $status = $warehouse === null ? 'rejected' : 'return_authorized';
            $this->query('UPDATE refunds SET status=?,return_warehouse_id=?,return_address=?,review_note=?,decided_by=?,
                decided_at=UTC_TIMESTAMP() WHERE id=?', [$status, $warehouse, $warehouse === null ? null : $address, $note, $merchant, $id]);
            $this->audit($merchant, 'refund.reviewed', $id, ['status' => $status, 'warehouse_id' => $warehouse, 'note' => $note]);
        });
    }

    public function dispatchReturn(int $buyer, int $id, string $carrier, string $tracking): void
    {
        $this->actor($buyer, 'buyer');
        $carrier = strtolower(trim($carrier));
        $tracking = trim($tracking);
        if (!self::validTracking($carrier, $tracking)) {
            throw new RuleViolation('请填写有效的承运商代码和运单号。');
        }
        try {
            $this->transaction(function () use ($buyer, $id, $carrier, $tracking): void {
                $refund = $this->one('SELECT status,carrier,tracking FROM refunds WHERE id=? AND buyer_id=? FOR UPDATE', [$id, $buyer]);
                if ($refund && $refund['carrier'] === $carrier && $refund['tracking'] === $tracking) {
                    return;
                }
                if (!$refund || $refund['status'] !== 'return_authorized') {
                    throw new RuleViolation('售后申请暂不可登记寄回。');
                }
                $this->query("UPDATE refunds SET status='return_in_transit',carrier=?,tracking=?,dispatched_at=UTC_TIMESTAMP() WHERE id=?",
                    [$carrier, $tracking, $id]);
                $this->audit($buyer, 'refund.dispatched', $id, ['carrier' => $carrier, 'tracking' => $tracking]);
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('该退货运单已登记。');
            }
            throw $error;
        }
    }

    public function decideReturnFreight(int $actor, int $id, int $amount, string $note): void
    {
        $role = $this->actor($actor, 'merchant', 'finance')['role'];
        $note = trim($note);
        if ($amount < 0 || $note === '' || strlen($note) > 250) {
            throw new RuleViolation('请填写有效的退运费金额和处理依据。');
        }
        $payer = $role === 'finance' ? 'platform' : 'merchant';
        $this->transaction(function () use ($actor, $role, $id, $amount, $note, $payer): void {
            if ($role === 'finance') {
                $scope = $this->one('SELECT m.shop_id FROM refunds r JOIN order_items i ON i.id=r.item_id
                    JOIN merchant_orders m ON m.id=i.merchant_order_id WHERE r.id=?', [$id]);
                if (!$scope) {
                    throw new RuleViolation('售后申请不存在。');
                }
                $shop = (int) $scope['shop_id'];
            } else {
                $shop = (int) $this->shopActor($actor, 'support')['shop_id'];
            }
            $refund = $this->lockReturn($id, $shop);
            if ($refund['freight_payer'] !== null && $refund['freight_payer'] !== $payer) {
                throw new RuleViolation('这笔运费已由另一承担方确认。');
            }
            if ((int) $refund['freight_amount'] === $amount && $refund['freight_note'] === $note
                && ($amount > 0 || (int) $refund['freight_decided_by'] === $actor)) {
                return;
            }
            if (!in_array($refund['status'], ['return_authorized', 'return_in_transit'], true)) {
                throw new RuleViolation('退货审核通过后、验收前可登记运费。');
            }
            if ($amount === 0 && $refund['freight_payer'] === null) {
                throw new RuleViolation('当前没有可撤回的运费决定。');
            }
            // Use a locking read after acquiring the shop lock, including decisions on other lines of the same suborder.
            $held = $this->rows("SELECT r.freight_amount FROM order_items i JOIN refunds r ON r.item_id=i.id
                WHERE i.merchant_order_id=? AND r.id<>? AND r.freight_amount>0
                AND r.status IN ('return_authorized','return_in_transit','refund_pending','refunded') FOR UPDATE OF r",
                [$refund['merchant_order_id'], $id]);
            if ($amount > (int) $refund['shipping'] - array_sum(array_column($held, 'freight_amount'))) {
                throw new RuleViolation('该子订单可退运费不足。');
            }
            $this->query('UPDATE refunds SET freight_amount=?,freight_payer=?,freight_note=?,freight_decided_by=?,
                freight_decided_at=UTC_TIMESTAMP() WHERE id=?', [$amount, $amount > 0 ? $payer : null, $note, $actor, $id]);
            $this->audit($actor, 'refund.freight_decided', $id, ['payer' => $payer, 'amount' => $amount, 'note' => $note]);
        });
    }

    public function inspectReturn(int $merchant, int $id, int $accepted, int $restocked, string $note): void
    {
        $note = trim($note);
        if ($accepted < 0 || $restocked < 0 || $restocked > $accepted || strlen($note) > 250) {
            throw new RuleViolation('请核对验收数量和回库数量。');
        }
        $this->transaction(function () use ($merchant, $id, $accepted, $restocked, $note): void {
            $shop = (int) $this->shopActor($merchant, 'fulfillment')['shop_id'];
            $refund = $this->lockReturn($id, $shop);
            if ($refund['status'] !== 'return_in_transit') {
                if ($refund['inspected_at'] && (int) $refund['accepted_quantity'] === $accepted
                    && (int) $refund['restocked_quantity'] === $restocked && $refund['inspection_note'] === $note) {
                    return;
                }
                throw new RuleViolation('当前售后不可验收。');
            }
            if ($accepted > (int) $refund['quantity'] || ($accepted !== (int) $refund['quantity'] && $note === '')) {
                throw new RuleViolation('少收、拒收或验收不符时请填写原因。');
            }
            $held = $this->rows("SELECT accepted_quantity FROM refunds WHERE item_id=? AND status='refund_pending' FOR UPDATE",
                [$refund['item_id']]);
            $before = (int) $refund['refunded_quantity'] + array_sum(array_column($held, 'accepted_quantity'));
            $after = $before + $accepted;
            $quantity = (int) $refund['item_quantity'];
            if ($after > $quantity) {
                throw new RuleViolation('退款数量超过购买数量。');
            }
            $net = $quantity * (int) $refund['unit_price'] - (int) $refund['discount'];
            $goods = intdiv($net * $after, $quantity) - intdiv($net * $before, $quantity);
            $freight = $accepted > 0 ? (int) $refund['freight_amount'] : 0;
            $amount = $goods + $freight;
            $fee = (int) $refund['item_commission'];
            $commission = intdiv($fee * $after, $quantity) - intdiv($fee * $before, $quantity);
            if ($restocked > 0) {
                $this->restockReturn((int) $refund['product_id'], (int) $refund['return_warehouse_id'], $restocked);
            }
            $status = $accepted > 0 ? 'refund_pending' : 'rejected';
            $token = $accepted > 0 ? bin2hex(random_bytes(16)) : null;
            $this->query('UPDATE refunds SET status=?,accepted_quantity=?,restocked_quantity=?,inspection_note=?,inspected_by=?,
                inspected_at=UTC_TIMESTAMP(),amount=?,commission=?,refund_token=? WHERE id=?',
                [$status, $accepted, $restocked, $note, $merchant, $amount, $commission, $token, $id]);
            $this->audit($merchant, 'refund.inspected', $id, ['accepted' => $accepted, 'restocked' => $restocked,
                'status' => $status, 'amount' => $amount, 'goods' => $goods, 'freight' => $freight, 'note' => $note]);
        });
    }

    public static function validReturnReceipt(string $token, string $reference, string $payment, string $currency, int $amount): bool
    {
        return self::validSandboxFields($token, $reference, $currency, max(1, $amount))
            && $amount >= 0 && preg_match('/^[a-z0-9_-]{1,80}$/iD', $payment) === 1;
    }

    public function recordReturnReceipt(string $token, string $reference, string $payment, string $currency, int $amount): array
    {
        if (!demoEnabled() || !self::validReturnReceipt($token, $reference, $payment, $currency, $amount)) {
            throw new RuleViolation('退款回执无效或沙箱未启用。');
        }
        try {
            return $this->transaction(function () use ($token, $reference, $payment, $currency, $amount): array {
                $identity = $this->one('SELECT r.id,m.shop_id,m.order_id,p.reference FROM refunds r JOIN order_items i ON i.id=r.item_id
                    JOIN merchant_orders m ON m.id=i.merchant_order_id JOIN payments p ON p.order_id=m.order_id
                    WHERE r.refund_token=?', [$token]);
                if (!$identity || $identity['reference'] !== $payment || $currency !== 'CNY') {
                    throw new RuleViolation('未找到匹配的原支付流水。');
                }
                $id = (int) $identity['id'];
                $order = (int) $identity['order_id'];
                $refund = $this->lockReturn($id, (int) $identity['shop_id'], $order);
                if ((int) $refund['amount'] !== $amount || ($refund['status'] !== 'refund_pending'
                    && ($refund['status'] !== 'refunded' || $refund['refund_reference'] !== $reference))) {
                    throw new RuleViolation('退款回执与申请金额或流水不符。');
                }
                if ($refund['status'] === 'refund_pending') {
                    $this->query("UPDATE refunds SET status='refunded',refund_reference=?,confirmed_at=UTC_TIMESTAMP() WHERE id=?",
                        [$reference, $id]);
                    $this->query('UPDATE order_items SET refunded_quantity=refunded_quantity+? WHERE id=?',
                        [$refund['accepted_quantity'], $refund['item_id']]);
                    $credit = $refund['freight_payer'] === 'platform' ? (int) $refund['freight_amount'] : 0;
                    $this->query("INSERT INTO ledger (shop_id,merchant_order_id,event_key,kind,gross,commission,platform_freight)
                        VALUES (?,?,?,'refund',?,?,?)", [
                        $identity['shop_id'], $refund['merchant_order_id'], 'refund-' . $id, -$amount, -(int) $refund['commission'],
                        $credit,
                    ]);
                    $this->audit(null, 'refund.confirmed', $id, ['reference' => $reference, 'amount' => $amount]);
                    $this->restoreRefundedCoupon($order, $id);
                }
                return ['refund_id' => $id, 'status' => 'refunded'];
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('该退款回执已用于其他申请。');
            }
            throw $error;
        }
    }

    private function lockReturn(int $id, int $shop, ?int $order = null): array
    {
        $this->one("SELECT id FROM shops WHERE id=? AND status IN ('active','suspended') FOR UPDATE", [$shop])
            ?? throw new RuleViolation('当前店铺不可处理售后。');
        if ($order !== null) {
            // Cross-shop refund confirmations for a single order must see each other's committed quantities.
            $this->one('SELECT id FROM orders WHERE id=? FOR UPDATE', [$order]);
        }
        // All quantity reservations and releases serialize on the order line.
        $item = $this->one('SELECT i.*,m.shipping FROM refunds r JOIN order_items i ON i.id=r.item_id
            JOIN merchant_orders m ON m.id=i.merchant_order_id WHERE r.id=? AND m.shop_id=? FOR UPDATE OF i', [$id, $shop]);
        if (!$item) {
            throw new RuleViolation('售后申请不存在。');
        }
        return $this->one('SELECT * FROM refunds WHERE id=? FOR UPDATE', [$id]) + [
            'item_quantity' => $item['quantity'], 'product_id' => $item['product_id'], 'refunded_quantity' => $item['refunded_quantity'],
            'merchant_order_id' => $item['merchant_order_id'], 'unit_price' => $item['unit_price'],
            'discount' => $item['discount'], 'item_commission' => $item['commission'], 'shipping' => $item['shipping'],
        ];
    }

    private function restockReturn(int $product, int $warehouse, int $units): void
    {
        $updated = $this->query('UPDATE products SET stock=stock+?,version=version+1 WHERE id=? AND stock<=?',
            [$units, $product, 1_000_000_000 - $units]);
        if ($updated->rowCount() !== 1) {
            throw new RuleViolation('回库后库存超出上限，请先核对可售库存。');
        }
        $this->query('INSERT INTO warehouse_stock (product_id,warehouse_id,stock) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE stock=stock+?', [$product, $warehouse, $units, $units]);
    }
}
