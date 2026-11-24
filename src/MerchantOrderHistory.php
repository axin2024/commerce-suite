<?php

declare(strict_types=1);

trait MerchantOrderHistory
{
    public const ORDER_HISTORY_PAGE_SIZE = 50;

    public function merchantOrderHistory(int $merchant, string $from, string $through, string $before = ''): array
    {
        $shop = (int) $this->shopActor($merchant, 'fulfillment')['shop_id'];
        $dateFormat = '/^\d{4}-\d{2}-\d{2}$/D';
        if (!preg_match($dateFormat, $from) || !preg_match($dateFormat, $through)) {
            throw new RuleViolation('请选择有效的订单日期范围。');
        }
        $utc = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $through, $utc);
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $through
            || $from < '1000-01-01' || $through >= '9999-12-31' || $start > $end) {
            throw new RuleViolation('请选择有效的订单日期范围。');
        }
        $until = $end->modify('+1 day');
        $params = [$shop, $start->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')];
        $cursor = '';
        if ($before !== '') {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,19})$/D', $before, $match)
                || !filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                throw new RuleViolation('翻页位置无效，请重新查询。');
            }
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1], $utc);
            if (!$time || $time->format('Y-m-d H:i:s') !== $match[1] || $time < $start || $time >= $until) {
                throw new RuleViolation('翻页位置不在当前日期范围内，请重新查询。');
            }
            $cursor = ' AND (m.placed_at<? OR (m.placed_at=? AND m.id<?))';
            array_push($params, $match[1], $match[1], (int) $match[2]);
        }
        $limit = self::ORDER_HISTORY_PAGE_SIZE;
        $rows = $this->rows('SELECT m.id,m.order_id,m.placed_at,m.status,m.total,m.shipping,m.freight_policy_revision_id,o.channel
            FROM merchant_orders m JOIN orders o ON o.id=m.order_id
            WHERE m.shop_id=? AND m.placed_at>=? AND m.placed_at<?' . $cursor . '
            ORDER BY m.placed_at DESC,m.id DESC LIMIT ' . ($limit + 1), $params);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[array_key_last($rows)];
            $next = $last['placed_at'] . '|' . $last['id'];
        }
        return ['rows' => $rows, 'next' => $next];
    }
}
