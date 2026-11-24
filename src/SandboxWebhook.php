<?php

declare(strict_types=1);

function webhookReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function readSandboxWebhook(?string $secret): array
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($secret === null) {
        webhookReply(404, ['error' => 'not_found']);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        webhookReply(405, ['error' => 'method_not_allowed']);
    }
    if (!preg_match('/^application\/json\s*(?:;|$)/i', $_SERVER['CONTENT_TYPE'] ?? '')) {
        webhookReply(415, ['error' => 'expected_json']);
    }
    $body = file_get_contents('php://input', length: 8193);
    if ($body === false || strlen($body) > 8192) {
        webhookReply(413, ['error' => 'body_too_large']);
    }
    $timestamp = $_SERVER['HTTP_X_COMMERCE_TIMESTAMP'] ?? '';
    $signature = $_SERVER['HTTP_X_COMMERCE_SIGNATURE'] ?? '';
    if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300
        || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
        webhookReply(401, ['error' => 'invalid_signature']);
    }
    try {
        return [$body, json_decode($body, true, 4, JSON_THROW_ON_ERROR)];
    } catch (JsonException) {
        webhookReply(400, ['error' => 'invalid_json']);
    }
}
