<?php
$search = is_string($_GET['q'] ?? null) && strlen($_GET['q']) <= 150 ? $_GET['q'] : '';
$categoryFilter = filter_var($_GET['category'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$channelFilter = in_array($_GET['channel'] ?? '', ['web', 'store'], true) ? $_GET['channel'] : '';
$products = $app->catalog($search, $categoryFilter, $channelFilter);
$preorders = [];
foreach ($app->catalogPreorders(array_column($products, 'id'), $channelFilter) as $batch) {
    $preorders[$batch['product_id']][] = $batch;
}
$offers = $app->promotionOffers();
$claimed = $role === 'buyer' ? array_column($app->buyerCoupons((int) $user['id']), 'status', 'id') : [];
?>
<section class="hero">
    <div>
        <h1>全渠道商城</h1>
        <p>选购不同店铺的商品，统一下单付款。支持配送到家和门店自提。</p>
    </div>
</section>
<?php if ($offers): ?>
<section class="panel">
    <h2>跨店优惠</h2>
    <?php foreach ($offers as $offer): ?>
        <div class="record">
            <div><strong><?= e($offer['name']) ?></strong>
                <p class="muted"><?= e($offer['category_name'] ?? '全场') ?> · <?= e(promotionTerms($offer)) ?>
                    <?= $offer['stack_member_price'] ? ' · 可叠加会员价' : ' · 活动商品按标价计算门槛' ?></p>
            </div>
            <?php if (!$offer['requires_claim']): ?><span>结算自动匹配</span>
            <?php elseif (isset($claimed[$offer['id']])): ?><a href="/?page=account#coupons">已领取</a>
            <?php elseif ($role === 'buyer'):
                form('claim_coupon'); echo field('promotion', $offer['id']); ?>
                <button class="secondary">领券</button></form>
            <?php elseif ($role === 'guest'): ?><a href="/?page=account">登录领券</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<section>
    <div class="heading">
        <h2>商品目录</h2>
        <form class="search" method="get" action="/">
            <input aria-label="搜索商品名称、规格或 SKU" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="商品名称、规格或 SKU">
            <select aria-label="商品分类" name="category">
                <option value="">全部分类</option>
                <?php foreach ($app->categories() as $category): ?>
                    <option value="<?= e($category['id']) ?>"<?= $categoryFilter === (int) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select aria-label="销售渠道" name="channel">
                <option value="">全部渠道</option>
                <option value="web"<?= $channelFilter === 'web' ? ' selected' : '' ?>>配送</option>
                <option value="store"<?= $channelFilter === 'store' ? ' selected' : '' ?>>门店自提</option>
            </select>
            <button class="secondary">搜索</button>
        </form>
    </div>
    <?php if (!$products): ?><p class="panel">当前没有符合条件的商品。</p><?php endif; ?>
    <div class="product-grid">
        <?php foreach ($products as $p): $available = (int) $p['stock'] - (int) $p['reserved']; ?>
            <article class="product">
                <p class="muted"><?= e($p['shop_name']) ?> <span class="sku"><?= e($p['sku']) ?></span></p>
                <h3><?= e($p['name']) ?></h3>
                <p class="muted"><?= e(implode(' · ', array_filter([$p['category_name'], $p['family_name'], $p['color'], $p['size']]))) ?></p>
                <p class="muted"><?= e(implode('、', array_filter([$p['delivery'] ? '配送' : '', $p['pickup'] ? '门店自提' : '']))) ?></p>
                <div class="product-price"><strong><?= cash($p['price']) ?></strong><span>可售 <?= $available ?></span></div>
                <?php if ($role === 'buyer' && $user['member_level'] === 'plus' && $p['member_price'] !== null): ?>
                    <p>会员价 <strong><?= cash($p['member_price']) ?></strong></p>
                <?php endif; ?>
                <?php if (in_array($role, ['guest', 'buyer'], true) && $available): ?>
                    <?php form('cart'); echo field('product', $p['id']); ?>
                    <div class="inline">
                        <input type="number" name="quantity" min="1" max="<?= min(99, $available) ?>" value="<?= min($available, (int) ($cart[$p['id']] ?? 1)) ?>" aria-label="<?= e($p['name']) ?>的购买数量" required>
                        <button>设置购物车数量</button>
                    </div>
                    </form>
                <?php endif; ?>
                <?php foreach ($preorders[$p['id']] ?? [] as $batch): ?>
                    <div class="inventory">
                        <strong>预售 <?= e($batch['code']) ?></strong>
                        <p class="muted">预计 <?= e($batch['expected_on']) ?> 到货 · 剩余 <?= e($batch['available']) ?> 件</p>
                        <?php if ($batch['pickup_name'] && $channelFilter !== 'web'): ?>
                            <p class="muted">可在<?= e($batch['pickup_name']) ?>自提</p>
                        <?php endif; ?>
                        <?php if ($role === 'buyer'):
                            form('cart'); echo field('product', $p['id']), field('preorder_batch', $batch['id']); ?>
                            <div class="inline">
                                <input type="number" name="quantity" min="1" max="<?= min(99, $batch['available']) ?>" value="1"
                                    aria-label="预售购买数量" required>
                                <button class="secondary">选购此批次</button>
                            </div></form>
                        <?php elseif ($role === 'guest'): ?>
                            <a class="button secondary" href="/?page=account">登录后预订</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
