<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/sandbox-replay.php';

$secret = sandboxWebhookSecret();
if ($secret === null) {
    fwrite(STDERR, "Set APP_DEMO=1 and COMMERCE_SANDBOX_WEBHOOK_SECRET (at least 32 bytes).\n");
    exit(2);
}
$port = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$token = $argv[2] ?? '';
$reference = $argv[3] ?? '';
$amount = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT);
$currency = $argv[5] ?? 'CNY';
if (!$port || $amount === false || !Commerce::validSandboxFields($token, $reference, $currency, $amount)) {
    fwrite(STDERR, "Usage: php bin/sandbox-payment.php <localhost port> <payment token> <reference> <minor amount> [currency]\n");
    exit(2);
}
exit(sendSandboxEvent($port, 'payment-webhook.php', $secret, [
    'payment_token' => $token, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency,
]));
