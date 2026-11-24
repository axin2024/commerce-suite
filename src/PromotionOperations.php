<?php

declare(strict_types=1);

trait PromotionOperations
{
    public function setMemberLevel(int $admin, string $email, string $level): void
    {
        $this->actor($admin, 'admin');
        if (!in_array($level, ['standard', 'plus'], true)) {
            throw new RuleViolation('会员等级无效。');
        }
        $this->transaction(function () use ($admin, $email, $level): void {
            $buyer = $this->one("SELECT id,member_level FROM users WHERE email=? AND role='buyer' FOR UPDATE",
                [strtolower(trim($email))]);
            if (!$buyer) {
                throw new RuleViolation('找不到对应的买家账号。');
            }
            if ($buyer['member_level'] !== $level) {
                $this->query('UPDATE users SET member_level=? WHERE id=?', [$level, $buyer['id']]);
                $this->audit($admin, 'member.level_changed', (int) $buyer['id'], ['level' => $level]);
            }
        });
    }

    public function publishPromotion(
        int $admin, string $code, string $name, int $threshold, int $discount,
        bool $requiresClaim, bool $stackMemberPrice, ?int $category = null, string $end = '',
    ): int {
        $this->actor($admin, 'admin');
        $code = trim($code);
        $name = trim($name);
        $end = trim($end);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,47}$/D', $code) || $name === '' || strlen($name) > 120
            || $threshold < 1 || $threshold > 300_000_000_000 || $discount < 1
            || $discount > 100_000_000 || $discount > $threshold) {
            throw new RuleViolation('请检查活动编号、名称、门槛和减免金额。');
        }
        $lastMinute = $end === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $end, new DateTimeZone('UTC'));
        if ($end !== '' && (!$lastMinute || $lastMinute->format('Y-m-d\TH:i') !== $end || $lastMinute->getTimestamp() <= time())) {
            throw new RuleViolation('结束时间须为未来的 UTC 日期和时间。');
        }
        $endsAt = $lastMinute?->format('Y-m-d H:i:s');
        return $this->transaction(function () use ($admin, $code, $name, $threshold, $discount,
            $requiresClaim, $stackMemberPrice, $category, $endsAt): int {
            if ($category !== null && !$this->one('SELECT id FROM product_categories WHERE id=?', [$category])) {
                throw new RuleViolation('活动分类不存在。');
            }
            $this->query('INSERT INTO promotions (code) VALUES (?) ON DUPLICATE KEY UPDATE code=code', [$code]);
            $promotion = $this->one('SELECT id FROM promotions WHERE code=? FOR UPDATE', [$code]);
            $id = $this->insert('INSERT INTO promotion_revisions
                (promotion_id,name,category_id,threshold,discount,requires_claim,stack_member_price,ends_at,published_by)
                VALUES (?,?,?,?,?,?,?,?,?)', [$promotion['id'], $name, $category, $threshold, $discount,
                    (int) $requiresClaim, (int) $stackMemberPrice, $endsAt, $admin]);
            $this->query('UPDATE promotions SET current_revision_id=? WHERE id=?', [$id, $promotion['id']]);
            $this->audit($admin, 'promotion.published', (int) $promotion['id'], ['revision_id' => $id]);
            return $id;
        });
    }

    public function setPromotionEnabled(int $admin, int $promotion, bool $enabled): void
    {
        $this->actor($admin, 'admin');
        $this->transaction(function () use ($admin, $promotion, $enabled): void {
            $row = $this->one('SELECT enabled FROM promotions WHERE id=? FOR UPDATE', [$promotion]);
            if (!$row) {
                throw new RuleViolation('活动不存在。');
            }
            if ((bool) $row['enabled'] !== $enabled) {
                $this->query('UPDATE promotions SET enabled=? WHERE id=?', [(int) $enabled, $promotion]);
                $this->audit($admin, 'promotion.availability_changed', $promotion, ['enabled' => $enabled]);
            }
        });
    }

    public function claimCoupon(int $buyer, int $promotion): bool
    {
        $this->actor($buyer, 'buyer');
        return $this->transaction(function () use ($buyer, $promotion): bool {
            $this->lockCart($buyer);
            $existing = $this->one('SELECT revision_id FROM coupon_claims WHERE buyer_id=? AND promotion_id=?', [$buyer, $promotion]);
            if ($existing) {
                return false;
            }
            $active = $this->one('SELECT r.id FROM promotions p JOIN promotion_revisions r ON r.id=p.current_revision_id
                WHERE p.id=? AND p.enabled=1 AND r.requires_claim=1 AND (r.ends_at IS NULL OR r.ends_at>UTC_TIMESTAMP())
                FOR SHARE', [$promotion]);
            if (!$active) {
                throw new RuleViolation('这个优惠券活动当前无法领取。');
            }
            $this->query('INSERT INTO coupon_claims (buyer_id,promotion_id,revision_id) VALUES (?,?,?)',
                [$buyer, $promotion, $active['id']]);
            $this->audit($buyer, 'promotion.coupon_claimed', $promotion, ['revision_id' => (int) $active['id']]);
            return true;
        });
    }

    public function promotionOffers(): array
    {
        return $this->rows('SELECT p.id,p.code,r.name,r.threshold,r.discount,r.requires_claim,r.stack_member_price,
            r.ends_at,c.name AS category_name FROM promotions p JOIN promotion_revisions r ON r.id=p.current_revision_id
            LEFT JOIN product_categories c ON c.id=r.category_id
            WHERE p.enabled=1 AND (r.ends_at IS NULL OR r.ends_at>UTC_TIMESTAMP()) ORDER BY p.id');
    }

    public function buyerCoupons(int $buyer): array
    {
        $this->actor($buyer, 'buyer');
        return $this->rows("SELECT p.id,r.name,r.discount,r.threshold,r.ends_at,r.stack_member_price,
            CASE WHEN c.redeemed_at IS NOT NULL THEN 'used' WHEN c.order_id IS NOT NULL THEN 'held'
                 WHEN p.enabled=0 OR r.ends_at<=UTC_TIMESTAMP() THEN 'unavailable' ELSE 'available' END AS status
            FROM coupon_claims c JOIN promotions p ON p.id=c.promotion_id JOIN promotion_revisions r ON r.id=c.revision_id
            WHERE c.buyer_id=? ORDER BY c.claimed_at DESC,p.id DESC", [$buyer]);
    }

    private function pricePromotion(int $buyer, array $items, ?int $coupon, bool $lock): array
    {
        $mode = $lock ? ' FOR SHARE' : '';
        if ($coupon !== null) {
            $campaign = $this->one("SELECT r.* FROM coupon_claims c JOIN promotions p ON p.id=c.promotion_id
                JOIN promotion_revisions r ON r.id=c.revision_id
                WHERE c.buyer_id=? AND c.promotion_id=? AND c.order_id IS NULL AND c.redeemed_at IS NULL
                    AND p.enabled=1 AND (r.ends_at IS NULL OR r.ends_at>UTC_TIMESTAMP())" . $mode, [$buyer, $coupon]);
            $offer = $campaign ? $this->promotionPrice($items, $campaign) : null;
            if (!$offer) {
                throw new RuleViolation('优惠券已失效、被使用或当前商品未达到活动门槛。');
            }
            return $offer;
        }
        $best = ['promotion' => null, 'prices' => [], 'parts' => [], 'charge' => 0];
        foreach ($items as $id => $item) {
            $best['prices'][$id] = $item['unit_price'];
            $best['charge'] += $item['unit_price'] * $item['quantity'];
        }
        $campaigns = $this->rows('SELECT r.* FROM promotions p JOIN promotion_revisions r ON r.id=p.current_revision_id
            WHERE p.enabled=1 AND r.requires_claim=0 AND (r.ends_at IS NULL OR r.ends_at>UTC_TIMESTAMP()) ORDER BY p.id' . $mode);
        foreach ($campaigns as $campaign) {
            $offer = $this->promotionPrice($items, $campaign);
            if ($offer && $offer['charge'] < $best['charge']) {
                $best = $offer;
            }
        }
        return $best;
    }

    private function promotionPrice(array $items, array $campaign): ?array
    {
        $eligibleShops = $eligible = $prices = [];
        $charge = 0;
        foreach ($items as $id => $item) {
            $inScope = $campaign['category_id'] === null || (int) $campaign['category_id'] === $item['category_id'];
            $prices[$id] = $inScope && !$campaign['stack_member_price'] ? $item['list_price'] : $item['unit_price'];
            $weight = $prices[$id] * $item['quantity'];
            $charge += $weight;
            if ($inScope) {
                $eligibleShops[$item['shop']] = true;
                $eligible[$id] = $weight;
            }
        }
        if (count($eligibleShops) < 2 || array_sum($eligible) < (int) $campaign['threshold']) {
            return null;
        }
        $amount = (int) $campaign['discount'];
        return [
            'promotion' => ['id' => (int) $campaign['promotion_id'], 'revision_id' => (int) $campaign['id'],
                'name' => $campaign['name'], 'claimed' => (bool) $campaign['requires_claim']],
            'prices' => $prices, 'parts' => self::allocate($amount, $eligible), 'charge' => $charge - $amount,
        ];
    }
}
