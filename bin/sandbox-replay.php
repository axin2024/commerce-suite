<?php

declare(strict_types=1);

function sendSandboxEvent(int $port, string $endpoint, string $secret, array $payload): int
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
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
    $response = @file_get_contents("http://127.0.0.1:$port/$endpoint", context: $context);
    if ($response === false) {
        fwrite(STDERR, "No response from the local sandbox. Retry with the same reference and payload.\n");
        return 1;
    }
    echo $response, PHP_EOL;
    return preg_match('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '') === 1 ? 0 : 1;
}
