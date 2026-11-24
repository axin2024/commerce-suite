<?php
$orders = $app->rows('SELECT * FROM orders WHERE buyer_id=? ORDER BY id DESC LIMIT 50', [$id]);
$refunds = $app->rows('SELECT r.*,i.name FROM refunds r JOIN order_items i ON i.id=r.item_id
    WHERE r.buyer_id=? ORDER BY r.id DESC LIMIT 50', [$id]);
?>
<div class="heading"><h1>我的订单</h1></div>
<?php if (!$orders): ?><p class="panel">还没有订单。</p><?php endif; ?>
<?php foreach ($orders as $order): ?>
    <article class="panel order">
        <div class="heading">
            <h2>订单 #<?= e($order['id']) ?> <?= badge($order['status']) ?></h2>
            <strong class="amount"><?= cash($order['total']) ?></strong>
        </div>
        <p class="muted"><?= $order['channel'] === 'web' ? '配送' : '自提' ?> · <?= e($order['destination']) ?> · <?= e($order['created_at']) ?> UTC</p>
        <?php if (sandboxWebhookSecret() !== null): ?>
            <p class="muted">沙箱支付意图 <code><?= e($order['payment_token']) ?></code></p>
        <?php endif; ?>
        <?php if ($order['status'] === 'unpaid'): ?>
            <div class="inline">
                <?php if (demoEnabled()): form('pay'); echo field('order', $order['id']), field('amount', $order['total']); ?>
                    <button>模拟支付</button></form>
                <?php endif; ?>
                <?php form('cancel'); echo field('order', $order['id']); ?>
                <button class="secondary">取消订单</button></form>
            </div>
        <?php endif; ?>
        <?php foreach ($app->rows('SELECT m.*,s.name FROM merchant_orders m JOIN shops s ON s.id=m.shop_id
            WHERE m.order_id=? ORDER BY m.id', [$order['id']]) as $merchantOrder): ?>
            <section class="suborder">
                <div class="heading"><h3><?= e($merchantOrder['name']) ?> <?= badge($merchantOrder['status']) ?></h3>
                    <strong><?= cash($merchantOrder['total']) ?></strong></div>
                <?php if ($merchantOrder['tracking']): ?><p class="muted">履约单号 <?= e($merchantOrder['tracking']) ?></p><?php endif; ?>
                <?php foreach ($app->rows('SELECT i.*,r.color,r.size,
                    (SELECT COALESCE(SUM(r.quantity),0) FROM refunds r WHERE r.item_id=i.id AND r.status=\'pending\') AS pending_qty
                    FROM order_items i LEFT JOIN product_releases r ON r.id=i.product_release_id
                    WHERE i.merchant_order_id=? ORDER BY i.id', [$merchantOrder['id']]) as $item):
                    $remaining = (int) $item['quantity'] - (int) $item['refunded_quantity'] - (int) $item['pending_qty']; ?>
                    <div class="record">
                        <div><?= e($item['name']) ?> × <?= e($item['quantity']) ?>
                            <div class="muted"><?= e($item['sku']) ?> <?= e(trim((string) $item['color'] . ' ' . (string) $item['size'])) ?></div>
                            <div class="muted">实付 <?= cash($item['quantity'] * $item['unit_price'] - $item['discount']) ?> · 已退 <?= e($item['refunded_quantity']) ?></div></div>
                        <?php if ($merchantOrder['status'] === 'fulfilled' && $remaining > 0): ?>
                            <details><summary>申请退款</summary>
                                <?php form('refund'); echo field('item', $item['id']), field('key', bin2hex(random_bytes(16))); ?>
                                <label>数量<input type="number" min="1" max="<?= $remaining ?>" name="quantity" value="1" required></label>
                                <label>原因<input name="reason" maxlength="80" required></label>
                                <button>提交申请</button></form>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </article>
<?php endforeach; ?>
<?php if ($refunds): ?>
    <section class="panel">
        <h2>售后记录</h2>
        <?php foreach ($refunds as $refund): ?>
            <div class="record"><span>#<?= e($refund['id']) ?> · <?= e($refund['name']) ?> × <?= e($refund['quantity']) ?></span>
                <span><?= badge($refund['status']) ?> <?= cash($refund['amount']) ?></span></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
