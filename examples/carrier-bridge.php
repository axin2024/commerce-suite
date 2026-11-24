<?php

declare(strict_types=1);

$secret = getenv('COMMERCE_CARRIER_WEBHOOK_SECRET');
if (!is_string($secret) || strlen($secret) < 32) {
    throw new RuntimeException('Configure a carrier bridge signing secret of at least 32 bytes.');
}

return ['parcel_v1' => static function (string $body, array $headers) use ($secret): array {
    $timestamp = $headers['x-carrier-timestamp'] ?? '';
    $signature = $headers['x-carrier-signature'] ?? '';
    if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300
        || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
        throw new InvalidCarrierSignature();
    }
    $event = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($event) || array_is_list($event)) {
        throw new UnexpectedValueException('Expected a carrier event object.');
    }
    return $event;
}];
