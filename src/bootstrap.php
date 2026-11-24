<?php

declare(strict_types=1);

require_once __DIR__ . '/Commerce.php';

function database(): PDO
{
    $dsn = getenv('COMMERCE_DSN');
    $user = getenv('COMMERCE_DB_USER');
    if (!$dsn || !$user || !str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('Configure COMMERCE_DSN and COMMERCE_DB_USER.');
    }
    $db = new PDO($dsn, $user, getenv('COMMERCE_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function demoEnabled(): bool
{
    return getenv('APP_DEMO') === '1';
}

function sandboxWebhookSecret(): ?string
{
    $secret = getenv('COMMERCE_SANDBOX_WEBHOOK_SECRET');
    return demoEnabled() && is_string($secret) && strlen($secret) >= 32 ? $secret : null;
}
