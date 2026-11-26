<?php

declare(strict_types=1);

trait ProcurementRecovery
{
    public function setProcurementIntake(int $finance,int $company,bool $accept): void
    {
        $this->actor($finance,'finance');
        $this->transaction(function () use ($finance,$company,$accept) {
            $this->procurementBillingClock($company);
            $this->query('UPDATE procurement_companies SET accepting_requests=? WHERE id=?',[(int)$accept,$company]);
            $this->audit($finance,'procurement.intake.changed',$company,['enabled'=>$accept]);
        });
    }

    public function procurementPreflight(): array
    {
        $counts = [];
        foreach (['orders','merchant_orders','payments','refunds','ledger','settlements'] as $table) {
            $counts[$table] = (int)$this->one("SELECT COUNT(*) AS n FROM $table")['n'];
        }
        $report = ['retail_counts'=>$counts,'retail_ownership_conflicts'=>$this->rows('SELECT m.id FROM merchant_orders m
            LEFT JOIN orders o ON o.id=m.order_id LEFT JOIN shops s ON s.id=m.shop_id WHERE o.id IS NULL OR s.id IS NULL'),
            'pending_refunds'=>$this->rows("SELECT id,status FROM refunds WHERE status IN ('pending','return_in_transit','refund_pending') ORDER BY id")];
        $hasProcurement = $this->one("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()
            AND table_name='procurement_statements'");
        if ($hasProcurement) {
            $report['pending_approvals'] = $this->rows("SELECT company_id,id,request_key FROM procurement_awards WHERE status='pending' ORDER BY id");
            $report['open_deliveries'] = $this->rows('SELECT a.company_id,o.id AS order_id,l.line_no,
                l.approved_qty-COALESCE((SELECT SUM(r.accepted_qty) FROM procurement_receipts r WHERE r.order_id=o.id AND r.line_no=l.line_no),0) AS open_qty
                FROM procurement_orders o JOIN procurement_awards a ON a.id=o.award_id JOIN procurement_award_lines l ON l.award_id=a.id
                HAVING open_qty>0 ORDER BY a.company_id,o.id,l.line_no');
            $report['unclosed_postings'] = $this->rows('SELECT company_id,period,id FROM procurement_statement_lines WHERE statement_id IS NULL ORDER BY id');
            $report['credit_checkpoints'] = $this->rows('SELECT company_id,MAX(id) AS last_entry,SUM(amount_minor) AS credit_used_minor
                FROM procurement_credit_entries GROUP BY company_id ORDER BY company_id');
            $report['unapplied_payments'] = $this->rows('SELECT b.company_id,b.id,b.amount_minor-COALESCE((SELECT SUM(a.amount_minor)
                FROM procurement_allocations a WHERE a.bank_id=b.id),0) AS unapplied_minor FROM procurement_bank_receipts b
                HAVING unapplied_minor>0 ORDER BY b.company_id,b.id');
            $report['procurement_ownership_conflicts'] = $this->rows('SELECT a.id FROM procurement_awards a JOIN procurement_stores st ON st.id=a.ship_to
                JOIN procurement_rfqs q ON q.id=a.rfq_id JOIN procurement_stores origin ON origin.id=q.store_id
                JOIN procurement_budgets b ON b.id=a.budget_id WHERE a.company_id<>st.company_id OR a.company_id<>origin.company_id OR b.company_id<>a.company_id');
        }
        return $report;
    }
}
