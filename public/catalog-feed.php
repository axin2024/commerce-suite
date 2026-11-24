<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function catalogReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    catalogReply(405, ['error' => 'method_not_allowed']);
}
if (!preg_match('/^Bearer (cf1_[a-f0-9]{64})$/iD', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $authorization)) {
    header('WWW-Authenticate: Bearer');
    catalogReply(401, ['error' => 'invalid_credential']);
}
$limit = filter_var($_GET['limit'] ?? '100', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
$cursor = $_GET['cursor'] ?? null;
if ($limit === false || ($cursor !== null && !is_string($cursor))) {
    catalogReply(400, ['error' => 'invalid_page']);
}

try {
    catalogReply(200, (new Commerce(database()))->catalogChannelFeed($authorization[1], $cursor, $limit));
} catch (InvalidCatalogCredential) {
    header('WWW-Authenticate: Bearer');
    catalogReply(401, ['error' => 'invalid_credential']);
} catch (InvalidArgumentException) {
    catalogReply(400, ['error' => 'invalid_page']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in catalog feed');
    catalogReply(503, ['error' => 'catalog_unavailable']);
}
