<?php

declare(strict_types=1);

trait SettlementOperations
{
    private const SETTLEMENT_TOTALS = "COUNT(*) AS entries, COALESCE(MAX(id),0) AS last_entry,
        COALESCE(SUM(CASE WHEN kind='sale' THEN gross ELSE 0 END),0) AS sales,
        COALESCE(-SUM(CASE WHEN kind='refund' THEN gross ELSE 0 END),0) AS refunds,
        COALESCE(SUM(commission),0) AS commission, COALESCE(SUM(gross-commission),0) AS net";

    public function previewSettlement(int $finance, int $shop, string $month, string $currency): array
    {
        $this->actor($finance, 'finance');
        [$period, $cutoff] = self::settlementRange($month, $currency);
        return $this->transaction(function () use ($shop, $period, $cutoff, $currency): array {
            $account = $this->settlementShop($shop, 'FOR SHARE');
            return $this->settlementPreview($account, $period, $cutoff, $currency);
        });
    }

    public function freezeSettlement(int $finance, int $shop, string $month, string $currency, string $hash): int
    {
        $this->actor($finance, 'finance');
        [$period, $cutoff] = self::settlementRange($month, $currency);
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuleViolation('请重新生成结算预览。');
        }
        return $this->transaction(function () use ($finance, $shop, $period, $cutoff, $currency, $hash): int {
            $account = $this->settlementShop($shop, 'FOR UPDATE');
            $existing = $this->one('SELECT id,preview_hash FROM settlements WHERE shop_id=? AND currency=? AND period=?',
                [$shop, $currency, $period]);
            if ($existing) {
                if ($existing['preview_hash'] !== $hash) {
                    throw new RuleViolation('该结算月份已关期。');
                }
                return (int) $existing['id'];
            }
            $preview = $this->settlementPreview($account, $period, $cutoff, $currency);
            if (!hash_equals($preview['hash'], $hash)) {
                throw new RuleViolation('分录或结算资料已变化，请重新预览。');
            }
            if ($preview['blocked']) {
                throw new RuleViolation($preview['blocked']);
            }
            $id = $this->insert("INSERT INTO settlements (shop_id,created_by,amount,period,currency,state,payee_name,
                agreement_version,preview_hash) VALUES (?,?,?,?,?,'review_pending',?,?,?)", [
                $shop, $finance, $preview['net'], $period, $currency, $account['payee_name'], $account['agreement_version'], $hash,
            ]);
            $attached = $this->query('UPDATE ledger SET settlement_id=? WHERE shop_id=? AND currency=? AND settlement_id IS NULL
                AND created_at<?', [$id, $shop, $currency, $cutoff]);
            if ($attached->rowCount() !== $preview['entries']) {
                throw new LogicException('Settlement entry count changed while freezing.');
            }
            $this->audit($finance, 'settlement.frozen', $id, ['shop_id' => $shop, 'period' => $period,
                'currency' => $currency, 'net' => $preview['net'], 'entries' => $preview['entries']]);
            return $id;
        });
    }

    public function reviewSettlement(int $finance, int $id, int $amount): void
    {
        $this->actor($finance, 'finance');
        $this->transaction(function () use ($finance, $id, $amount): void {
            $batch = $this->one('SELECT state,amount FROM settlements WHERE id=? FOR UPDATE', [$id]);
            if (!$batch || $amount !== (int) $batch['amount']) {
                throw new RuleViolation('结算单不存在或复核金额不符。');
            }
            if (in_array($batch['state'], ['payable', 'cleared', 'paid'], true)) {
                return;
            }
            if ($batch['state'] !== 'review_pending') {
                throw new RuleViolation('该结算单不可复核。');
            }
            $state = $amount === 0 ? 'cleared' : 'payable';
            $this->query('UPDATE settlements SET state=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?', [$state, $finance, $id]);
            $this->audit($finance, 'settlement.reviewed', $id, ['state' => $state, 'amount' => $amount]);
        });
    }

    public function recordSettlementTransfer(int $payout, int $id, string $reference, int $amount): void
    {
        $this->actor($payout, 'payout');
        $reference = trim($reference);
        if ($amount < 1 || preg_match('/^[a-z0-9_-]{1,80}$/iD', $reference) !== 1) {
            throw new RuleViolation('请填写有效的银行流水号和实付金额。');
        }
        try {
            $this->transaction(function () use ($payout, $id, $reference, $amount): void {
                $batch = $this->one('SELECT state,amount,reviewed_by,transfer_reference FROM settlements WHERE id=? FOR UPDATE', [$id]);
                if (!$batch || (int) $batch['amount'] !== $amount || (int) $batch['reviewed_by'] === $payout) {
                    throw new RuleViolation('无权登记该结算单或打款金额不符。');
                }
                if ($batch['state'] === 'paid' && $batch['transfer_reference'] === $reference) {
                    return;
                }
                if ($batch['state'] !== 'payable') {
                    throw new RuleViolation('仅可为已复核、待付款的结算单登记流水。');
                }
                $this->query("UPDATE settlements SET state='paid',paid_by=?,paid_at=UTC_TIMESTAMP(),transfer_reference=? WHERE id=?",
                    [$payout, $reference, $id]);
                $this->audit($payout, 'settlement.payment_recorded', $id, ['reference' => $reference, 'amount' => $amount]);
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuleViolation('该银行流水已用于其他结算单。');
            }
            throw $error;
        }
    }

    public function settlementList(int $actor, int $shop = 0, string $month = '', ?int $before = null): array
    {
        $scope = $this->settlementScope($actor);
        $conditions = [];
        $params = [];
        if ($scope['shop_id'] !== null) {
            if ($shop && $shop !== $scope['shop_id']) {
                throw new RuleViolation('无权查看其他店铺结算单。');
            }
            $shop = $scope['shop_id'];
        }
        if ($scope['role'] === 'payout') {
            $conditions[] = "b.state IN ('payable','paid')";
        }
        if ($shop !== 0) {
            $conditions[] = 'b.shop_id=?';
            $params[] = $shop;
        }
        if ($month !== '') {
            [$period] = self::settlementRange($month, 'CNY');
            $conditions[] = 'b.period=?';
            $params[] = $period;
        }
        if ($before !== null) {
            $conditions[] = 'b.id<?';
            $params[] = $before;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return $this->rows('SELECT b.*,s.name AS shop_name FROM settlements b JOIN shops s ON s.id=b.shop_id'
            . $where . ' ORDER BY b.id DESC LIMIT 51', $params);
    }

    public function settlementDetail(int $actor, int $id): array
    {
        return $this->settlementForActor($actor, $id) + $this->settlementTotals('settlement_id=?', [$id]);
    }

    private function settlementForActor(int $actor, int $id): array
    {
        $scope = $this->settlementScope($actor);
        $batch = $this->one('SELECT b.*,s.name AS shop_name FROM settlements b JOIN shops s ON s.id=b.shop_id WHERE b.id=?', [$id]);
        if (!$batch || ($scope['shop_id'] !== null && $scope['shop_id'] !== (int) $batch['shop_id'])
            || ($scope['role'] === 'payout' && !in_array($batch['state'], ['payable', 'paid'], true))) {
            throw new RuleViolation('结算单不存在或不可访问。');
        }
        return $batch;
    }

    public function settlementEntries(int $actor, int $id, int $after = 0): array
    {
        $this->settlementExportScope($actor, $id);
        return $this->rows('SELECT id,event_key,merchant_order_id,kind,currency,gross,commission,created_at
            FROM ledger WHERE settlement_id=? AND id>? ORDER BY id LIMIT 101', [$id, $after]);
    }

    public function exportSettlement(int $actor, int $id): Generator
    {
        $batch = $this->settlementExportScope($actor, $id);
        yield ['shop_id', 'period_utc', 'settlement_id', 'entry_id', 'event', 'merchant_order_id', 'kind',
            'currency', 'gross_minor', 'commission_minor', 'net_minor', 'posted_at_utc'];
        $after = 0;
        do {
            $rows = $this->rows('SELECT id,event_key,merchant_order_id,kind,currency,gross,commission,created_at
                FROM ledger WHERE settlement_id=? AND id>? ORDER BY id LIMIT 1000', [$id, $after]);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                yield [$batch['shop_id'], $batch['period'] ? substr($batch['period'], 0, 7) : '',
                    $id, $after, $row['event_key'], $row['merchant_order_id'], $row['kind'], $row['currency'],
                    $row['gross'], $row['commission'], (int) $row['gross'] - (int) $row['commission'], $row['created_at']];
            }
        } while (count($rows) === 1000);
    }

    private function settlementExportScope(int $actor, int $id): array
    {
        $this->actor($actor, 'admin', 'finance', 'merchant');
        return $this->settlementForActor($actor, $id);
    }

    private function settlementScope(int $actor): array
    {
        $user = $this->actor($actor, 'admin', 'finance', 'merchant', 'payout');
        return ['role' => $user['role'], 'shop_id' => $user['role'] === 'merchant'
            ? (int) $this->shopActor($actor, 'finance')['shop_id'] : null];
    }

    private static function settlementRange(string $month, string $currency): array
    {
        if (preg_match('/^[2-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month) !== 1
            || $month > gmdate('Y-m') || $currency !== 'CNY') {
            throw new RuleViolation('请选择有效的结算月份和币种。');
        }
        $period = $month . '-01';
        $cutoff = (new DateTimeImmutable($period, new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d');
        return [$period, $cutoff];
    }

    private function settlementShop(int $shop, string $lock): array
    {
        return $this->one('SELECT id,name,payee_name,agreement_version FROM shops WHERE id=? ' . $lock, [$shop])
            ?? throw new RuleViolation('店铺不存在。');
    }

    private function settlementPreview(array $shop, string $period, string $cutoff, string $currency): array
    {
        $latest = $this->one('SELECT MAX(period) AS period FROM settlements WHERE shop_id=? AND currency=?',
            [$shop['id'], $currency])['period'];
        $totals = $this->settlementTotals('shop_id=? AND currency=? AND settlement_id IS NULL AND created_at<?',
            [$shop['id'], $currency, $cutoff]);
        $blocked = match (true) {
            $latest !== null && $period <= $latest => '该月份已关期或早于最后结算月份。',
            $totals['entries'] === 0 => '该月份无可结算分录。',
            $totals['net'] < 0 => '应结净额为负，分录将继续结转。',
            $totals['net'] > 0 && (!$shop['payee_name'] || !$shop['agreement_version']) => '请先核实店铺收款主体及结算协议。',
            default => null,
        };
        // Postings are appended under the same shop lock; a close changes the month or removes entries from this set.
        return $totals + ['shop_name' => $shop['name'], 'month' => substr($period, 0, 7), 'currency' => $currency,
            'blocked' => $blocked, 'hash' => hash('sha256', json_encode([$shop, $period, $currency, $latest, $totals], JSON_THROW_ON_ERROR))];
    }

    private function settlementTotals(string $where, array $params): array
    {
        return array_map('intval', $this->one('SELECT ' . self::SETTLEMENT_TOTALS . ' FROM ledger WHERE ' . $where, $params));
    }
}
