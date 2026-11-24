<?php

declare(strict_types=1);

trait PaymentChannelOperations
{
    public function paymentCheckoutUrl(int $buyer, int $order): string
    {
        $this->actor($buyer, 'buyer');
        $row = $this->one("SELECT payment_provider,payment_token,total,UNIX_TIMESTAMP(expires_at) AS expires_at
            FROM orders WHERE id=? AND buyer_id=? AND status='unpaid' AND expires_at>UTC_TIMESTAMP()", [$order, $buyer]);
        if (($row['payment_provider'] ?? null) === null || (int) $row['total'] < 1) {
            throw new RuleViolation('订单当前无法发起渠道支付。');
        }
        return $this->paymentChannels->checkout($row['payment_provider'], [
            'payment_token' => $row['payment_token'], 'amount' => (int) $row['total'], 'currency' => 'CNY',
            'expires_at' => (int) $row['expires_at'],
        ]);
    }

    public function receivePaymentChannelEvent(string $provider, string $body, array $headers): array
    {
        $event = $this->paymentChannels->verifiedEvent($provider, $body, $headers);
        if ($event === null) {
            return ['status' => 'ignored'];
        }
        $type = $event['type'] ?? null;
        $token = $event['token'] ?? '';
        $reference = $event['reference'] ?? null;
        $original = $event['payment_reference'] ?? '';
        $currency = $event['currency'] ?? null;
        $amount = $event['amount'] ?? null;
        if (!is_string($type) || !is_string($token) || !is_string($reference) || !is_string($original)
            || !is_string($currency) || !is_int($amount)) {
            throw new UnexpectedValueException('Invalid payment event fields.');
        }
        $digest = hash('sha256', json_encode([$type, $token, $reference, $original, $currency, $amount], JSON_THROW_ON_ERROR));
        return match ($type) {
            'capture' => self::validSandboxFields($token, $reference, $currency, $amount) && $original === ''
                ? $this->recordCapture($provider, $token, $reference, $currency, $amount, $digest)
                : throw new UnexpectedValueException('Invalid capture fields.'),
            'return_refund' => self::validReturnReceipt($token, $reference, $original, $currency, $amount)
                ? $this->confirmReturnReceipt($provider, $token, $reference, $original, $currency, $amount, $digest)
                : throw new UnexpectedValueException('Invalid return refund fields.'),
            'exception_refund' => $token === '' && self::validPaymentReference($original)
                && self::validCaptureDetails($reference, $currency, $amount)
                ? $this->confirmExceptionRefund($provider, $reference, $original, $currency, $amount, $digest)
                : throw new UnexpectedValueException('Invalid exception refund fields.'),
            default => throw new UnexpectedValueException('Unknown event type.'),
        };
    }

    private function rememberExternalRefund(string $provider, string $reference, string $digest): void
    {
        $this->query('INSERT INTO external_refund_receipts (provider,reference,event_sha256)
            VALUES (?,?,?) ON DUPLICATE KEY UPDATE event_sha256=event_sha256', [$provider, $reference, $digest]);
        $row = $this->one('SELECT event_sha256 FROM external_refund_receipts WHERE provider=? AND reference=? FOR UPDATE',
            [$provider, $reference]);
        if (!hash_equals($row['event_sha256'], $digest)) {
            throw new RuleViolation('该渠道退款流水对应的内容已存在。');
        }
    }

    private function confirmExceptionRefund(
        string $provider, string $reference, string $original, string $currency, int $amount, string $digest,
    ): array {
        return $this->transaction(function () use ($provider, $reference, $original, $currency, $amount, $digest): array {
            $this->rememberExternalRefund($provider, $reference, $digest);
            $receipt = $this->one('SELECT id,status,amount,currency,refund_reference FROM payment_receipts
                WHERE provider=? AND reference=? FOR UPDATE', [$provider, $original]);
            if (!$receipt || $receipt['currency'] !== $currency || (int) $receipt['amount'] !== $amount
                || ($receipt['status'] !== 'refund_required'
                    && ($receipt['status'] !== 'refunded' || $receipt['refund_reference'] !== $reference))) {
                throw new RuleViolation('未找到匹配的待退回实收流水。');
            }
            $id = (int) $receipt['id'];
            if ($receipt['status'] === 'refund_required') {
                $this->query("UPDATE payment_receipts SET status='refunded',refund_reference=?,resolved_at=UTC_TIMESTAMP()
                    WHERE id=?", [$reference, $id]);
                $this->audit(null, 'payment.exception_refunded', $id, ['provider' => $provider, 'reference' => $reference]);
            }
            return ['receipt_id' => $id, 'status' => 'refunded'];
        });
    }
}
