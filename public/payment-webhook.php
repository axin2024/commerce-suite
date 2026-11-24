<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$secret = sandboxWebhookSecret();
if ($secret === null) {
    reply(404, ['error' => 'not_found']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    reply(405, ['error' => 'method_not_allowed']);
}
if (!preg_match('/^application\/json\s*(?:;|$)/i', $_SERVER['CONTENT_TYPE'] ?? '')) {
    reply(415, ['error' => 'expected_json']);
}
$body = file_get_contents('php://input', length: 8193);
if ($body === false || strlen($body) > 8192) {
    reply(413, ['error' => 'body_too_large']);
}
$timestamp = $_SERVER['HTTP_X_COMMERCE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_COMMERCE_SIGNATURE'] ?? '';
if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300
    || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
    reply(401, ['error' => 'invalid_signature']);
}

try {
    $payload = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    reply(400, ['error' => 'invalid_json']);
}
if (!is_array($payload) || count($payload) !== 4 || !is_string($payload['payment_token'] ?? null)
    || !is_string($payload['reference'] ?? null) || !is_string($payload['currency'] ?? null)
    || !is_int($payload['amount'] ?? null)
    || !Commerce::validSandboxFields($payload['payment_token'], $payload['reference'], $payload['currency'], $payload['amount'])) {
    reply(422, ['error' => 'invalid_receipt']);
}
try {
    $receipt = (new Commerce(database()))->recordSandboxReceipt($payload['payment_token'], $payload['reference'],
        $payload['currency'], $payload['amount'], hash('sha256', $body));
    reply(200, $receipt);
} catch (RuleViolation) {
    reply(409, ['error' => 'receipt_conflict']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in payment webhook');
    reply(503, ['error' => 'receipt_unavailable']);
}
