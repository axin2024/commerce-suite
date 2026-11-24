<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/SandboxWebhook.php';

[, $payload] = readSandboxWebhook(sandboxShippingSecret());
if (!is_array($payload) || count($payload) !== 3 || !is_string($payload['carrier'] ?? null)
    || !is_string($payload['tracking'] ?? null) || !is_string($payload['event_reference'] ?? null)
    || !Commerce::validDeliveryEvent($payload['carrier'], $payload['tracking'], $payload['event_reference'])) {
    webhookReply(422, ['error' => 'invalid_delivery']);
}
try {
    $event = (new Commerce(database()))->recordDelivery($payload['carrier'], $payload['tracking'], $payload['event_reference']);
    webhookReply(200, $event);
} catch (RuleViolation) {
    webhookReply(409, ['error' => 'delivery_conflict']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in shipping webhook');
    webhookReply(503, ['error' => 'delivery_unavailable']);
}
