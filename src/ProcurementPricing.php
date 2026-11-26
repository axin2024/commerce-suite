<?php

declare(strict_types=1);

trait ProcurementPricing
{
    public function procurementSupplierOptions(int $actor, int $company): array
    {
        return $this->rows("SELECT sh.id,sh.name,COALESCE(s.enabled,0) AS enabled
            FROM procurement_companies c CROSS JOIN shops sh
            LEFT JOIN procurement_suppliers s ON s.shop_id=sh.id AND s.company_id=c.id
            WHERE c.id=? AND c.manager_id=? AND (sh.status='active' OR s.enabled=1)
            ORDER BY sh.name,sh.id", [$company,$actor]);
    }

    public function procurementSupplierAccounts(int $merchant): array
    {
        $shop = $this->shopActor($merchant, 'catalog');
        return $this->rows('SELECT c.id,c.billing_name FROM procurement_companies c
            JOIN procurement_suppliers s ON s.company_id=c.id WHERE s.shop_id=? AND s.enabled=1 ORDER BY c.id',
            [$shop['shop_id']]);
    }

    public function procurementMerchantSkus(int $merchant,string $term): array
    {
        $shop = $this->shopActor($merchant,'catalog');
        return $this->rows("SELECT id,sku,name,status FROM products WHERE shop_id=? AND status='active'
            AND (sku=? OR LOCATE(?,sku)>0 OR LOCATE(?,name)>0) ORDER BY (sku=?) DESC,id LIMIT 100",[$shop['shop_id'],$term,$term,$term,$term]);
    }

    public function procurementSupplierPreview(int $actor,int $company,int $product,int $qty,string $date): array
    {
        $shop = $this->shopActor($actor,'catalog');
        $scope = $this->one('SELECT c.manager_id,st.id FROM procurement_companies c JOIN procurement_stores st ON st.company_id=c.id
            JOIN procurement_suppliers s ON s.company_id=c.id AND s.enabled=1 JOIN products p ON p.shop_id=s.shop_id
            WHERE c.id=? AND s.shop_id=? AND p.id=? ORDER BY st.id LIMIT 1',[$company,$shop['shop_id'],$product]);
        if (!$scope) throw new RuleViolation('该企业或 SKU 当前不可试算。');
        return $this->procurementQuote((int)$scope['manager_id'],(int)$scope['id'],$product,$qty,$date);
    }

    public function procurementStoreCatalog(int $actor, int $store,string $term=''): array
    {
        return $this->rows("SELECT p.id,p.sku,p.name,sh.name AS supplier,MAX(a.revision) AS revision
            FROM procurement_stores st JOIN procurement_companies c ON c.id=st.company_id
            JOIN procurement_suppliers s ON s.company_id=c.id AND s.enabled=1
            JOIN shops sh ON sh.id=s.shop_id AND sh.status='active'
            JOIN products p ON p.shop_id=sh.id AND p.status='active'
            JOIN procurement_agreements a ON a.product_id=p.id AND a.company_id=c.id
            WHERE st.id=? AND (c.manager_id=? OR EXISTS (SELECT 1 FROM procurement_members m
            WHERE m.store_id=st.id AND m.user_id=? AND m.duty='purchaser'))
            AND (LOCATE(?,p.sku)>0 OR LOCATE(?,p.name)>0)
            GROUP BY p.id,p.sku,p.name,sh.name ORDER BY p.id LIMIT 100", [$store,$actor,$actor,$term,$term]);
    }

    public function authorizeProcurementSupplier(int $actor, int $company, int $shop, bool $enabled): void
    {
        $this->transaction(function () use ($actor, $company, $shop, $enabled) {
            if (!$this->one('SELECT id FROM procurement_companies WHERE id=? AND manager_id=? FOR UPDATE', [$company,$actor])) {
                throw new RuleViolation('无权修改该企业的签约商家。');
            }
            if (!$this->one('SELECT id FROM shops WHERE id=?', [$shop])) {
                throw new RuleViolation('商家不存在。');
            }
            $write = $this->query('INSERT INTO procurement_suppliers (company_id,shop_id,enabled) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE enabled=?', [$company,$shop,(int) $enabled,(int) $enabled]);
            if ($write->rowCount()) {
                $this->audit($actor, 'procurement.supplier.changed', $company, ['shop' => $shop, 'enabled' => $enabled]);
            }
        });
    }

    private function procurementDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
            throw new RuleViolation('请使用有效的年月日。');
        }
        return $value;
    }

    private function validateProcurementTiers(array $tiers): void
    {
        if (!$tiers || count($tiers) > 64) {
            throw new RuleViolation('每版协议需要 1 至 64 条阶梯。');
        }
        $intervals = [];
        foreach ($tiers as $row) {
            if (!is_array($row)) {
                throw new RuleViolation('阶梯行无效。');
            }
            foreach (['min' => 1000000,'max' => 1000000,'priority' => 65535,'price_minor' => 100000000] as $key => $limit) {
                $value = $row[$key] ?? null;
                if (!is_int($value) || $value < ($key === 'priority' ? 0 : 1) || $value > $limit) {
                    throw new RuleViolation("阶梯字段 {$key} 超出范围。");
                }
            }
            if ($row['min'] > $row['max']) {
                throw new RuleViolation('阶梯数量上限小于下限。');
            }
            foreach ($intervals[$row['priority']] ?? [] as [$start,$end]) {
                if ($row['min'] <= $end && $row['max'] >= $start) {
                    throw new RuleViolation("同优先级阶梯有重叠：{$start}–{$end} 与 {$row['min']}–{$row['max']}。");
                }
            }
            $intervals[$row['priority']][] = [$row['min'],$row['max']];
        }
    }

    public function publishProcurementPricing(int $merchant, int $company, int $product, int $expectedRevision,
        string $from, string $until, array $tiers): int
    {
        if ($this->procurementDate($from) > $this->procurementDate($until)) {
            throw new RuleViolation('协议截止日须在生效日之后或当天。');
        }
        $this->validateProcurementTiers($tiers);
        return $this->transaction(function () use ($merchant,$company,$product,$expectedRevision,$from,$until,$tiers) {
            $shop = $this->lockMerchantCatalog($merchant);
            if (!$this->one("SELECT p.id FROM products p JOIN procurement_suppliers s ON s.shop_id=p.shop_id
                WHERE p.id=? AND p.shop_id=? AND s.company_id=? AND s.enabled=1 AND p.status='active' FOR UPDATE",
                [$product,$shop,$company])) {
                throw new RuleViolation('请确认签约授权及本商家的在售 SKU。');
            }
            $current = $this->one('SELECT revision,valid_from FROM procurement_agreements WHERE company_id=? AND product_id=?
                ORDER BY revision DESC LIMIT 1 FOR UPDATE', [$company,$product]);
            if ((int) ($current['revision'] ?? 0) !== $expectedRevision || ($current && $from < $current['valid_from'])) {
                throw new RuleViolation('协议基版已变化或生效日期早于上一版。');
            }
            $id = $this->insert('INSERT INTO procurement_agreements (company_id,product_id,revision,valid_from,valid_until,author_id)
                VALUES (?,?,?,?,?,?)', [$company,$product,$expectedRevision+1,$from,$until,$merchant]);
            foreach ($tiers as $row) {
                $this->query('INSERT INTO procurement_price_tiers (agreement_id,quantity_min,quantity_max,priority,price_minor)
                    VALUES (?,?,?,?,?)', [$id,$row['min'],$row['max'],$row['priority'],$row['price_minor']]);
            }
            $this->audit($merchant, 'procurement.pricing.published', $id, ['product' => $product,'company' => $company]);
            return $id;
        });
    }

    public function procurementQuote(int $actor, int $store, int $product, int $qty, string $date): array
    {
        $this->procurementDate($date);
        if ($qty < 1 || $qty > 1000000) {
            throw new RuleViolation('询价数量超出范围。');
        }
        $row = $this->one("SELECT a.id AS agreement_id,a.revision,a.valid_until,t.price_minor,t.quantity_min,t.quantity_max
            FROM procurement_stores st JOIN procurement_companies c ON c.id=st.company_id
            JOIN products p ON p.id=? AND p.status='active'
            JOIN shops sh ON sh.id=p.shop_id AND sh.status='active'
            JOIN procurement_suppliers su ON su.company_id=c.id AND su.shop_id=sh.id AND su.enabled=1
            JOIN procurement_agreements a ON a.id=(SELECT a2.id FROM procurement_agreements a2
                WHERE a2.company_id=c.id AND a2.product_id=p.id AND a2.valid_from<=?
                ORDER BY a2.valid_from DESC,a2.id DESC LIMIT 1)
            JOIN procurement_price_tiers t ON t.agreement_id=a.id AND ? BETWEEN t.quantity_min AND t.quantity_max
            WHERE st.id=? AND a.valid_until>=? AND (c.manager_id=? OR EXISTS (SELECT 1 FROM procurement_members m
                WHERE m.store_id=st.id AND m.user_id=? AND m.duty='purchaser')) ORDER BY t.priority DESC LIMIT 1",
            [$product,$date,$qty,$store,$date,$actor,$actor]);
        if (!$row) {
            throw new RuleViolation('当前门店、日期与数量没有可用协议价。');
        }
        $row['quantity'] = $qty;
        $row['total_minor'] = (int) $row['price_minor'] * $qty;
        return $row;
    }
}
