<?php

declare(strict_types=1);

trait ChannelBillOperations
{
    public const CHANNEL_BILL_MAX_BYTES = 262144;
    private const CHANNEL_BILL_HEADER = ['kind', 'order_id', 'reference', 'currency', 'amount_minor'];

    public function importChannelBill(int $actor, string $csv): int
    {
        $this->actor($actor, 'finance', 'merchant');
        $rows = self::parseChannelBill($csv);
        $digest = hash('sha256', $csv);
        return $this->transaction(function () use ($actor, $rows, $digest): int {
            $this->one('SELECT id FROM users WHERE id=? FOR UPDATE', [$actor]);
            $shop = $this->channelBillShop($actor);
            $existing = $this->one('SELECT id FROM channel_bill_imports WHERE uploaded_by=? AND file_sha256=?', [$actor, $digest]);
            if ($existing) {
                return (int) $existing['id'];
            }
            $id = $this->insert('INSERT INTO channel_bill_imports (uploaded_by,shop_id,file_sha256,row_count) VALUES (?,?,?,?)',
                [$actor, $shop, $digest, count($rows)]);
            $save = $this->db->prepare('INSERT INTO channel_bill_rows
                (import_id,line_no,kind,order_id,reference,currency,amount,status,result_code) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $number => $row) {
                $save->execute([$id, $number, ...$row]);
            }
            $this->audit($actor, 'channel_bill.uploaded', $id, ['rows' => count($rows), 'shop_id' => $shop]);
            return $id;
        });
    }

