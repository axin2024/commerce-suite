<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo "{\"status\":\"method_not_allowed\"}\n";
    exit;
}

try {
    assertSchemaReady(database());
    CarrierAdapters::fromEnvironment();
    FreightStrategies::fromEnvironment();
    PaymentChannels::fromEnvironment();
    PromotionStrategies::fromEnvironment();
    $status = 'ready';
} catch (Throwable) {
    http_response_code(503);
    $status = 'unavailable';
}
if ($method === 'GET') {
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR), "\n";
}
