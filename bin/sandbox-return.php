<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/sandbox-replay.php';

$secret = sandboxRefundSecret();
if ($secret === null) {
    fwrite(STDERR, "Set APP_DEMO=1 and COMMERCE_SANDBOX_REFUND_SECRET (at least 32 bytes).\n");
    exit(2);
}
$port = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$token = $argv[2] ?? '';
$reference = $argv[3] ?? '';
$payment = $argv[4] ?? '';
$amount = filter_var($argv[5] ?? '', FILTER_VALIDATE_INT);
$currency = $argv[6] ?? 'CNY';
if (!$port || $amount === false || !Commerce::validReturnReceipt($token, $reference, $payment, $currency, $amount)) {
    fwrite(STDERR, "Usage: php bin/sandbox-return.php <localhost port> <refund token> <reference> <payment reference> <minor amount> [currency]\n");
    exit(2);
}
exit(sendSandboxEvent($port, 'refund-webhook.php', $secret, [
    'refund_token' => $token, 'reference' => $reference, 'payment_reference' => $payment,
    'amount' => $amount, 'currency' => $currency,
]));
