<?php
$newPickupCode = $_SESSION['pickup_code'] ?? null;
unset($_SESSION['pickup_code']);
$orders = $app->rows('SELECT o.*,r.name AS promotion_name,c.restored_at AS coupon_restored_at FROM orders o
    LEFT JOIN promotion_revisions r ON r.id=o.promotion_revision_id LEFT JOIN coupon_refund_restorations c ON c.order_id=o.id
    WHERE o.buyer_id=? ORDER BY o.id DESC LIMIT 50', [$id]);
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
        <p class="muted"><?= $order['channel'] === 'web' ? '配送 · ' . e($order['destination']) : '门店自提' ?>
            · <?= e($order['created_at']) ?> UTC</p>
        <?php if ($order['promotion_name']): ?>
            <p class="muted"><?= e($order['promotion_name']) ?> · 优惠 <?= cash($order['discount']) ?></p>
        <?php endif; ?>
        <?php if ($order['coupon_restored_at']): ?>
            <p>优惠券已返还 · <?= e($order['coupon_restored_at']) ?> UTC <a href="/?page=account#coupons">查看优惠券</a></p>
        <?php endif; ?>
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
        <?php foreach ($app->rows('SELECT m.*,s.name,p.location_name,p.location_address,p.prepared_at,p.used_at,p.code_expires_at
            FROM merchant_orders m JOIN shops s ON s.id=m.shop_id LEFT JOIN order_pickups p ON p.merchant_order_id=m.id
            WHERE m.order_id=? ORDER BY m.id', [$order['id']]) as $merchantOrder): ?>
            <section class="suborder">
                <div class="heading"><h3><?= e($merchantOrder['name']) ?>
                    <?= badge($merchantOrder['status'] === 'ready' && $merchantOrder['location_name']
                        ? ($merchantOrder['prepared_at'] ? 'pickup_prepared' : 'pickup_pending') : $merchantOrder['status']) ?></h3>
                    <strong><?= cash($merchantOrder['total']) ?></strong></div>
                <?php if ($merchantOrder['tracking']): ?><p class="muted">履约单号 <?= e($merchantOrder['tracking']) ?></p><?php endif; ?>
                <?php if ($order['channel'] === 'web'):
                    $parcels = $app->shipments((int) $merchantOrder['id']);
                    require __DIR__ . '/shipment_list.php';
                endif; ?>
                <?php if ($merchantOrder['location_name']): ?>
                    <p class="muted">子订单 #<?= e($merchantOrder['id']) ?> · <?= e($merchantOrder['location_name']) ?>
                        · <?= e($merchantOrder['location_address']) ?></p>
                    <?php if ($merchantOrder['used_at']): ?>
                        <p class="muted">已提货 <?= e($merchantOrder['used_at']) ?> UTC</p>
                    <?php elseif ($merchantOrder['status'] === 'ready' && $merchantOrder['prepared_at']): ?>
                        <?php if (($newPickupCode['order'] ?? null) === (int) $merchantOrder['id']):
                            $pickupCode = $newPickupCode['code'];
                            require __DIR__ . '/pickup_barcode.php';
                        else:
                            form('pickup_issue'); echo field('merchant_order', $merchantOrder['id']); ?>
                            <?php if ($merchantOrder['code_expires_at']): ?>
                                <p class="muted">重新生成后，前一个提货码作废。</p>
                            <?php endif; ?>
                            <button type="submit" class="secondary"><?= $merchantOrder['code_expires_at'] ? '重新生成提货码' : '生成提货码' ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php foreach ($app->rows('SELECT i.*,r.color,r.size,b.code AS preorder_code,b.expected_on,b.received_at,
                    (SELECT COALESCE(SUM(' . Commerce::OPEN_RETURN_QUANTITY . '),0) FROM refunds r WHERE r.item_id=i.id) AS pending_qty
                    FROM order_items i LEFT JOIN product_releases r ON r.id=i.product_release_id
                    LEFT JOIN order_preorders op ON op.order_item_id=i.id LEFT JOIN preorder_batches b ON b.id=op.batch_id
                    WHERE i.merchant_order_id=? ORDER BY i.id', [$merchantOrder['id']]) as $item):
                    $remaining = (int) $item['quantity'] - (int) $item['refunded_quantity'] - (int) $item['pending_qty']; ?>
                    <div class="record">
                        <div><?= e($item['name']) ?> × <?= e($item['quantity']) ?>
                            <div class="muted"><?= e($item['sku']) ?> <?= e(trim((string) $item['color'] . ' ' . (string) $item['size'])) ?></div>
                            <?php if ($item['preorder_code']): ?>
                                <div class="muted">预售 <?= e($item['preorder_code']) ?> ·
                                    <?= $item['received_at'] ? '已到货' : '预计 ' . e($item['expected_on']) . ' 到货' ?></div>
                            <?php endif; ?>
                            <div class="muted">实付 <?= cash($item['quantity'] * $item['unit_price'] - $item['discount']) ?> · 已退 <?= e($item['refunded_quantity']) ?></div></div>
                        <?php if ($merchantOrder['status'] === 'fulfilled' && $remaining > 0): ?>
                            <details><summary>申请退货退款</summary>
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
            <div class="record">
                <div>
                    <strong>#<?= e($refund['id']) ?> · <?= e($refund['name']) ?> × <?= e($refund['quantity']) ?></strong>
                    <?= badge($refund['status']) ?>
                    <?php if ($refund['return_address']): ?><p>寄回地址 <?= e($refund['return_address']) ?></p><?php endif; ?>
                    <?php if ($refund['review_note']): ?><p>审核备注 <?= e($refund['review_note']) ?></p><?php endif; ?>
                    <?php if ($refund['tracking']): ?>
                        <p>退货运单 <?= e($refund['carrier']) ?> · <?= e($refund['tracking']) ?></p>
                    <?php endif; ?>
                    <?php if ($refund['accepted_quantity'] !== null): ?><p>验收 <?= e($refund['accepted_quantity']) ?> 件</p><?php endif; ?>
                    <?php if ($refund['inspection_note']): ?><p>验收备注 <?= e($refund['inspection_note']) ?></p><?php endif; ?>
                    <?php if ($refund['freight_amount'] > 0 && $refund['status'] !== 'rejected'): ?>
                        <p>退运费 <?= cash($refund['freight_amount']) ?> · <?= e(Commerce::FREIGHT_PAYERS[$refund['freight_payer']]) ?></p>
                    <?php endif; ?>
                    <?php if ($refund['refund_reference']): ?><p>退款流水 <?= e($refund['refund_reference']) ?></p><?php endif; ?>
                </div>
                <?php if ($refund['status'] === 'return_authorized'):
                    form('return_dispatch'); echo field('refund', $refund['id']); ?>
                    <label>承运商代码<input name="carrier" maxlength="32" pattern="[A-Za-z0-9_\-]+" required></label>
                    <label>退货运单号<input name="tracking" maxlength="80" pattern="[A-Za-z0-9_\-]+" required></label>
                    <button>登记寄回</button></form>
                <?php elseif (in_array($refund['status'], ['approved', 'refund_pending', 'refunded'], true)): ?>
                    <strong><?= cash($refund['amount']) ?></strong>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
