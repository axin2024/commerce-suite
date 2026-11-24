<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

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
$body = json_encode(['payment_token' => $token, 'reference' => $reference, 'amount' => $amount,
    'currency' => $currency], JSON_THROW_ON_ERROR);
$time = time();
$signature = hash_hmac('sha256', $time . '.' . $body, $secret);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => ['Content-Type: application/json', 'X-Commerce-Timestamp: ' . $time, 'X-Commerce-Signature: ' . $signature],
    'content' => $body,
    'timeout' => 5,
    'follow_location' => 0,
    'ignore_errors' => true,
]]);
$response = @file_get_contents("http://127.0.0.1:$port/payment-webhook.php", context: $context);
if ($response === false) {
    fwrite(STDERR, "No response from the local sandbox. Retry with the same reference and payload.\n");
    exit(1);
}
echo $response, PHP_EOL;
exit(preg_match('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '') === 1 ? 0 : 1);
