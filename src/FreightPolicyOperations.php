<?php

declare(strict_types=1);

trait FreightPolicyOperations
{
    public function freightStrategyLabels(): array
    {
        return $this->freightStrategies->labels();
    }

    public function merchantFreightHistory(int $merchant): array
    {
        $actor = $this->shopActor($merchant, 'owner');
        return $this->rows('SELECT id,strategy,base_fee,free_threshold,published_at FROM freight_policy_revisions
            WHERE shop_id=? ORDER BY id DESC LIMIT 10', [$actor['shop_id']]);
    }

    public function publishFreightPolicy(
        int $merchant, int $expectedRevision, string $strategy, int $baseFee, ?int $freeThreshold,
    ): int {
        if (!$this->freightStrategies->supports($strategy) || $baseFee < 0 || $baseFee > FreightStrategies::MAX_BASE_FEE
            || ($freeThreshold !== null && ($freeThreshold < 1 || $freeThreshold > FreightStrategies::MAX_FREE_THRESHOLD))) {
            throw new RuleViolation('请选择可用的计费方式并检查运费和包邮门槛。');
        }
        return $this->transaction(function () use ($merchant, $expectedRevision, $strategy, $baseFee, $freeThreshold): int {
            $actor = $this->shopActor($merchant, 'owner');
            $shop = $this->one('SELECT id,status,freight_policy_revision_id FROM shops WHERE id=? FOR UPDATE', [$actor['shop_id']]);
            if (!in_array($shop['status'] ?? null, ['active', 'suspended'], true)) {
                throw new RuleViolation('店铺通过审核后可发布运费规则。');
            }
            if ($expectedRevision !== (int) $shop['freight_policy_revision_id']) {
                throw new RuleViolation('运费规则已更新，请刷新后再发布。');
            }
            $id = $this->insert('INSERT INTO freight_policy_revisions (shop_id,strategy,base_fee,free_threshold,published_by)
                VALUES (?,?,?,?,?)', [$shop['id'], $strategy, $baseFee, $freeThreshold, $merchant]);
            $this->query('UPDATE shops SET freight_policy_revision_id=? WHERE id=?', [$id, $shop['id']]);
            $this->audit($merchant, 'freight.policy_published', $id, ['shop_id' => (int) $shop['id']]);
            return $id;
        });
    }

    private function deliveryFreight(int $shop, int $goods, array $warehouses, bool $lock): array
    {
        $policy = $this->one('SELECT r.id,r.strategy,r.base_fee,r.free_threshold FROM shops s
            JOIN freight_policy_revisions r ON r.id=s.freight_policy_revision_id WHERE s.id=?'
            . ($lock ? ' FOR SHARE OF r' : ''), [$shop]);
        if ($policy === null) {
            return ['shipping' => FreightStrategies::DEFAULT_FEE * count($warehouses)];
        }
        if (!$this->freightStrategies->supports($policy['strategy'])) {
            throw new RuleViolation('店铺运费规则暂不可用，请联系商家。');
        }
        return [
            'shipping' => $policy['free_threshold'] !== null && $goods >= (int) $policy['free_threshold']
                ? 0 : $this->freightStrategies->charge($policy['strategy'], (int) $policy['base_fee'], $warehouses),
            'freight_policy_revision_id' => (int) $policy['id'],
        ];
    }
}
