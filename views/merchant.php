<?php
$shop = $app->rows('SELECT * FROM shops WHERE id=?', [$user['shop_id']])[0];
$inventory = $app->rows('SELECT * FROM products WHERE shop_id=? ORDER BY id DESC LIMIT 100', [$shop['id']]);
$merchantOrders = $app->rows('SELECT m.*,o.channel,o.destination FROM merchant_orders m JOIN orders o ON o.id=m.order_id
    WHERE m.shop_id=? AND m.status IN (\'ready\',\'fulfilled\') ORDER BY m.id DESC LIMIT 50', [$shop['id']]);
$refunds = $app->rows('SELECT r.*,i.name FROM refunds r JOIN order_items i ON i.id=r.item_id
    JOIN merchant_orders m ON m.id=i.merchant_order_id WHERE m.shop_id=? ORDER BY r.id DESC LIMIT 50', [$shop['id']]);
?>
<div class="heading"><h1><?= e($shop['name']) ?></h1><?= badge($shop['status']) ?></div>
<?php if ($shop['status'] !== 'active'): ?><p class="panel">店铺通过平台审核后即可经营。</p><?php return; endif; ?>
<div class="tabs"><a href="#products">商品库存</a><a href="#fulfillment">履约</a><a href="#refunds">售后</a></div>
<section class="panel" id="products">
    <h2>商品与库存</h2>
    <?php
    $productForm = static function (array $p): void {
        form('product');
        echo field('id', $p['id']), field('version', $p['version']);
        ?>
        <div class="inputs">
            <label>SKU<input name="sku" value="<?= e($p['sku']) ?>" required maxlength="64" pattern="[A-Za-z0-9_\-]+"></label>
            <label>名称<input name="name" value="<?= e($p['name']) ?>" required maxlength="52"></label>
            <label>价格（元）<input name="price" value="<?= e($p['price'] === '' ? '' : number_format($p['price'] / 100, 2, '.', '')) ?>" inputmode="decimal" required></label>
            <label>在库总量<input type="number" name="stock" value="<?= e($p['stock']) ?>" min="<?= e($p['reserved']) ?>" required></label>
            <label>状态<select name="status">
                <?php foreach (['draft' => '草稿', 'active' => '上架', 'archived' => '归档'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $p['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
        </div>
        <button>保存商品</button></form>
        <?php
    };
    ?>
    <details><summary>新增商品</summary>
        <?php $productForm(['id' => 0, 'version' => 0, 'sku' => '', 'name' => '', 'price' => '', 'stock' => 0, 'reserved' => 0, 'status' => 'draft']); ?>
    </details>
    <?php foreach ($inventory as $product): ?>
        <details class="inventory">
            <summary><?= e($product['name']) ?> · <?= e($product['sku']) ?> · <?= cash($product['price']) ?> · <?= badge($product['status']) ?>
                <span class="muted">在库 <?= e($product['stock']) ?> / 预留 <?= e($product['reserved']) ?></span></summary>
            <?php $productForm($product); ?>
        </details>
    <?php endforeach; ?>
</section>
<section class="panel" id="fulfillment">
    <h2>订单履约</h2>
    <?php if (!$merchantOrders): ?><p class="muted">暂无已付款订单。</p><?php endif; ?>
    <?php foreach ($merchantOrders as $order): ?>
        <div class="record">
            <div><strong>#<?= e($order['id']) ?> · <?= cash($order['total']) ?></strong> <?= badge($order['status']) ?>
                <p class="muted"><?= $order['channel'] === 'web' ? '配送' : '自提' ?> · <?= e($order['destination']) ?></p>
                <?php foreach ($app->rows('SELECT name,quantity FROM order_items WHERE merchant_order_id=? ORDER BY id', [$order['id']]) as $line): ?>
                    <div><?= e($line['name']) ?> × <?= e($line['quantity']) ?></div>
                <?php endforeach; ?>
            </div>
            <?php if ($order['status'] === 'ready'): ?>
                <?php form('fulfill'); echo field('merchant_order', $order['id']); ?>
                <label><?= $order['channel'] === 'web' ? '物流单号' : '自提核验编号' ?><input name="tracking" maxlength="40" required></label>
                <button>确认履约</button></form>
            <?php else: ?><span><?= e($order['tracking']) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="panel" id="refunds">
    <h2>售后审核</h2>
    <?php if (!$refunds): ?><p class="muted">暂无售后申请。</p><?php endif; ?>
    <?php foreach ($refunds as $refund): ?>
        <div class="record">
            <div>#<?= e($refund['id']) ?> · <?= e($refund['name']) ?> × <?= e($refund['quantity']) ?> <?= badge($refund['status']) ?>
                <p class="muted"><?= e($refund['reason']) ?></p></div>
            <?php if ($refund['status'] === 'pending'):
                form('decide'); echo field('refund', $refund['id']); ?>
                <div class="inline"><button name="decision" value="approve">批准</button>
                    <button class="secondary" name="decision" value="reject">拒绝</button></div></form>
            <?php else: ?><strong><?= cash($refund['amount']) ?></strong><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
