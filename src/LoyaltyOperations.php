<?php

declare(strict_types=1);

trait LoyaltyOperations
{
    public const MAX_POINTS_PER_ORDER = 100_000_000;
    public const POINT_EVENTS = [
        'hold' => '下单预占', 'cancel' => '关单释放', 'spend' => '支付抵扣',
        'earn' => '支付获赠', 'restore' => '退款返还抵扣', 'reverse' => '退款扣回赠送',
    ];

    public function publishLoyalty(int $admin, int $earn, int $redeem, bool $returnSpent): int
    {
        $this->actor($admin, 'admin');
        if ($earn < 0 || $earn > 10 || $redeem < 0 || $redeem > 100) {
            throw new RuleViolation('每元赠送积分须为 0–10，抵扣比例须为 0–100%。');
        }
        return $this->transaction(function () use ($admin, $earn, $redeem, $returnSpent): int {
            $revision = $this->insert(
                'INSERT INTO loyalty_revisions (earn_per_yuan,redeem_percent,return_spent,published_by) VALUES (?,?,?,?)',
                [$earn, $redeem, (int) $returnSpent, $admin],
            );
            $this->audit($admin, 'loyalty.published', $revision, ['earn_per_yuan' => $earn, 'redeem_percent' => $redeem,
                'return_spent' => $returnSpent]);
            return $revision;
        });
    }

    public function loyaltyPolicy(bool $lock = false): ?array
    {
        return $this->one('SELECT id,earn_per_yuan,redeem_percent,return_spent FROM loyalty_revisions
            ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR SHARE' : ''));
    }

    public function loyaltyAccount(int $buyer): array
    {
        $this->actor($buyer, 'buyer');
        return $this->one('SELECT balance,held,GREATEST(balance-held,0) AS available FROM loyalty_accounts WHERE buyer_id=?', [$buyer])
            ?? ['balance' => 0, 'held' => 0, 'available' => 0];
    }

    public function loyaltyHistory(int $buyer): array
    {
        $this->actor($buyer, 'buyer');
        return $this->rows('SELECT e.* FROM loyalty_entries e JOIN orders o ON o.id=e.order_id
            WHERE o.buyer_id=? ORDER BY e.id DESC LIMIT 30', [$buyer]);
    }

    private function loyaltyQuote(int $buyer, int $points, array $weights, bool $lock): ?array
    {
        $policy = $this->loyaltyPolicy($lock);
        $cap = intdiv(array_sum($weights) * (int) ($policy['redeem_percent'] ?? 0), 100);
        if ($points < 0 || $points > self::MAX_POINTS_PER_ORDER || $points > $cap) {
            throw new RuleViolation('积分抵扣超过本单商品金额允许的上限。');
        }
        if ($points > 0 && $points > (int) $this->loyaltyAccount($buyer)['available']) {
            throw new RuleViolation('可用积分不足，请重新报价。');
        }
        if (!$policy || (!(int) $policy['earn_per_yuan'] && !$points)) {
            return null;
        }
        return ['revision_id' => (int) $policy['id'], 'spent' => $points, 'earned' => 0,
            'parts' => self::allocate($points, $weights), 'rate' => (int) $policy['earn_per_yuan']];
    }

    private function reserveOrderPoints(int $order, int $buyer, array $loyalty): void
    {
        $points = $loyalty['spent'];
        if (!$points && !$loyalty['earned']) {
            return;
        }
        $this->query('INSERT INTO loyalty_accounts (buyer_id) VALUES (?) ON DUPLICATE KEY UPDATE buyer_id=buyer_id', [$buyer]);
        if ($points > 0) {
            $hold = $this->query('UPDATE loyalty_accounts SET held=held+? WHERE buyer_id=? AND balance-held>=?',
                [$points, $buyer, $points]);
            if ($hold->rowCount() !== 1) {
                throw new RuleViolation('可用积分已变化，请重新报价。');
            }
            $this->appendPointsEvent($order, null, 'hold', 0, $points);
        }
    }

    private function settleOrderPoints(int $order, bool $paid): void
    {
        $snapshot = $this->one('SELECT buyer_id,points_spent,points_earned FROM orders WHERE id=?', [$order]);
        $buyer = (int) $snapshot['buyer_id'];
        $spent = (int) $snapshot['points_spent'];
        $earned = (int) $snapshot['points_earned'];
        if ($spent > 0) {
            $this->movePoints($buyer, $order, null, $paid ? 'spend' : 'cancel', $paid ? -$spent : 0, -$spent);
        }
        if ($paid && $earned > 0) {
            $this->movePoints($buyer, $order, null, 'earn', $earned, 0);
        }
    }

    private function settleRefundPoints(int $order, array $refund): void
    {
        $buyer = (int) $refund['buyer_id'];
        $id = (int) $refund['id'];
        if ($refund['points_restored'] > 0) {
            $this->movePoints($buyer, $order, $id, 'restore', (int) $refund['points_restored'], 0);
        }
        if ($refund['points_reversed'] > 0) {
            $this->movePoints($buyer, $order, $id, 'reverse', -(int) $refund['points_reversed'], 0);
        }
    }

    private function movePoints(int $buyer, int $order, ?int $refund, string $kind, int $balance, int $held): void
    {
        $updated = $this->query('UPDATE loyalty_accounts SET balance=balance+?,held=held+? WHERE buyer_id=?',
            [$balance, $held, $buyer]);
        if ($updated->rowCount() !== 1) {
            throw new LogicException('Missing loyalty account for order movement.');
        }
        $this->appendPointsEvent($order, $refund, $kind, $balance, $held);
    }

    private function appendPointsEvent(int $order, ?int $refund, string $kind, int $balance, int $held): void
    {
        $this->query('INSERT INTO loyalty_entries (event_key,order_id,refund_id,kind,balance_delta,held_delta)
            VALUES (?,?,?,?,?,?)', [$kind . '-' . ($refund ?? $order), $order, $refund, $kind, $balance, $held]);
    }
}
