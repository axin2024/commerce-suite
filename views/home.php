<?php
$search = is_string($_GET['q'] ?? null) && strlen($_GET['q']) <= 150 ? $_GET['q'] : '';
$products = $app->catalog($search);
?>
<section class="hero">
    <div>
        <h1>全渠道商城</h1>
        <p>选购不同店铺的商品，统一下单付款。支持配送到家和门店自提。</p>
    </div>
    <aside>跨店满 ¥200 减 ¥20 <span>结算时自动应用</span></aside>
</section>
<section>
    <div class="heading">
        <h2>商品目录</h2>
        <form class="search" method="get" action="/">
            <input aria-label="搜索商品名称或 SKU" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="商品名称或 SKU">
            <button class="secondary">搜索</button>
        </form>
    </div>
    <?php if (!$products): ?><p class="panel">当前没有符合条件的商品。</p><?php endif; ?>
    <div class="product-grid">
        <?php foreach ($products as $p): $available = (int) $p['stock'] - (int) $p['reserved']; ?>
            <article class="product">
                <p class="muted"><?= e($p['shop_name']) ?> <span class="sku"><?= e($p['sku']) ?></span></p>
                <h3><?= e($p['name']) ?></h3>
                <div class="product-price"><strong><?= cash($p['price']) ?></strong><span>可售 <?= $available ?></span></div>
                <?php if ($role === 'buyer' && $available): ?>
                    <?php form('cart'); echo field('product', $p['id']); ?>
                    <div class="inline">
                        <input type="number" name="quantity" min="1" max="<?= min(99, $available) ?>" value="<?= min($available, (int) ($cart[$p['id']] ?? 1)) ?>" aria-label="<?= e($p['name']) ?>的购买数量" required>
                        <button>设置购物车数量</button>
                    </div>
                    </form>
                <?php elseif ($role === 'guest' && $available): ?>
                    <a class="button secondary" href="/?page=account">登录后选购</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
