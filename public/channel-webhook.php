<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/SandboxWebhook.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $channels = PaymentChannels::fromEnvironment();
    $provider = $_GET['provider'] ?? null;
    if (!is_string($provider) || !$channels->supports($provider)) {
        webhookReply(404, ['error' => 'not_found']);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        webhookReply(405, ['error' => 'method_not_allowed']);
    }
    $body = file_get_contents('php://input', length: 65537);
    if ($body === false || strlen($body) > 65536) {
        webhookReply(413, ['error' => 'body_too_large']);
    }
    $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
    unset($headers['cookie']);
    $app = new Commerce(database(), paymentChannels: $channels);
    webhookReply(200, $app->receivePaymentChannelEvent($provider, $body, $headers));
} catch (InvalidPaymentSignature) {
    webhookReply(401, ['error' => 'invalid_signature']);
} catch (UnexpectedValueException | JsonException) {
    webhookReply(422, ['error' => 'invalid_event']);
} catch (RuleViolation) {
    webhookReply(409, ['error' => 'receipt_conflict']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in channel webhook');
    webhookReply(503, ['error' => 'receipt_unavailable']);
}
