<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/SandboxWebhook.php';

[, $payload] = readSandboxWebhook(sandboxRefundSecret());
if (!is_array($payload) || count($payload) !== 5 || !is_string($payload['refund_token'] ?? null)
    || !is_string($payload['reference'] ?? null) || !is_string($payload['payment_reference'] ?? null)
    || !is_string($payload['currency'] ?? null) || !is_int($payload['amount'] ?? null)
    || !Commerce::validReturnReceipt($payload['refund_token'], $payload['reference'], $payload['payment_reference'],
        $payload['currency'], $payload['amount'])) {
    webhookReply(422, ['error' => 'invalid_refund']);
}
try {
    $receipt = (new Commerce(database()))->recordReturnReceipt($payload['refund_token'], $payload['reference'],
        $payload['payment_reference'], $payload['currency'], $payload['amount']);
    webhookReply(200, $receipt);
} catch (RuleViolation) {
    webhookReply(409, ['error' => 'refund_conflict']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in refund webhook');
    webhookReply(503, ['error' => 'refund_unavailable']);
}
