<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ini_set('session.use_strict_mode', '1');
session_name('commerce_suite');
session_set_cookie_params([
    'httponly' => true, 'samesite' => 'Lax',
    'secure' => getenv('COMMERCE_SECURE_COOKIES') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value) || !preg_match('//u', $value)) {
        throw new RuleViolation('提交的表单格式无效。');
    }
    return $value;
}

function number(string $key, int $min = 1, int $max = PHP_INT_MAX): int
{
    $value = filter_var(input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($value === false) {
        throw new RuleViolation('请检查填写的数字。');
    }
    return $value;
}

function cents(): int
{
    if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D', trim(input('price')), $matches)) {
        throw new RuleViolation('价格最多保留两位小数。');
    }
    return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
}

function redirect(string $page, array $params = []): never
{
    header('Location: /?' . http_build_query(['page' => $page] + $params), true, 303);
    exit;
}

function loginAs(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['user' => (int) $user['id'], 'csrf' => bin2hex(random_bytes(32)),
        'access_version' => $user['access_version'] ?? null];
}

function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cash(string|int|null $cents): string
{
    $num = (int) $cents;
    return ($num < 0 ? '−' : '') . '¥' . number_format(abs($num) / 100, 2, '.', ',');
}

function field(string $name, string|int $value): string
{
    return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
}

function form(string $action): void
{
    echo '<form method="post" action="/">', field('csrf', $_SESSION['csrf']), field('action', $action);
}

function badge(string $status): string
{
    $labels = [
        'pending' => '待审核', 'active' => '已启用', 'rejected' => '已拒绝', 'suspended' => '暂停营业',
        'draft' => '草稿', 'archived' => '已归档', 'unpaid' => '待付款',
        'paid' => '已付款', 'ready' => '待履约', 'fulfilled' => '已履约',
        'cancelled' => '已取消', 'approved' => '已审批',
    ];
    return '<span class="badge ' . e($status) . '">' . e($labels[$status] ?? $status) . '</span>';
}

