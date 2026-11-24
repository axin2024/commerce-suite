<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function channelOrderReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    channelOrderReply(405, ['error' => 'method_not_allowed']);
}
if (!preg_match('/^(?i:Bearer) (co1_[a-f0-9]{64})$/D', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $authorization)) {
    header('WWW-Authenticate: Bearer');
    channelOrderReply(401, ['error' => 'invalid_credential']);
}
$token = $authorization[1];

try {
    $app = new Commerce(database());
    if ($method === 'GET') {
        if (isset($_GET['return_reference'])) {
            channelOrderReply(200, $app->getChannelReturn($token, $_GET['reference'] ?? null, $_GET['return_reference']));
        }
        channelOrderReply(200, $app->getChannelOrder($token, $_GET['reference'] ?? null));
    }
    if (!preg_match('/^application\/json\s*(?:;|$)/i', $_SERVER['CONTENT_TYPE'] ?? '')) {
        channelOrderReply(415, ['error' => 'expected_json']);
    }
    $body = file_get_contents('php://input', length: 16385);
    if ($body === false || strlen($body) > 16384) {
        channelOrderReply(413, ['error' => 'body_too_large']);
    }
    $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) {
        channelOrderReply(422, ['error' => 'invalid_request']);
    }
    $action = $_GET['action'] ?? '';
    if ($action === 'quote') {
        channelOrderReply(200, $app->quoteChannelOrder($token, $request));
    }
    if ($action === 'place') {
        [$created, $order] = $app->placeChannelOrder($token, $request);
        channelOrderReply($created ? 201 : 200, $order);
    }
    if ($action === 'return_request') {
        [$created, $refund] = $app->requestChannelReturn($token, $request);
        channelOrderReply($created ? 201 : 200, $refund);
    }
    if ($action === 'return_dispatch') {
        channelOrderReply(200, $app->dispatchChannelReturn($token, $request));
    }
    if (($action === 'checkout' || $action === 'cancel') && array_keys($request) === ['reference']) {
        $result = $action === 'checkout' ? $app->checkoutChannelOrder($token, $request['reference'])
            : $app->cancelChannelOrder($token, $request['reference']);
        channelOrderReply(200, $result);
    }
    channelOrderReply(422, ['error' => 'invalid_request']);
} catch (ChannelOrderError $error) {
    if ($error->status === 401) {
        header('WWW-Authenticate: Bearer');
    }
    channelOrderReply($error->status, ['error' => $error->getMessage()]);
} catch (JsonException) {
    channelOrderReply(400, ['error' => 'invalid_json']);
} catch (Throwable $error) {
    error_log($error::class . ' [' . $error->getCode() . '] in channel orders');
    channelOrderReply(503, ['error' => 'order_service_unavailable']);
}
