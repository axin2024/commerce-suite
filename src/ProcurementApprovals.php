<?php

declare(strict_types=1);

trait ProcurementApprovals
{
    public function procurementFinanceAccounts(int $finance): array
    {
        $this->actor($finance,'finance');
        return $this->rows('SELECT c.id,c.billing_name,c.credit_limit_minor,c.accepting_requests,
            COALESCE((SELECT SUM(e.amount_minor) FROM procurement_credit_entries e WHERE e.company_id=c.id),0) AS credit_used_minor
            FROM procurement_companies c ORDER BY c.billing_name,c.id');
    }

    public function procurementStoreAwards(int $actor,int $store,?int $order=null): array
    {
        if (!$this->procurementStores($actor,$store)) {
            return [];
        }
        $sql = 'SELECT a.*,o.id AS order_id,q.subject,b.limit_minor-b.used_minor AS remaining_minor,
            c.credit_limit_minor-COALESCE((SELECT SUM(ce.amount_minor) FROM procurement_credit_entries ce WHERE ce.company_id=c.id),0) AS credit_available_minor
            FROM procurement_awards a JOIN procurement_rfqs q ON q.id=a.rfq_id JOIN procurement_budgets b ON b.id=a.budget_id
            JOIN procurement_companies c ON c.id=a.company_id
            LEFT JOIN procurement_orders o ON o.award_id=a.id WHERE a.ship_to=?';
        $params = [$store];
        if ($order!==null) {
            $sql .= ' AND o.id=?';
            $params[] = $order;
        }
        $awards = $this->rows($sql . ' ORDER BY a.id DESC',$params);
        foreach ($awards as &$award) {
            $award['lines'] = $this->rows('SELECT l.*,rl.specification FROM procurement_award_lines l
                JOIN procurement_rfq_lines rl ON rl.line_no=l.line_no AND rl.rfq_id=? WHERE award_id=? ORDER BY l.line_no',
                [$award['rfq_id'],$award['id']]);
            if ($award['order_id']) {
                $received = (int)$this->one('SELECT COALESCE(SUM(accepted_qty),0) AS qty FROM procurement_receipts WHERE order_id=?',[$award['order_id']])['qty'];
                $balance = (int)$this->one('SELECT COALESCE(SUM(amount_minor),0) AS balance FROM procurement_credit_entries WHERE order_id=?',[$award['order_id']])['balance'];
                $approved = array_sum(array_column($award['lines'],'approved_qty'));
                $award['delivery_state'] = match (true) {
                    $received===0=>'待收货',
                    $received<$approved=>'部分收货',
                    $balance===0=>'结清',
                    default=>'已收齐',
                };
            }
        }
        return $awards;
    }

    public function setProcurementCredit(int $finance, int $company, int $limit): void
    {
        $this->actor($finance,'finance');
        $this->transaction(function () use ($finance,$company,$limit) {
            $org = $this->one('SELECT id FROM procurement_companies WHERE id=? FOR UPDATE',[$company]);
            if (!$org || $limit < $this->procurementCreditUsed($company) || $limit > 1000000000000000) {
                throw new RuleViolation('请核对法人及授信额度，授信不能低于已占用余额。');
            }
            $this->query('UPDATE procurement_companies SET credit_limit_minor=? WHERE id=?',[$limit,$company]);
            $this->audit($finance,'procurement.credit.limit',$company,['limit_minor'=>$limit]);
        });
    }

    private function procurementCreditUsed(int $company): int
    {
        return (int)$this->one('SELECT COALESCE(SUM(amount_minor),0) AS used_minor
            FROM procurement_credit_entries WHERE company_id=?',[$company])['used_minor'];
    }

    public function setProcurementBudget(int $manager, int $store, string $month, int $limit, int $threshold): int
    {
        $period = $this->procurementDate($month . '-01');
        if ($limit < 0 || $threshold < 0 || max($limit,$threshold) > 1000000000000000) {
            throw new RuleViolation('请核对预算额度及总部复核阈值。');
        }
        return $this->transaction(function () use ($manager,$store,$period,$limit,$threshold) {
            $st = $this->one('SELECT st.company_id,st.cost_center FROM procurement_stores st
                JOIN procurement_companies c ON c.id=st.company_id WHERE st.id=? AND c.manager_id=? FOR UPDATE',[$store,$manager]);
            if (!$st) {
                throw new RuleViolation('仅总部负责人可配置成本中心预算。');
            }
            $scope = [$st['company_id'],$st['cost_center'],$period];
            $budget = $this->one('SELECT id,used_minor FROM procurement_budgets
                WHERE company_id=? AND cost_center=? AND period=? FOR UPDATE',$scope);
            if ($budget && $limit < $budget['used_minor']) {
                throw new RuleViolation('新预算不足以覆盖现有占用。');
            }
            if ($budget) {
                $id = (int)$budget['id'];
                $this->query('UPDATE procurement_budgets SET limit_minor=?,review_above_minor=? WHERE id=?',[$limit,$threshold,$id]);
            } else {
                $id = $this->insert('INSERT INTO procurement_budgets (company_id,cost_center,period,limit_minor,review_above_minor)
                    VALUES (?,?,?,?,?)',[...$scope,$limit,$threshold]);
            }
            $this->audit($manager,'procurement.budget.configured',$id,['limit_minor'=>$limit,'threshold'=>$threshold]);
            return $id;
        });
    }

    private function awardQuantities(array $items): array
    {
        if (!$items || count($items)>100) {
            throw new RuleViolation('accepted_lines 需要 1 至 100 行。');
        }
        $lines = [];
        foreach ($items as $item) {
            $line = $item['line'] ?? null;
            $qty = $item['qty'] ?? null;
            if (!is_int($line) || $line<=0 || isset($lines[$line]) || !is_int($qty) || $qty<=0 || $qty>1000000) {
                throw new RuleViolation('accepted_lines 中的行号或数量无效，行号不可重复。');
            }
            $lines[$line] = $qty;
        }
        ksort($lines,SORT_NUMERIC);
        return $lines;
    }

    public function proposeProcurementAward(int $actor,int $rfq,int $bid,int $shipTo,array $items,string $key): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$key)) {
            throw new RuleViolation('请提供有效的请求幂等键。');
        }
        $quantities = $this->awardQuantities($items);
        $hash = hash('sha256',json_encode([$rfq,$bid,$shipTo,$quantities],JSON_THROW_ON_ERROR));
        return $this->transaction(function () use ($actor,$rfq,$bid,$shipTo,$key,$hash,$quantities) {
            $q = $this->one('SELECT q.store_id,st.company_id,q.deadline<=UTC_TIMESTAMP() AS closed
                FROM procurement_rfqs q JOIN procurement_stores st ON st.id=q.store_id
                JOIN procurement_companies c ON c.id=st.company_id WHERE q.id=? FOR UPDATE',[$rfq]);
            $dest = $this->procurementStoreForDuty($actor,$shipTo,'purchaser');
            if (!$q || !$dest || (int)$q['company_id']!==(int)$dest['company_id'] || !$this->procurementStoreForDuty($actor,(int)$q['store_id'],'purchaser')) {
                throw new RuleViolation('未找到该询价单或收货门店。',404);
            }
            $prior = $this->one('SELECT id,request_hash,requester FROM procurement_awards WHERE company_id=? AND request_key=?',
                [$q['company_id'],$key]);
            if ($prior) {
                if ((int)$prior['requester']!==$actor || $prior['request_hash']!==$hash) {
                    throw new RuleViolation('幂等键已用于其他授标内容。',409);
                }
                return $this->procurementAwardResult((int)$prior['id']);
            }
            $offer = $this->one("SELECT b.id,b.freight_minor FROM procurement_bids b JOIN shops sh ON sh.id=b.shop_id
                JOIN procurement_suppliers s ON s.shop_id=sh.id AND s.company_id=? AND s.enabled=1
                WHERE b.rfq_id=? AND b.id=? AND b.late=0 AND sh.status='active' AND NOT EXISTS
                (SELECT 1 FROM procurement_bids n WHERE n.rfq_id=b.rfq_id AND n.shop_id=b.shop_id AND n.late=0 AND n.revision>b.revision)",
                [$q['company_id'],$rfq,$bid]);
            if (!$q['closed'] || !$offer) {
                throw new RuleViolation('bid_revision 必须为截止后可授标的有效报价。');
            }
            if ($this->one("SELECT id FROM procurement_awards WHERE rfq_id=? AND status<>'rejected' LIMIT 1",[$rfq])) {
                throw new RuleViolation('该询价单已有生效授标。',409);
            }
            $budget = $this->one("SELECT * FROM procurement_budgets WHERE company_id=? AND cost_center=? AND period=? FOR UPDATE",
                [$q['company_id'],$dest['cost_center'],$this->procurementBillingClock((int)$q['company_id'])->format('Y-m-01')]);
            if (!$budget) {
                throw new RuleViolation('收货成本中心尚未配置当月预算。');
            }
            $offered = array_column($this->rows('SELECT line_no,quantity,price_minor FROM procurement_bid_lines WHERE bid_id=?',[$bid]),null,'line_no');
            $total = (int)$offer['freight_minor'];
            foreach ($quantities as $line=>$qty) {
                if (!isset($offered[$line]) || $qty>$offered[$line]['quantity']) {
                    throw new RuleViolation("accepted_lines 行 $line 超过投标数量。");
                }
                $total += $qty*(int)$offered[$line]['price_minor'];
            }
            if ($total > (int)$budget['limit_minor']-(int)$budget['used_minor']) {
                throw new RuleViolation('成本中心当月预算不足。',409);
            }
            $status = $total > $budget['review_above_minor'] ? 'pending' : 'approved';
            $award = $this->insert('INSERT INTO procurement_awards
                (company_id,rfq_id,bid_id,ship_to,budget_id,requester,request_key,request_hash,status,total_minor)
                VALUES (?,?,?,?,?,?,?,?,?,?)',[$q['company_id'],$rfq,$bid,$shipTo,$budget['id'],$actor,$key,$hash,$status,$total]);
            foreach ($quantities as $line=>$qty) {
                $this->query('INSERT INTO procurement_award_lines (award_id,line_no,requested_qty,approved_qty,price_minor) VALUES (?,?,?,?,?)',
                    [$award,$line,$qty,$status==='approved' ? $qty : 0,$offered[$line]['price_minor']]);
            }
            $this->query('UPDATE procurement_budgets SET used_minor=used_minor+? WHERE id=?',[$total,$budget['id']]);
            $this->audit($actor,'procurement.award.proposed',$award,['rfq'=>$rfq,'status'=>$status,'reserved_minor'=>$total]);
            if ($status==='approved') {
                $this->confirmProcurementOrder($award);
            }
            return $this->procurementAwardResult($award);
        });
    }

    public function decideProcurementAward(int $manager, int $award, array $items, string $reason): array
    {
        $quantities = $items ? $this->awardQuantities($items) : [];
        $reason = trim($reason);
        return $this->transaction(function () use ($manager,$award,$quantities,$reason) {
            $a = $this->one('SELECT a.*,b.freight_minor FROM procurement_awards a
                JOIN procurement_companies c ON c.id=a.company_id JOIN procurement_bids b ON b.id=a.bid_id
                WHERE a.id=? AND c.manager_id=? FOR UPDATE',[$award,$manager]);
            if (!$a) {
                throw new RuleViolation('未找到需要审批的授标。',404);
            }
            $lines = $this->rows('SELECT line_no,requested_qty,approved_qty,price_minor FROM procurement_award_lines WHERE award_id=?',[$award]);
            $requested = $approved = [];
            $prices = [];
            foreach ($lines as $line) {
                $n = (int)$line['line_no'];
                $requested[$n] = (int)$line['requested_qty'];
                $prices[$n] = (int)$line['price_minor'];
                if ($line['approved_qty']) {
                    $approved[$n] = (int)$line['approved_qty'];
                }
            }
            if ($a['status']!=='pending') {
                if ((int)$a['decided_by']===$manager && $quantities==$approved && $reason===($a['decision_reason'] ?? '')) {
                    return $this->procurementAwardResult($award);
                }
                throw new RuleViolation('该授标已处理。',409);
            }
            $total = $quantities ? (int)$a['freight_minor'] : 0;
            foreach ($quantities as $line=>$qty) {
                if ($qty>($requested[$line] ?? 0)) {
                    throw new RuleViolation("审批行 $line 超过申请数量。");
                }
                $total += $qty*$prices[$line];
            }
            if ($reason!=='' || $quantities!=$requested) {
                $this->procurementText($reason,200);
            }
            foreach ($requested as $line=>$qty) {
                $this->query('UPDATE procurement_award_lines SET approved_qty=? WHERE award_id=? AND line_no=?',
                    [$quantities[$line] ?? 0,$award,$line]);
            }
            $release = (int)$a['total_minor']-$total;
            $this->query('UPDATE procurement_budgets SET used_minor=used_minor-? WHERE id=?',[$release,$a['budget_id']]);
            $state = $total ? 'approved' : 'rejected';
            $this->query('UPDATE procurement_awards SET total_minor=?,status=?,decided_by=?,decision_reason=? WHERE id=?',
                [$total,$state,$manager,$reason,$award]);
            if ($total) {
                $this->confirmProcurementOrder($award);
            }
            $this->audit($manager,'procurement.award.decided',$award,['decision'=>$state,'released_minor'=>$release,'reason'=>$reason]);
            return $this->procurementAwardResult($award);
        });
    }

    private function procurementAwardResult(int $award): array
    {
        return $this->one('SELECT a.id AS award_id,a.status,o.id AS order_id,COALESCE(o.total_minor,0) AS credit_reserved_minor
            FROM procurement_awards a LEFT JOIN procurement_orders o ON o.award_id=a.id WHERE a.id=?',[$award]);
    }

    private function confirmProcurementOrder(int $award): void
    {
        $a = $this->one("SELECT a.company_id,a.total_minor,c.credit_limit_minor,c.billing_name,st.delivery_address
            FROM procurement_awards a JOIN procurement_companies c ON c.id=a.company_id JOIN procurement_stores st ON st.id=a.ship_to
            WHERE a.id=? AND a.status='approved' FOR UPDATE",[$award]);
        if (!$a) {
            throw new RuleViolation('采购审批尚未通过。');
        }
        $need = (int)$a['total_minor'];
        $gap = $need - ((int)$a['credit_limit_minor'] - $this->procurementCreditUsed((int)$a['company_id']));
        if ($gap>0) {
            throw new RuleViolation("法人授信不足，缺口 {$gap} 分。",409);
        }
        $order = $this->insert('INSERT INTO procurement_orders (award_id,billing_name,ship_address,total_minor) VALUES (?,?,?,?)',
            [$award,$a['billing_name'],$a['delivery_address'],$need]);
        $this->query('INSERT INTO procurement_credit_entries (company_id,order_id,source_key,amount_minor) VALUES (?,?,?,?)',
            [$a['company_id'],$order,'order-'.$order,$need]);
        $this->query("UPDATE procurement_awards SET status='ordered' WHERE id=?",[$award]);
    }
}
