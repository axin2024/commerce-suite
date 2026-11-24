<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/SandboxWebhook.php';

[$body, $payload] = readSandboxWebhook(sandboxWebhookSecret());

if (!is_array($payload) || count($payload) !== 4 || !is_string($payload['payment_token'] ?? null)
    || !is_string($payload['reference'] ?? null) || !is_string($payload['currency'] ?? null)
    || !is_int($payload['amount'] ?? null)
    || !Commerce::validSandboxFields($payload['payment_token'], $payload['reference'], $payload['currency'], $payload['amount'])) {
    webhookReply(422, ['error' => 'invalid_receipt']);
}
try {
    $receipt = (new Commerce(database()))->recordSandboxReceipt($payload['payment_token'], $payload['reference'],
        $payload['currency'], $payload['amount'], hash('sha256', $body));
    webhookReply(200, $receipt);
} catch (RuleViolation) {
    webhookReply(409, ['error' => 'receipt_conflict']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in payment webhook');
    webhookReply(503, ['error' => 'receipt_unavailable']);
}
