<?php

declare(strict_types=1);

trait ProcurementBilling
{
    public function procurementBankBalances(int $finance,int $company): array
    {
        $this->actor($finance,'finance');
        return $this->rows('SELECT b.*,b.amount_minor-COALESCE((SELECT SUM(a.amount_minor) FROM procurement_allocations a
            WHERE a.bank_id=b.id),0) AS unapplied_minor FROM procurement_bank_receipts b WHERE b.company_id=? ORDER BY b.id',[$company]);
    }

    public function procurementInvoiceLines(int $finance,int $company): array
    {
        $this->actor($finance,'finance');
        return $this->rows('SELECT l.id,l.statement_id,l.period,l.kind,l.quantity,l.amount_minor,l.source_ref,r.id AS receipt_id,
            r.order_id,r.line_no,o.award_id,DATEDIFF(UTC_DATE(),r.received_at) AS age_days,
            COALESCE((SELECT SUM(a.amount_minor) FROM procurement_allocations a WHERE a.line_id=l.id),0) AS applied_minor
            FROM procurement_statement_lines l JOIN procurement_receipts r ON r.id=l.receipt_id
            JOIN procurement_orders o ON o.id=r.order_id WHERE l.company_id=? ORDER BY l.period,l.id',[$company]);
    }

    public function procurementAging(int $finance,int $company): array
    {
        $this->actor($finance,'finance');
        return $this->rows('SELECT r.order_id,SUM(l.amount_minor-COALESCE(p.paid,0)) AS outstanding_minor,
            MIN(CASE WHEN l.amount_minor>COALESCE(p.paid,0) THEN r.received_at END) AS oldest_receipt,
            DATEDIFF(UTC_DATE(),MIN(CASE WHEN l.amount_minor>COALESCE(p.paid,0) THEN r.received_at END)) AS age_days
            FROM procurement_statement_lines l JOIN procurement_receipts r ON r.id=l.receipt_id
            LEFT JOIN (SELECT line_id,SUM(amount_minor) AS paid FROM procurement_allocations GROUP BY line_id) p ON p.line_id=l.id
            WHERE l.company_id=? AND l.statement_id IS NOT NULL GROUP BY r.order_id HAVING outstanding_minor<>0 ORDER BY oldest_receipt,r.order_id',[$company]);
    }

    public function procurementBillingExport(int $actor,string $kind,int $id): array
    {
        $profile = $this->actor($actor,'finance','buyer');
        if ($kind==='receipt') {
            $rows = $this->rows('SELECT r.id AS receipt_id,o.award_id,r.order_id,r.line_no,a.ship_to,r.delivery_ref,r.received_at,
                r.accepted_qty,r.damaged_qty,r.open_qty,r.amount_minor FROM procurement_receipts r
                JOIN procurement_orders o ON o.id=r.order_id JOIN procurement_awards a ON a.id=o.award_id WHERE r.id=?',[$id]);
            if (!$rows || ($profile['role']==='buyer' && !$this->procurementStores($actor,(int)$rows[0]['ship_to']))) {
                throw new RuleViolation('未找到可下载的收货单。',404);
            }
            return $rows;
        }
        if ($kind==='statement') {
            $statement = $this->one('SELECT s.id,c.manager_id FROM procurement_statements s
                JOIN procurement_companies c ON c.id=s.company_id WHERE s.id=?',[$id]);
            if (!$statement || ($profile['role']==='buyer' && (int)$statement['manager_id']!==$actor)) {
                throw new RuleViolation('未找到可下载的法人账单。',404);
            }
            $filter = 'l.statement_id=?';
        } elseif ($kind==='credit') {
            $filter = "l.id=? AND l.kind='return'";
        } else {
            throw new RuleViolation('未知下载类型。',404);
        }
        $rows = $this->rows("SELECT l.statement_id,l.id AS billing_line_id,l.period,l.kind,l.receipt_id,r.order_id,r.line_no,
            o.award_id,a.ship_to,l.quantity,l.amount_minor,l.posted_at FROM procurement_statement_lines l
            JOIN procurement_receipts r ON r.id=l.receipt_id JOIN procurement_orders o ON o.id=r.order_id
            JOIN procurement_awards a ON a.id=o.award_id WHERE $filter ORDER BY l.id",[$id]);
        if ($kind==='credit' && (!$rows || ($profile['role']==='buyer' && !$this->procurementStores($actor,(int)$rows[0]['ship_to'])))) {
            throw new RuleViolation('未找到可下载的贷项行。',404);
        }
        return $rows;
    }

    private function procurementBillingClock(int $company): DateTimeImmutable
    {
        $row = $this->one("SELECT UTC_TIMESTAMP() AS utc, COALESCE(s.time_zone,'UTC') AS zone
            FROM procurement_companies c LEFT JOIN procurement_billing_settings s ON s.company_id=c.id
            WHERE c.id=? FOR UPDATE",[$company]);
        if (!$row) {
            throw new RuleViolation('未找到结算法人。',404);
        }
        return (new DateTimeImmutable($row['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($row['zone']));
    }

    private function procurementOpenPeriod(int $company): string
    {
        $current = $this->procurementBillingClock($company)->format('Y-m-01');
        $last = $this->one('SELECT DATE_ADD(MAX(period),INTERVAL 1 MONTH) AS next_period FROM procurement_statements WHERE company_id=?',[$company]);
        return max($current,$last['next_period'] ?? $current);
    }

    private function billProcurementReceipt(int $receiver,int $company,int $receipt,int $order,int $accepted,int $amount,int $freight): void
    {
        if (!$accepted) {
            return;
        }
        $period = $this->procurementOpenPeriod($company);
        $this->query("INSERT INTO procurement_statement_lines (company_id,receipt_id,period,kind,source_ref,quantity,amount_minor,recorded_by)
            VALUES (?,?,?,'goods','delivery',?,?,?)",[$company,$receipt,$period,$accepted,$amount,$receiver]);
        if ($freight && !$this->one("SELECT l.id FROM procurement_statement_lines l JOIN procurement_receipts r ON r.id=l.receipt_id
            WHERE r.order_id=? AND l.kind='freight' LIMIT 1",[$order])) {
            $this->query("INSERT INTO procurement_statement_lines (company_id,receipt_id,period,kind,source_ref,quantity,amount_minor,recorded_by)
                VALUES (?,?,?,'freight','shipping',0,?,?)",[$company,$receipt,$period,$freight,$receiver]);
        }
    }

    public function closeProcurementPeriod(int $finance,int $company,string $month): int
    {
        $this->actor($finance,'finance');
        $period = $this->procurementDate($month . '-01');
        return $this->transaction(function () use ($finance,$company,$period) {
            $today = $this->procurementBillingClock($company);
            $old = $this->one('SELECT id FROM procurement_statements WHERE company_id=? AND period=?',[$company,$period]);
            if ($old) {
                return (int)$old['id'];
            }
            $previous = $this->one('SELECT MAX(period) AS period FROM procurement_statements WHERE company_id=?',[$company])['period'];
            if ($period > $today->format('Y-m-01') || ($previous && $period<=$previous)
                || $this->one('SELECT id FROM procurement_statement_lines WHERE company_id=? AND statement_id IS NULL AND period<? LIMIT 1',[$company,$period])) {
                throw new RuleViolation('请按顺序关闭已到期或本月账期。');
            }
            $statement = $this->insert('INSERT INTO procurement_statements (company_id,period,closed_by) VALUES (?,?,?)',[$company,$period,$finance]);
            $this->query('UPDATE procurement_statement_lines SET statement_id=? WHERE company_id=? AND period=? AND statement_id IS NULL',
                [$statement,$company,$period]);
            $this->audit($finance,'procurement.period.closed',$statement,['company'=>$company,'period'=>$period]);
            return $statement;
        });
    }

    private function releaseProcurementCredit(int $company,int $order,string $source,int $amount): void
    {
        $outstanding = (int)$this->one('SELECT COALESCE(SUM(amount_minor),0) AS balance FROM procurement_credit_entries WHERE order_id=?',[$order])['balance'];
        $released = min($amount,$outstanding);
        if ($released>0) {
            $this->query('INSERT INTO procurement_credit_entries (company_id,order_id,source_key,amount_minor) VALUES (?,?,?,?)',
                [$company,$order,$source,-$released]);
        }
    }

    public function returnProcurementReceipt(int $actor,int $receipt,string $reference,int $qty): int
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$reference) || $qty<1 || $qty>1000000) {
            throw new RuleViolation('退货单号或退货数量无效。');
        }
        return $this->transaction(function () use ($actor,$receipt,$reference,$qty) {
            $r = $this->one('SELECT r.*,a.company_id,a.ship_to,l.price_minor FROM procurement_receipts r
                JOIN procurement_orders o ON o.id=r.order_id JOIN procurement_awards a ON a.id=o.award_id
                JOIN procurement_award_lines l ON l.award_id=a.id AND l.line_no=r.line_no
                JOIN procurement_companies c ON c.id=a.company_id WHERE r.id=? FOR UPDATE',[$receipt]);
            if (!$r || !$this->procurementStoreForDuty($actor,(int)$r['ship_to'],'receiver')) {
                throw new RuleViolation('未找到可退货的收货行。',404);
            }
            $previous = $this->one("SELECT id,quantity FROM procurement_statement_lines WHERE receipt_id=? AND kind='return' AND source_ref=?",[$receipt,$reference]);
            if ($previous) {
                if ((int)$previous['quantity']!==$qty) {
                    throw new RuleViolation('同一退货单号的数量不一致。',409);
                }
                return (int)$previous['id'];
            }
            $used = (int)$this->one("SELECT COALESCE(SUM(quantity),0) AS n FROM procurement_statement_lines WHERE receipt_id=? AND kind='return'",[$receipt])['n'];
            if ($qty+$used>(int)$r['accepted_qty']) {
                throw new RuleViolation('退货数量超过原批次实收数量。');
            }
            $company = (int)$r['company_id'];
            $amount = $qty*(int)$r['price_minor'];
            $line = $this->insert("INSERT INTO procurement_statement_lines (company_id,receipt_id,period,kind,source_ref,quantity,amount_minor,recorded_by)
                VALUES (?,?,?,'return',?,?,?,?)",[$company,$receipt,$this->procurementOpenPeriod($company),$reference,$qty,-$amount,$actor]);
            $this->releaseProcurementCredit($company,(int)$r['order_id'],'return-'.$line,$amount);
            $this->audit($actor,'procurement.goods.returned',$line,['receipt'=>$receipt,'quantity'=>$qty]);
            return $line;
        });
    }

    public function importProcurementBankCsv(int $finance,int $company,string $csv): array
    {
        $this->actor($finance,'finance');
        if (strlen($csv)>262144) {
            throw new RuleViolation('单次最多导入 256 KiB 流水。');
        }
        $file = new SplTempFileObject();
        $file->fwrite($csv);
        $file->rewind();
        if ($file->fgetcsv(',', '"', '')!==['bank_ref','value_date','amount_minor']) {
            throw new RuleViolation('表头须为 bank_ref,value_date,amount_minor。');
        }
        return $this->transaction(function () use ($finance,$company,$file) {
            $this->procurementBillingClock($company);
            $file->rewind();
            $file->fgetcsv(',', '"', '');
            $result = [];
            while (!$file->eof()) {
                $fields = $file->fgetcsv(',', '"', '');
                if (!$fields || $fields===[null]) {
                    continue;
                }
                if (count($fields)!==3 || !preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$fields[0])
                    || !preg_match('/^[1-9][0-9]{0,14}$/D',$fields[2])) {
                    throw new RuleViolation('流水格式无效，请核对行号 ' . $file->key() . '。');
                }
                [$ref,$date,$amount] = $fields;
                $this->procurementDate($date);
                $prior = $this->one('SELECT * FROM procurement_bank_receipts WHERE company_id=? AND bank_ref=?',[$company,$ref]);
                if ($prior && ($prior['value_date']!==$date || (int)$prior['amount_minor']!==(int)$amount)) {
                    throw new RuleViolation("流水号 $ref 已存在，日期或金额不一致。",409);
                }
                if (!$prior) {
                    $id = $this->insert('INSERT INTO procurement_bank_receipts (company_id,bank_ref,value_date,amount_minor,imported_by)
                        VALUES (?,?,?,?,?)',[$company,$ref,$date,$amount,$finance]);
                    $this->audit($finance,'procurement.bank.imported',$id,['company'=>$company,'amount_minor'=>(int)$amount]);
                }
                $result[$ref] = (int)($prior['id'] ?? $id);
            }
            if (!$result) {
                throw new RuleViolation('流水文件为空。');
            }
            return $result;
        });
    }

    public function allocateProcurementPayment(int $finance,int $company,int $bank,int $line,int $amount,string $key): int
    {
        $this->actor($finance,'finance');
        if ($amount<=0 || !preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$key)) {
            throw new RuleViolation('请核对核销金额及请求号。');
        }
        return $this->transaction(function () use ($finance,$company,$bank,$line,$amount,$key) {
            $this->procurementBillingClock($company);
            $payment = $this->one('SELECT amount_minor FROM procurement_bank_receipts WHERE id=? AND company_id=?',[$bank,$company]);
            $item = $this->one('SELECT l.amount_minor,r.order_id FROM procurement_statement_lines l JOIN procurement_receipts r ON r.id=l.receipt_id
                WHERE l.id=? AND l.company_id=? AND l.statement_id IS NOT NULL AND l.amount_minor>0',[$line,$company]);
            if (!$payment || !$item) {
                throw new RuleViolation('未找到该法人的到账流水或已关账应收行。',404);
            }
            $old = $this->one('SELECT * FROM procurement_allocations WHERE bank_id=? AND request_key=?',[$bank,$key]);
            if ($old) {
                if ((int)$old['line_id']!==$line || (int)$old['amount_minor']!==$amount) {
                    throw new RuleViolation('核销请求号对应其他明细。',409);
                }
                return (int)$old['id'];
            }
            $applied = (int)$this->one('SELECT COALESCE(SUM(amount_minor),0) AS n FROM procurement_allocations WHERE bank_id=?',[$bank])['n'];
            $paid = (int)$this->one('SELECT COALESCE(SUM(amount_minor),0) AS n FROM procurement_allocations WHERE line_id=?',[$line])['n'];
            $orderBalance = (int)$this->one('SELECT COALESCE(SUM(l.amount_minor),0) AS n FROM procurement_statement_lines l
                JOIN procurement_receipts r ON r.id=l.receipt_id WHERE r.order_id=? AND (l.statement_id IS NOT NULL OR l.amount_minor<0)',[$item['order_id']])['n']
                - (int)$this->one('SELECT COALESCE(SUM(a.amount_minor),0) AS n FROM procurement_allocations a
                JOIN procurement_statement_lines l ON l.id=a.line_id JOIN procurement_receipts r ON r.id=l.receipt_id WHERE r.order_id=?',[$item['order_id']])['n'];
            if ($amount>min((int)$payment['amount_minor']-$applied,(int)$item['amount_minor']-$paid,$orderBalance)) {
                throw new RuleViolation('核销超过可用来款或订单应收余额。',409);
            }
            $id = $this->insert('INSERT INTO procurement_allocations (bank_id,line_id,request_key,amount_minor,allocated_by)
                VALUES (?,?,?,?,?)',[$bank,$line,$key,$amount,$finance]);
            $this->releaseProcurementCredit($company,(int)$item['order_id'],'allocation-'.$id,$amount);
            $this->audit($finance,'procurement.payment.allocated',$id,['bank'=>$bank,'line'=>$line,'amount_minor'=>$amount]);
            return $id;
        });
    }
}