    public function processChannelBills(): int
    {
        return $this->transaction(function (): int {
            $rows = $this->rows("SELECT r.*,i.shop_id FROM channel_bill_rows r JOIN channel_bill_imports i ON i.id=r.import_id
                WHERE r.status='queued' ORDER BY r.id LIMIT 100 FOR UPDATE OF r SKIP LOCKED");
            foreach ($rows as $row) {
                [$status, $reason, $localId] = $this->matchChannelBill($row, $row['shop_id'] === null ? null : (int) $row['shop_id']);
                $this->query('UPDATE channel_bill_rows SET status=?,result_code=?,local_id=?,checked_at=UTC_TIMESTAMP() WHERE id=?',
                    [$status, $reason, $localId, $row['id']]);
            }
            return count($rows);
        });
    }

    public function retryChannelBill(int $finance, int $import): int
    {
        $this->actor($finance, 'finance');
        return $this->transaction(function () use ($finance, $import): int {
            $this->channelBillImport($finance, $import);
            $requeued = $this->query("UPDATE channel_bill_rows SET status='queued'
                WHERE import_id=? AND status='awaiting_receipt'", [$import])->rowCount();
            if ($requeued) {
                $this->audit($finance, 'channel_bill.requeued', $import, ['rows' => $requeued]);
            }
            return $requeued;
        });
    }

    public function channelBillList(int $actor, ?int $before = null): array
    {
        $shop = $this->channelBillShop($actor);
        $conditions = [];
        $params = [];
        if ($shop !== null) {
            $conditions[] = 'i.shop_id=?';
            $params[] = $shop;
        }
        if ($before !== null) {
            $conditions[] = 'i.id<?';
            $params[] = $before;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return $this->rows('SELECT i.id,i.shop_id,i.row_count,i.created_at,s.name AS shop_name FROM channel_bill_imports i
            LEFT JOIN shops s ON s.id=i.shop_id' . $where . ' ORDER BY i.id DESC LIMIT 51', $params);
    }

    public function channelBillDetails(int $actor, int $import): array
    {
        $bill = $this->channelBillImport($actor, $import);
        $rows = $this->rows('SELECT line_no,kind,order_id,reference,currency,amount,status,result_code,local_id,checked_at
            FROM channel_bill_rows WHERE import_id=? ORDER BY line_no', [$import]);
        if ($bill['shop_id'] !== null && $this->actor($actor, 'finance', 'merchant')['role'] === 'merchant') {
            foreach ($rows as &$row) {
                if ($row['checked_at'] !== null) {
                    $row['status'] = 'reviewed';
                    $row['result_code'] = null;
                    $row['local_id'] = null;
                    $row['checked_at'] = null;
                }
            }
            unset($row);
        }
        return $bill + ['rows' => $rows];
    }

    private function channelBillShop(int $actor): ?int
    {
        $user = $this->actor($actor, 'finance', 'merchant');
        return $user['role'] === 'merchant' ? (int) $this->shopActor($actor, 'finance')['shop_id'] : null;
    }

    private function channelBillImport(int $actor, int $import): array
    {
        $shop = $this->channelBillShop($actor);
        $batch = $this->one('SELECT i.*,s.name AS shop_name FROM channel_bill_imports i LEFT JOIN shops s ON s.id=i.shop_id
            WHERE i.id=?', [$import]);
        if (!$batch || ($shop !== null && (int) $batch['shop_id'] !== $shop)) {
            throw new RuleViolation('账单任务不存在或不可访问。');
        }
        return $batch;
    }

    private static function parseChannelBill(string $data): array
    {
        if ($data === '' || strlen($data) > self::CHANNEL_BILL_MAX_BYTES || !preg_match('//u', $data) || str_contains($data, "\0")) {
            throw new RuleViolation('请上传 256 KiB 以内的 UTF-8 CSV 账单。');
        }
        $data = str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data;
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        // All bill fields are single-line identifiers or integers. Parse independently to keep later rows after a bad quote.
        $lines = explode("\n", str_ends_with($data, "\n") ? substr($data, 0, -1) : $data, 1002);
        if (self::channelBillColumns(array_shift($lines)) !== self::CHANNEL_BILL_HEADER) {
            throw new RuleViolation('CSV 表头与账单模板不一致。');
        }
        if (!$lines || count($lines) > 1000) {
            throw new RuleViolation('每份 CSV 必须包含 1 至 1000 条账单记录。');
        }
        $rows = [];
        $references = [];
        foreach ($lines as $index => $line) {
            $number = $index + 2;
            $parsed = self::channelBillFields(self::channelBillColumns($line));
            if ($parsed === null) {
                $rows[$number] = [null, null, null, null, null, 'invalid', 'invalid_fields'];
                continue;
            }
            $rows[$number] = [...$parsed, 'queued', null];
            $references[$parsed[0] . ':' . $parsed[2]][] = $number;
        }
        foreach ($references as $numbers) {
            $first = $numbers[0];
            $fields = array_slice($rows[$first], 0, 5);
            $conflict = false;
            foreach ($numbers as $number) {
                if (array_slice($rows[$number], 0, 5) !== $fields) {
                    $conflict = true;
                    break;
                }
            }
            foreach ($numbers as $number) {
                if ($conflict || $number !== $first) {
                    $rows[$number][5] = $conflict ? 'conflict' : 'duplicate';
                    $rows[$number][6] = 'repeated_reference';
                }
            }
        }
        return $rows;
    }

    private static function channelBillColumns(string $line): array
    {
        return array_map(static fn(string $value): string => strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')
            ? substr($value, 1, -1) : $value, explode(',', $line, 6));
    }

    private static function channelBillFields(array $fields): ?array
    {
        if (count($fields) !== 5) {
            return null;
        }
        [$kind, $order, $reference, $currency, $amount] = $fields;
        if (!is_string($kind) || !in_array($kind, ['capture', 'return_refund', 'exception_refund'], true)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $order)
            || !preg_match('/^[a-z0-9_-]{1,80}$/iD', (string) $reference)
            || !preg_match('/^[A-Z]{3}$/D', (string) $currency)
            || !preg_match('/^(0|[1-9][0-9]{0,12})$/D', (string) $amount)) {
            return null;
        }
        $order = filter_var($order, FILTER_VALIDATE_INT);
        $amount = (int) $amount;
        if ($order === false || $amount > 1_000_000_000_000 || ($kind !== 'return_refund' && $amount === 0)) {
            return null;
        }
        return [$kind, $order, $reference, $currency, $amount];
    }

    private function matchChannelBill(array $row, ?int $shop): array
    {
        if ($shop !== null && !$this->one('SELECT id FROM merchant_orders WHERE order_id=? AND shop_id=?', [$row['order_id'], $shop])) {
            return ['outside_shop', 'merchant_order_missing', null];
        }
        $reference = [$row['reference']];
        $receipt = match ($row['kind']) {
            'capture' => $this->one('SELECT id,order_id,amount,currency,status FROM payment_receipts WHERE reference=?', $reference),
            'exception_refund' => $this->one("SELECT id,order_id,amount,currency,status FROM payment_receipts
                WHERE refund_reference=? AND status='refunded'", $reference),
            'return_refund' => $this->one("SELECT r.id,m.order_id,m.shop_id,r.amount,'CNY' AS currency,r.status
                FROM refunds r JOIN order_items i ON i.id=r.item_id JOIN merchant_orders m ON m.id=i.merchant_order_id
                WHERE r.refund_reference=? AND r.status='refunded'", $reference),
        };
        if (!$receipt || ($shop !== null && isset($receipt['shop_id']) && (int) $receipt['shop_id'] !== $shop)) {
            return ['awaiting_receipt', 'reference_not_found', null];
        }
        $localId = (int) $receipt['id'];
        if ((int) $receipt['order_id'] !== (int) $row['order_id']) {
            return ['mismatch', 'order_mismatch', $localId];
        }
        if ($receipt['currency'] !== $row['currency'] || (int) $receipt['amount'] !== (int) $row['amount']) {
            return ['mismatch', 'amount_or_currency_mismatch', $localId];
        }
        if ($row['kind'] === 'capture' && !in_array($receipt['status'], ['accepted', 'refunded'], true)) {
            return ['receipt_exception', 'payment_receipt_exception', $localId];
        }
        return ['matched', null, $localId];
    }
}
