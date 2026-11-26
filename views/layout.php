<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commerce Suite · 全渠道电商</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
<header class="top">
    <nav class="wrap" aria-label="主导航">
        <a class="brand" href="/">Commerce <span>Suite</span></a>
        <div class="navlinks">
            <a href="/">商品目录</a>
            <?php if ($role === 'buyer'): ?>
                <a href="/?page=cart">购物车 <span class="counter"><?= count($cart) ?></span></a>
                <a href="/?page=orders">我的订单</a>
            <?php elseif ($role === 'guest'): ?>
                <a href="/?page=cart">购物车 <span class="counter"><?= count($cart) ?></span></a>
            <?php elseif ($role === 'merchant'): ?>
                <a href="/?page=merchant">商家中心</a>
            <?php elseif ($role === 'admin' || $role === 'finance'): ?>
                <a href="/?page=platform">平台运营</a>
                <a href="/?page=settlements">结算中心</a>
                <?php if ($role === 'finance'): ?><a href="/?page=channel_bills">渠道对账</a><?php endif; ?>
            <?php elseif ($role === 'payout'): ?>
                <a href="/?page=settlements">打款登记</a>
            <?php endif; ?>
            <?php if (in_array($role, ['buyer', 'admin','finance'], true) || ($role === 'merchant' && Commerce::canManage($user, 'catalog'))): ?><a href="/?page=procurement">企业采购</a><?php endif; ?>
            <a href="/?page=account"><?= $user ? '账号' : '登录 / 注册' ?></a>
        </div>
    </nav>
</header>
<main class="wrap">
    <?php if (isset($_SESSION['notice'])): ?>
        <p class="flash" role="status"><?= e($_SESSION['notice']) ?></p>
        <?php unset($_SESSION['notice']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['failure'])): ?>
        <p class="flash error" role="alert"><?= e($_SESSION['failure']) ?></p>
        <?php unset($_SESSION['failure']); ?>
    <?php endif; ?>
    <?php require __DIR__ . '/' . $page . '.php'; ?>
</main>
<footer class="wrap footer">Commerce Suite <span>PHP · MySQL · Apache-2.0</span></footer>
</body>
</html>
