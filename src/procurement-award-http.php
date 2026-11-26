<?php

declare(strict_types=1);

function procurementAwardHttp(Commerce $app, int $actor, string $role): never
{
    header('Content-Type: application/json; charset=UTF-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new RuleViolation('请使用 POST 提交授标。',405);
        }
        if ($role !== 'buyer' || !hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new RuleViolation('采购员登录态或 CSRF 令牌无效。',403);
        }
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new RuleViolation('请提交 application/json。',415);
        }
        $raw = file_get_contents('php://input',length:65537);
        if ($raw === false || strlen($raw)>65536) {
            throw new RuleViolation('授标请求过大。',413);
        }
        $body = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        foreach (['rfq_id','bid_revision','ship_to'] as $path) {
            if (!is_int($body[$path] ?? null) || $body[$path]<=0) {
                throw new RuleViolation('须为有效业务编号。',422);
            }
        }
        $path = 'accepted_lines';
        if (!is_array($body[$path] ?? null) || !array_is_list($body[$path])) {
            throw new RuleViolation('需要采购行数组。',422);
        }
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/D',$key)) {
            $path = 'Idempotency-Key';
            throw new RuleViolation('请求头须提供有效的幂等键。',422);
        }
        unset($path);
        $reply = $app->proposeProcurementAward($actor,$body['rfq_id'],$body['bid_revision'],$body['ship_to'],$body['accepted_lines'],$key);
        http_response_code(200);
    } catch (JsonException) {
        http_response_code(400);
        $reply = ['field'=>'body','error'=>'JSON 格式无效。'];
    } catch (RuleViolation $error) {
        http_response_code($error->getCode() ?: 422);
        $reply = ['error'=>$error->getMessage()];
        if (isset($path)) {
            $reply['field'] = $path;
        } elseif (http_response_code()===422) {
            $reply['field'] = str_contains($error->getMessage(),'bid_revision') ? 'bid_revision'
                : (str_contains($error->getMessage(),'accepted_lines') ? 'accepted_lines' : 'ship_to');
        }
    }
    echo json_encode($reply,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