try {
    $app = new Commerce(database());
    $id = (int) ($_SESSION['user'] ?? 0);
    $user = $id ? $app->profile($id) : null;
    if (($user['role'] ?? null) === 'merchant'
        && (!$user['enabled'] || $user['access_version'] !== ($_SESSION['access_version'] ?? null))) {
        session_regenerate_id(true);
        $message = $user['enabled'] ? '店铺授权已变更，请重新登录。' : '该店铺账号已停用。';
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'failure' => $message];
        redirect('account');
    }
    $role = $user['role'] ?? 'guest';
    $cart = $_SESSION['cart'] ?? [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $next = match ($role) {
            'merchant' => 'merchant',
            'admin', 'finance' => 'platform',
            'buyer' => 'orders',
            default => 'account',
        };
        try {
            if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
                throw new RuleViolation('表单已失效，请刷新后重试。');
            }
            $action = input('action');
            switch ($action) {
                case 'login':
                    if ($id) {
                        throw new RuleViolation('当前已有登录账号。');
                    }
                    $attempts = array_values(array_filter($_SESSION['login_attempts'] ?? [],
                        static fn(int $time): bool => $time > time() - 60));
                    if (count($attempts) >= 8) {
                        throw new RuleViolation('尝试次数过多，请稍后再试。');
                    }
                    $attempts[] = time();
                    $_SESSION['login_attempts'] = $attempts;
                    $password = input('password');
                    $login = strlen($password) <= 72 ? $app->authenticate(input('email'), $password) : null;
                    if (!$login) {
                        throw new RuleViolation('邮箱或密码有误。');
                    }
                    loginAs($app->profile($login));
                    redirect('home');
                case 'register':
                    if ($id) {
                        throw new RuleViolation('请退出当前账号后再注册。');
                    }
                    $account = $app->register(input('email'), input('password'), input('shop'));
                    loginAs($app->profile($account));
                    redirect('home');
                case 'logout':
                    $_SESSION = [];
                    session_regenerate_id(true);
                    redirect('home');
                case 'cart':
                    $next = 'cart';
                    if ($role !== 'buyer') {
                        $next = 'account';
                        throw new RuleViolation('请先使用买家账号登录。');
                    }
                    $productId = number('product');
                    $qty = input('remove') === '1' ? 0 : number('quantity', 0, 99);
                    if ($qty > 0) {
                        if (!array_key_exists($productId, $cart) && count($cart) >= 30) {
                            throw new RuleViolation('购物车最多容纳 30 种 SKU。');
                        }
                        $cart[$productId] = $qty;
                    } else {
                        unset($cart[$productId]);
                    }
                    $_SESSION['cart'] = $cart;
                    unset($_SESSION['quote']);
                    break;
                case 'quote':
                    $next = 'cart';
                    $_SESSION['quote'] = $app->quote($id, $cart, input('channel'), input('destination'));
                    break;
                case 'place':
                    $next = 'cart';
                    $order = $app->place($id, input('token'));
                    if (($_SESSION['quote']['token'] ?? null) === input('token')) {
                        unset($_SESSION['cart'], $_SESSION['quote']);
                    }
                    $_SESSION['notice'] = "订单 #$order 已创建，请在 15 分钟内完成支付。";
                    redirect('orders');
                case 'pay':
                    $order = number('order');
                    $app->demoPay($id, $order, 'demo-order-' . $order, number('amount'));
                    break;
                case 'cancel':
                    if (!$app->cancel(number('order'), $id)) {
                        throw new RuleViolation('订单当前不可取消。');
                    }
                    break;
                case 'review':
                    $decision = input('decision');
                    if (!in_array($decision, ['approve', 'reject'], true)) {
                        throw new RuleViolation('店铺审核操作无效。');
                    }
                    $app->reviewShop($id, number('shop'), $decision === 'approve');
                    break;
                case 'shop_agreement':
                    $app->submitShopAgreement($id, input('payee'));
                    break;
                case 'shop_status':
                    $decision = input('decision');
                    if (!in_array($decision, ['suspend', 'resume'], true)) {
                        throw new RuleViolation('经营状态操作无效。');
                    }
                    $app->setShopSuspended($id, number('shop'), $decision === 'suspend', input('reason'));
                    break;
                case 'platform_shop':
                    $app->createPlatformShop($id, input('email'), input('password'), input('shop'), input('payee'));
                    break;
                case 'shop_member':
                    $app->addShopMember($id, input('email'), input('password'), input('position'));
                    break;
                case 'shop_member_access':
                    $enabled = input('enabled');
                    if (!in_array($enabled, ['0', '1'], true)) {
                        throw new RuleViolation('账号状态操作无效。');
                    }
                    $app->setShopMemberEnabled($id, number('member'), $enabled === '1');
                    break;
                case 'product':
                    $app->saveProduct($id, number('id', 0), input('sku'), input('name'), cents(),
                        number('stock', 0, 1_000_000_000), input('status'), number('version', 0));
                    break;
                case 'fulfill':
                    $app->fulfill($id, number('merchant_order'), input('tracking'));
                    break;
                case 'refund':
                    $app->requestRefund($id, number('item'), number('quantity', 1, 99), input('reason'), input('key'));
                    break;
                case 'decide':
                    $app->decideRefund($id, number('refund'), input('decision') === 'approve');
                    break;
                case 'settle':
                    $app->settle($id, number('shop'));
                    break;
                default:
                    throw new RuleViolation('未知操作。');
            }
            $_SESSION['notice'] = '操作已保存。';
        } catch (RuleViolation $error) {
            $_SESSION['failure'] = $error->getMessage();
        }
        redirect($next);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD, POST');
        exit;
    }
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
    $scope = [
        'home' => ['guest', 'buyer', 'merchant', 'admin', 'finance'],
        'account' => ['guest', 'buyer', 'merchant', 'admin', 'finance'],
        'cart' => ['buyer'], 'orders' => ['buyer'], 'merchant' => ['merchant'], 'platform' => ['admin', 'finance'],
    ];
    if (!isset($scope[$page])) {
        http_response_code(404);
        $page = 'home';
    } elseif (!in_array($role, $scope[$page], true)) {
        http_response_code(403);
        $page = 'account';
    }
    ob_start();
    require __DIR__ . '/../views/layout.php';
    ob_end_flush();
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log($error::class . ' [' . $error->getCode() . '] at ' . basename($error->getFile()) . ':' . $error->getLine());
    http_response_code(500);
    echo '<h1>暂时无法处理请求</h1><p>请检查服务日志及数据库配置。</p>';
}
