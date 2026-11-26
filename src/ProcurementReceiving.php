<?php

declare(strict_types=1);

trait ProcurementReceiving
{
    public function procurementWeekDeliveries(int $actor,string $monday,?int $company,?int $store): array
    {
        $start = new DateTimeImmutable($this->procurementDate($monday),new DateTimeZone('UTC'));
        $start = $start->modify('monday this week');
        $sql = 'SELECT r.id,r.order_id,r.line_no,r.received_at,r.accepted_qty,r.open_qty,st.name AS store_name,c.billing_name
            FROM procurement_receipts r JOIN procurement_orders o ON o.id=r.order_id JOIN procurement_awards a ON a.id=o.award_id
            JOIN procurement_stores st ON st.id=a.ship_to JOIN procurement_companies c ON c.id=st.company_id
            WHERE r.received_at>=? AND r.received_at<? AND (c.manager_id=? OR EXISTS
            (SELECT 1 FROM procurement_members m WHERE m.store_id=st.id AND m.user_id=?))';
        $params = [$start->format('Y-m-d'),$start->modify('+1 week')->format('Y-m-d'),$actor,$actor];
        if ($company!==null) {
            $sql .= ' AND c.id=?';
            $params[] = $company;
        }
        if ($store!==null) {
            $sql .= ' AND st.id=?';
            $params[] = $store;
        }
        return $this->rows($sql . ' ORDER BY r.received_at,r.id',$params);
    }

    public function receiveProcurementLine(int $receiver,int $order,int $line,string $deliveryRef,int $accepted,int $damaged): array
    {
        if ($line<1 || !preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$deliveryRef)) {
            throw new RuleViolation('请填写采购行号与有效送货单号。');
        }
        if ($accepted<0 || $damaged<0 || $accepted>1000000 || $damaged>1000000 || $accepted+$damaged===0) {
            throw new RuleViolation('请填写本批验收数量。');
        }
        return $this->transaction(function () use ($receiver,$order,$line,$deliveryRef,$accepted,$damaged) {
            $row = $this->one('SELECT a.ship_to,a.company_id,b.freight_minor,l.approved_qty,l.price_minor FROM procurement_orders o
                JOIN procurement_awards a ON a.id=o.award_id JOIN procurement_award_lines l ON l.award_id=a.id
                JOIN procurement_bids b ON b.id=a.bid_id
                JOIN procurement_companies c ON c.id=a.company_id
                WHERE o.id=? AND l.line_no=? AND l.approved_qty>0 FOR UPDATE',[$order,$line]);
            if (!$row || !$this->procurementStoreForDuty($receiver,(int)$row['ship_to'],'receiver')) {
                throw new RuleViolation('未找到有收货权限的采购行。',404);
            }
            $existing = $this->one('SELECT * FROM procurement_receipts WHERE order_id=? AND line_no=? AND delivery_ref=?',
                [$order,$line,$deliveryRef]);
            if ($existing) {
                if ((int)$existing['accepted_qty']!==$accepted || (int)$existing['damaged_qty']!==$damaged) {
                    throw new RuleViolation('相同送货单行的数量与原回执不符。',409);
                }
                return $existing;
            }
            $previous = $this->one('SELECT COALESCE(SUM(accepted_qty),0) AS received
                FROM procurement_receipts WHERE order_id=? AND line_no=?',[$order,$line]);
            $pending = (int)$row['approved_qty']-(int)$previous['received'];
            if ($accepted+$damaged>$pending) {
                throw new RuleViolation("收货超出剩余 {$pending} 件。",409);
            }
            $open = $pending-$accepted;
            $receipt = $this->insert('INSERT INTO procurement_receipts
                (order_id,line_no,receiver_id,delivery_ref,received_at,accepted_qty,damaged_qty,open_qty,amount_minor)
                VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?,?)',[$order,$line,$receiver,$deliveryRef,$accepted,$damaged,$open,$accepted*(int)$row['price_minor']]);
            $this->billProcurementReceipt($receiver,(int)$row['company_id'],$receipt,$order,$accepted,$accepted*(int)$row['price_minor'],(int)$row['freight_minor']);
            $this->audit($receiver,'procurement.received',$receipt,['order'=>$order,'line'=>$line,'open_qty'=>$open]);
            return $this->one('SELECT * FROM procurement_receipts WHERE id=?',[$receipt]);
        });
    }

    public function procurementReceipts(int $actor,int $order): array
    {
        $parent = $this->one('SELECT a.ship_to FROM procurement_orders o JOIN procurement_awards a ON a.id=o.award_id WHERE o.id=?',[$order]);
        if (!$parent || !$this->procurementStores($actor,(int)$parent['ship_to'])) {
            throw new RuleViolation('未找到该采购单。',404);
        }
        return $this->rows('SELECT * FROM procurement_receipts WHERE order_id=? ORDER BY id',[$order]);
    }
}
