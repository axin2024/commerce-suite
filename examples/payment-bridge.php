<?php

declare(strict_types=1);

$checkoutUrl = getenv('COMMERCE_BRIDGE_CHECKOUT_URL');
$checkoutSecret = getenv('COMMERCE_BRIDGE_CHECKOUT_SECRET');
$webhookSecret = getenv('COMMERCE_BRIDGE_WEBHOOK_SECRET');
if (!is_string($checkoutUrl) || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)
    || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
    || parse_url($checkoutUrl, PHP_URL_QUERY) !== null || parse_url($checkoutUrl, PHP_URL_FRAGMENT) !== null
    || parse_url($checkoutUrl, PHP_URL_USER) !== null
    || !is_string($checkoutSecret) || strlen($checkoutSecret) < 32
    || !is_string($webhookSecret) || strlen($webhookSecret) < 32 || hash_equals($checkoutSecret, $webhookSecret)) {
    throw new RuntimeException('Configure a bridge checkout URL and two independent signing secrets.');
}

return ['bridge_v1' => [
    'checkout' => static function (array $intent) use ($checkoutUrl, $checkoutSecret): string {
        $query = http_build_query($intent, '', '&', PHP_QUERY_RFC3986);
        return $checkoutUrl . '?' . $query . '&signature=' . hash_hmac('sha256', $query, $checkoutSecret);
    },
    'verify' => static function (string $body, array $headers) use ($webhookSecret): ?array {
        $timestamp = $headers['x-bridge-timestamp'] ?? '';
        $signature = $headers['x-bridge-signature'] ?? '';
        if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300
            || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $webhookSecret), $signature)) {
            throw new InvalidPaymentSignature();
        }
        $event = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($event) || array_is_list($event)) {
            throw new UnexpectedValueException('Expected a payment event object.');
        }
        return $event;
    },
]];
