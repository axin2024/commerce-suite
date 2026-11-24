<?php

declare(strict_types=1);

trait ChannelReturnOperations
{
    public function requestChannelReturn(string $token, array $request): array
    {
        $orderReference = self::channelOrderReference($request['reference'] ?? null);
        $returnReference = self::channelReturnReference($request['return_reference'] ?? null);
        $sku = $request['sku'] ?? null;
        $quantity = $request['quantity'] ?? null;
        $reason = is_string($request['reason'] ?? null) ? trim($request['reason']) : '';
        if (count($request) !== 5 || !is_string($sku) || !preg_match('/^[a-z0-9_-]{1,64}$/iD', $sku)
            || !is_int($quantity) || $quantity < 1 || $quantity > 99 || $reason === '' || strlen($reason) > 250
            || !preg_match('//u', $reason) || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new ChannelOrderError(422, 'invalid_return');
        }
        $sku = strtoupper($sku);

        return $this->transaction(function () use ($token, $orderReference, $returnReference, $sku, $quantity, $reason): array {
            $channel = $this->channelOrderCredential($token, lock: true);
            $existing = $this->channelReturnRow($channel, $returnReference);
            if ($existing) {
                if ($existing['order_reference'] !== $orderReference || strtoupper($existing['sku']) !== $sku
                    || (int) $existing['quantity'] !== $quantity || $existing['reason'] !== $reason) {
                    throw new ChannelOrderError(409, 'return_reference_conflict');
                }
                return [false, self::channelReturnState($existing)];
            }
            $order = $this->requiredChannelOrder($channel, $orderReference);
            if ($order['fulfillment_status'] !== 'fulfilled') {
                throw new ChannelOrderError(409, 'order_not_fulfilled');
            }
            $item = $this->one('SELECT id FROM order_items WHERE merchant_order_id=? AND sku=?',
                [$order['merchant_order_id'], $sku]);
            if (!$item) {
                throw new ChannelOrderError(404, 'order_sku_not_found');
            }
            try {
                $refund = $this->requestReturnInTransaction((int) $channel['buyer_id'], (int) $item['id'], $quantity, $reason,
                    bin2hex(random_bytes(16)));
            } catch (RuleViolation) {
                throw new ChannelOrderError(409, 'return_unavailable');
            }
            $this->query('INSERT INTO channel_returns (channel_id,reference,refund_id) VALUES (?,?,?)',
                [$channel['id'], $returnReference, $refund]);
            return [true, self::channelReturnState($this->requiredChannelReturn($channel, $orderReference, $returnReference))];
        });
    }

    public function getChannelReturn(string $token, mixed $reference, mixed $returnReference): array
    {
        $orderReference = self::channelOrderReference($reference);
        $returnReference = self::channelReturnReference($returnReference);
        $channel = $this->channelOrderCredential($token);
        return self::channelReturnState($this->requiredChannelReturn($channel, $orderReference, $returnReference));
    }

    public function dispatchChannelReturn(string $token, array $request): array
    {
        $orderReference = self::channelOrderReference($request['reference'] ?? null);
        $returnReference = self::channelReturnReference($request['return_reference'] ?? null);
        $carrier = is_string($request['carrier'] ?? null) ? strtolower(trim($request['carrier'])) : '';
        $tracking = is_string($request['tracking'] ?? null) ? trim($request['tracking']) : '';
        if (count($request) !== 4 || !self::validTracking($carrier, $tracking)) {
            throw new ChannelOrderError(422, 'invalid_return_tracking');
        }
        return $this->transaction(function () use ($token, $orderReference, $returnReference, $carrier, $tracking): array {
            $channel = $this->channelOrderCredential($token, lock: true);
            $refund = $this->requiredChannelReturn($channel, $orderReference, $returnReference);
            try {
                $this->dispatchReturnInTransaction((int) $channel['buyer_id'], (int) $refund['id'], $carrier, $tracking);
            } catch (RuleViolation) {
                throw new ChannelOrderError(409, 'return_not_dispatchable');
            }
            return self::channelReturnState($this->requiredChannelReturn($channel, $orderReference, $returnReference));
        });
    }

    private function channelReturnRow(array $channel, string $reference): ?array
    {
        return $this->one('SELECT r.id,cr.reference AS return_reference,co.reference AS order_reference,i.sku,r.status,r.quantity,
            r.reason,r.return_address,r.review_note,r.carrier,r.tracking,r.accepted_quantity,r.restocked_quantity,
            r.inspection_note,r.amount,r.refund_reference,UNIX_TIMESTAMP(r.confirmed_at) AS confirmed_at
            FROM channel_returns cr JOIN refunds r ON r.id=cr.refund_id JOIN order_items i ON i.id=r.item_id
            JOIN merchant_orders m ON m.id=i.merchant_order_id
            JOIN channel_orders co ON co.order_id=m.order_id AND co.channel_id=cr.channel_id
            WHERE cr.channel_id=? AND cr.reference=? AND m.shop_id=? AND r.buyer_id=?',
            [$channel['id'], $reference, $channel['shop_id'], $channel['buyer_id']]);
    }

    private function requiredChannelReturn(array $channel, string $orderReference, string $returnReference): array
    {
        $refund = $this->channelReturnRow($channel, $returnReference);
        if (!$refund || $refund['order_reference'] !== $orderReference) {
            throw new ChannelOrderError(404, 'return_not_found');
        }
        return $refund;
    }

    private static function channelReturnReference(mixed $reference): string
    {
        if (!is_string($reference) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $reference)) {
            throw new ChannelOrderError(422, 'invalid_return_reference');
        }
        return $reference;
    }

    private static function channelReturnState(array $refund): array
    {
        $inspected = $refund['accepted_quantity'] !== null;
        return [
            'reference' => $refund['order_reference'],
            'return_reference' => $refund['return_reference'],
            'return_id' => (int) $refund['id'],
            'sku' => $refund['sku'],
            'status' => $refund['status'],
            'quantity' => (int) $refund['quantity'],
            'return_address' => $refund['return_address'],
            'review_note' => $refund['review_note'],
            'carrier' => $refund['carrier'],
            'tracking' => $refund['tracking'],
            'accepted_quantity' => $inspected ? (int) $refund['accepted_quantity'] : null,
            'restocked_quantity' => $inspected ? (int) $refund['restocked_quantity'] : null,
            'inspection_note' => $refund['inspection_note'],
            'currency' => 'CNY',
            'refund_amount_minor' => $inspected ? (int) $refund['amount'] : null,
            'refund_reference' => $refund['refund_reference'],
            'confirmed_at' => $refund['confirmed_at'] === null ? null : (int) $refund['confirmed_at'],
        ];
    }
}
