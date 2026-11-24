<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/sandbox-replay.php';

$secret = sandboxShippingSecret();
if ($secret === null) {
    fwrite(STDERR, "Set APP_DEMO=1 and COMMERCE_SANDBOX_SHIPPING_SECRET (at least 32 bytes).\n");
    exit(2);
}
$port = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$carrier = $argv[2] ?? '';
$tracking = $argv[3] ?? '';
$event = $argv[4] ?? '';
if (!$port || !Commerce::validDeliveryEvent($carrier, $tracking, $event)) {
    fwrite(STDERR, "Usage: php bin/sandbox-shipping.php <localhost port> <carrier> <tracking number> <event reference>\n");
    exit(2);
}
exit(sendSandboxEvent($port, 'shipping-webhook.php', $secret, [
    'carrier' => $carrier, 'tracking' => $tracking, 'event_reference' => $event,
]));
