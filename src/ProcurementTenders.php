<?php

declare(strict_types=1);

trait ProcurementTenders
{
    public function procurementInvitableShops(int $actor, int $store): array
    {
        $org = $this->procurementStores($actor,$store)[0] ?? null;
        if (!$org) {
            return [];
        }
        return $this->rows("SELECT sh.id,sh.name FROM shops sh JOIN procurement_suppliers s ON s.shop_id=sh.id
            WHERE s.company_id=? AND s.enabled=1 AND sh.status='active' ORDER BY sh.name",[$org['company_id']]);
    }

    public function procurementStoreTenders(int $actor, int $store): array
    {
        if (!$this->procurementStores($actor,$store)) {
            return [];
        }
        return $this->rows('SELECT id,subject,deadline,deadline<=UTC_TIMESTAMP() AS closed
            FROM procurement_rfqs WHERE store_id=? ORDER BY id DESC',[$store]);
    }

    public function procurementTenderInbox(int $merchant): array
    {
        $shop = $this->shopActor($merchant,'catalog');
        $invitations = $this->rows("SELECT q.id,q.subject,q.deadline,st.name AS store_name,
            COALESCE((SELECT MAX(b.revision) FROM procurement_bids b WHERE b.rfq_id=q.id AND b.shop_id=i.shop_id),0) AS revision
            FROM procurement_invites i JOIN procurement_rfqs q ON q.id=i.rfq_id JOIN procurement_stores st ON st.id=q.store_id
            JOIN procurement_suppliers s ON s.company_id=st.company_id AND s.shop_id=i.shop_id AND s.enabled=1
            JOIN shops sh ON sh.id=i.shop_id AND sh.status='active' WHERE i.shop_id=? ORDER BY q.id DESC",[$shop['shop_id']]);
        foreach ($invitations as &$row) {
            $row['lines'] = $this->rows('SELECT line_no,specification,quantity FROM procurement_rfq_lines
                WHERE rfq_id=? ORDER BY line_no',[$row['id']]);
            $row['history'] = $this->rows('SELECT id,revision,total_minor,late,submitted_at FROM procurement_bids
                WHERE rfq_id=? AND shop_id=? ORDER BY revision DESC',[$row['id'],$shop['shop_id']]);
        }
        return $invitations;
    }

    public function openProcurementTender(int $actor, int $store, string $subject, string $deadline,
        array $lines, array $shops): int
    {
        $subject = $this->procurementText($subject,120);
        $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$deadline,new DateTimeZone('UTC'));
        if (!$due || $due->format('Y-m-d H:i:s') !== $deadline || $due->getTimestamp() <= time()) {
            throw new RuleViolation('截止时间必须为未来的 UTC 日期和时间。');
        }
        if (!$lines || count($lines) > 100 || !$shops || count($shops) > 50) {
            throw new RuleViolation('请提供 1 至 100 行采购需求，选择 1 至 50 家签约商家。');
        }
        foreach ($shops as $shop) {
            if (!is_int($shop) || $shop < 1) {
                throw new RuleViolation('受邀商家编号无效。');
            }
        }
        return $this->transaction(function () use ($actor,$store,$subject,$deadline,$lines,$shops) {
            $org = $this->procurementStoreForDuty($actor,$store,'purchaser');
            if (!$org) {
                throw new RuleViolation('未找到采购门店。');
            }
            $company = $this->one('SELECT accepting_requests FROM procurement_companies WHERE id=? FOR UPDATE',[$org['company_id']]);
            if (!$company['accepting_requests']) {
                throw new RuleViolation('法人已停止接收新询价。',409);
            }
            $rfq = $this->insert('INSERT INTO procurement_rfqs (store_id,creator_id,subject,deadline) VALUES (?,?,?,?)',
                [$store,$actor,$subject,$deadline]);
            foreach (array_values($lines) as $i => $line) {
                $qty = $line['quantity'] ?? null;
                if (!is_int($qty) || $qty < 1 || $qty > 1000000 || !is_string($line['specification'] ?? null)) {
                    throw new RuleViolation('需求行必须包含规格和有效数量。');
                }
                $this->query('INSERT INTO procurement_rfq_lines (rfq_id,line_no,specification,quantity) VALUES (?,?,?,?)',
                    [$rfq,$i+1,$this->procurementText($line['specification'],160),$qty]);
            }
            foreach (array_unique($shops) as $shop) {
                $supplier = $this->one("SELECT s.shop_id FROM procurement_suppliers s JOIN shops sh ON sh.id=s.shop_id
                    WHERE s.company_id=? AND s.shop_id=? AND s.enabled=1 AND sh.status='active' FOR SHARE",
                    [$org['company_id'],$shop]);
                if (!$supplier) {
                    throw new RuleViolation('只能邀请当前已签约且营业的商家。');
                }
                $this->query('INSERT INTO procurement_invites (rfq_id,shop_id) VALUES (?,?)',[$rfq,$shop]);
            }
            $this->audit($actor,'procurement.rfq.opened',$rfq,['store'=>$store,'deadline'=>$deadline]);
            return $rfq;
        });
    }

    public function submitProcurementBid(int $actor, int $rfq, int $expectedRevision, array $products, int $freight): int
    {
        if ($freight < 0 || $freight > 100000000) {
            throw new RuleViolation('运费须为有效的整数分。');
        }
        return $this->transaction(function () use ($actor,$rfq,$expectedRevision,$products,$freight) {
            $shop = $this->lockMerchantCatalog($actor);
            $tender = $this->one("SELECT q.store_id,q.deadline,c.manager_id
                FROM procurement_invites i JOIN procurement_rfqs q ON q.id=i.rfq_id
                JOIN procurement_stores st ON st.id=q.store_id JOIN procurement_companies c ON c.id=st.company_id
                JOIN procurement_suppliers su ON su.company_id=c.id AND su.shop_id=i.shop_id AND su.enabled=1
                JOIN shops sh ON sh.id=i.shop_id AND sh.status='active'
                WHERE i.rfq_id=? AND i.shop_id=? FOR UPDATE",[$rfq,$shop]);
            if (!$tender) {
                throw new RuleViolation('未找到受邀询价单。');
            }
            $tender['submitted_at'] = $this->one('SELECT UTC_TIMESTAMP() AS stamp')['stamp'];
            $last = $this->one('SELECT revision FROM procurement_bids WHERE rfq_id=? AND shop_id=? ORDER BY revision DESC LIMIT 1',
                [$rfq,$shop]);
            if ((int) ($last['revision'] ?? 0) !== $expectedRevision) {
                throw new RuleViolation('报价基版已变化。');
            }
            $lines = $this->rows('SELECT line_no,quantity FROM procurement_rfq_lines WHERE rfq_id=? ORDER BY line_no',[$rfq]);
            if (count($products) !== count($lines)) {
                throw new RuleViolation('每一行需求都须指定供货 SKU。');
            }
            $quantities = [];
            foreach ($lines as $line) {
                $product = $products[$line['line_no']] ?? null;
                if (!is_int($product) || $product < 1) {
                    throw new RuleViolation('报价缺少有效商品编号。');
                }
                $quantities[$product] = ($quantities[$product] ?? 0) + (int) $line['quantity'];
            }
            $quotes = [];
            $total = $freight;
            foreach ($quantities as $product => $quantity) {
                if (!$this->one('SELECT id FROM products WHERE id=? AND shop_id=?',[$product,$shop])) {
                    throw new RuleViolation('不能用其他商家的商品投标。');
                }
                $quote = $this->procurementQuote((int)$tender['manager_id'],(int)$tender['store_id'],$product,
                    $quantity,substr($tender['submitted_at'],0,10));
                $quotes[$product] = $quote;
                $total += $quote['total_minor'];
            }
            $late = $tender['submitted_at'] >= $tender['deadline'];
            $bid = $this->insert('INSERT INTO procurement_bids
                (rfq_id,shop_id,revision,author_id,late,freight_minor,total_minor,submitted_at) VALUES (?,?,?,?,?,?,?,?)',
                [$rfq,$shop,$expectedRevision+1,$actor,(int)$late,$freight,$total,$tender['submitted_at']]);
            foreach ($lines as $line) {
                $quote = $quotes[$products[$line['line_no']]];
                $this->query('INSERT INTO procurement_bid_lines (bid_id,line_no,quantity,agreement_id,price_minor) VALUES (?,?,?,?,?)',
                    [$bid,$line['line_no'],$line['quantity'],$quote['agreement_id'],$quote['price_minor']]);
            }
            $this->audit($actor,'procurement.bid.submitted',$bid,['rfq'=>$rfq,'late'=>$late]);
            return $bid;
        });
    }

    public function procurementBidComparison(int $actor, int $rfq): array
    {
        $q = $this->one('SELECT store_id,deadline<=UTC_TIMESTAMP() AS closed FROM procurement_rfqs WHERE id=?',[$rfq]);
        if (!$q || !$this->procurementStoreForDuty($actor,(int)$q['store_id'],'purchaser')) {
            throw new RuleViolation('未找到该询价单。');
        }
        if (!$q['closed']) {
            throw new RuleViolation('截止前报价密封。');
        }
        $bids = $this->rows("SELECT b.*,s.name AS supplier,
            (b.late=0 AND b.revision=(SELECT MAX(b2.revision) FROM procurement_bids b2
             WHERE b2.rfq_id=b.rfq_id AND b2.shop_id=b.shop_id AND b2.late=0)) AS eligible
            FROM procurement_bids b JOIN shops s ON s.id=b.shop_id WHERE b.rfq_id=?
            ORDER BY eligible DESC,b.total_minor,b.id",[$rfq]);
        foreach ($bids as &$bid) {
            $bid['lines'] = $this->rows('SELECT bl.line_no,rl.specification,p.sku,bl.quantity,bl.price_minor,
                a.revision AS agreement_revision FROM procurement_bid_lines bl JOIN procurement_agreements a ON a.id=bl.agreement_id
                JOIN products p ON p.id=a.product_id JOIN procurement_rfq_lines rl ON rl.rfq_id=? AND rl.line_no=bl.line_no
                WHERE bl.bid_id=? ORDER BY bl.line_no',[$rfq,$bid['id']]);
        }
        return $bids;
    }
}
