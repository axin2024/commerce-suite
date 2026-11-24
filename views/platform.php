<?php
$shops = $app->rows('SELECT s.*, COALESCE(b.net,0) AS net, COALESCE(b.entries,0) AS entries FROM shops s
    LEFT JOIN (SELECT shop_id,SUM(' . Commerce::LEDGER_NET . ') AS net,COUNT(*) AS entries FROM ledger WHERE settlement_id IS NULL
    GROUP BY shop_id) b ON b.shop_id=s.id ORDER BY s.id DESC LIMIT 100');
$shopFilter = filter_var($_GET['shop'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filter = $shopFilter ? ' WHERE l.shop_id=?' : '';
$arguments = $shopFilter ? [$shopFilter] : [];
$ledger = $app->rows('SELECT l.*,s.name,' . Commerce::LEDGER_NET . ' AS net
    FROM ledger l JOIN shops s ON s.id=l.shop_id' . $filter . ' ORDER BY l.id DESC LIMIT 80', $arguments);
$receiptId = filter_var($_GET['receipt'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$returnReceiptId = filter_var($_GET['return_receipt'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
?>
<div class="heading"><h1>平台运营</h1></div>
<div class="tabs">
    <a href="#shops">店铺管理</a><a href="#ledger">结算分录</a><a href="/?page=settlements">结算单</a>
    <?php if ($role === 'admin'): ?><a href="#catalog-review">商品审核</a><a href="#marketing">会员与营销</a><?php endif; ?>
    <?php if (demoEnabled()): ?><a href="#receipts">沙箱支付回执</a><?php endif; ?>
    <?php if ($role === 'finance'): ?><a href="#freight">平台承担退运费</a><?php endif; ?>
    <?php if ($role === 'finance' && sandboxRefundSecret() !== null): ?><a href="#return-refunds">退货退款回执</a><?php endif; ?>
    <?php if ($role === 'admin'): ?><a href="#audit">审计记录</a><?php endif; ?>
</div>
<section class="panel" id="shops">
    <h2>店铺管理</h2>
    <?php if ($role === 'admin'): ?>
        <details><summary>创建自营店铺</summary>
            <?php form('platform_shop'); ?>
            <div class="inputs">
                <label>店铺名称<input name="shop" maxlength="100" required></label>
                <label>收款主体<input name="payee" maxlength="160" required></label>
                <label>负责人邮箱<input name="email" type="email" maxlength="190" required></label>
                <label>初始密码<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>
            </div>
            <button>创建并提交审核</button></form>
        </details>
    <?php endif; ?>
    <?php if (!$shops): ?><p class="muted">暂无店铺。</p><?php endif; ?>
    <?php foreach ($shops as $shop): ?>
        <div class="record">
            <div><a href="/?page=platform&amp;shop=<?= e($shop['id']) ?>#ledger"><?= e($shop['name']) ?></a> <?= badge($shop['status']) ?>
                <p class="muted"><?= $shop['kind'] === 'platform' ? '平台自营' : '入驻商家' ?> · 佣金 <?= e($shop['commission_bps'] / 100 . '%') ?>
                    · 未结净额 <?= cash($shop['net']) ?> · <?= e($shop['entries']) ?> 笔</p>
                <?php if ($role === 'admin'): ?>
                    <p>收款主体 <?= e($shop['payee_name'] ?? '未登记') ?> · 协议 <?= e($shop['agreement_version'] ?? '未确认') ?></p>
                <?php endif; ?>
            </div>
            <?php if ($role === 'admin' && $shop['status'] === 'pending'):
                form('review'); echo field('shop', $shop['id']); ?>
                <div class="inline"><?php if ($shop['agreement_version']): ?><button name="decision" value="approve">通过</button><?php endif; ?>
                    <button class="secondary" name="decision" value="reject">拒绝</button></div>
                </form>
            <?php endif; ?>
            <?php if ($role === 'admin' && in_array($shop['status'], ['active', 'suspended'], true)): ?>
                <?php if ($shop['legacy_payee_request']): ?>
                    <details><summary>复核补录的收款主体</summary>
                        <p><?= e($shop['legacy_payee_request']) ?></p>
                        <?php form('review_legacy_payee'); echo field('shop', $shop['id']), field('payee', $shop['legacy_payee_request']); ?>
                        <button name="decision" value="approve">确认主体</button>
                        <button class="secondary" name="decision" value="reject">退回</button></form>
                    </details>
                <?php endif; ?>
                <details><summary><?= $shop['status'] === 'active' ? '暂停营业' : '恢复营业' ?></summary>
                    <?php form('shop_status'); echo field('shop', $shop['id']), field('decision', $shop['status'] === 'active' ? 'suspend' : 'resume'); ?>
                    <label>原因<input name="reason" maxlength="250" required></label>
                    <button class="secondary">确认变更</button></form>
                </details>
            <?php endif; ?>
            <?php if ($role === 'finance' && $shop['entries']): ?>
                <a href="/?page=settlements&amp;shop=<?= e($shop['id']) ?>&amp;period=<?= e(gmdate('Y-m')) ?>">结算预览</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="panel" id="ledger">
    <div class="heading"><h2>结算分录</h2>
        <?php if ($shopFilter): ?><a href="/?page=platform#ledger">查看全部店铺</a><?php endif; ?></div>
    <?php if (!$ledger): ?><p class="muted">暂无分录。</p><?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>店铺</th><th>事件</th><th>子订单</th><th>现金</th><th>佣金</th><th>平台承担运费</th><th>积分出资</th><th>应结</th><th>批次</th></tr></thead>
            <tbody><?php foreach ($ledger as $entry): ?>
                <tr><td><?= e($entry['name']) ?></td><td><?= $entry['kind'] === 'sale' ? '销售' : '退款冲正' ?></td>
                    <td>#<?= e($entry['merchant_order_id']) ?></td><td><?= cash($entry['gross']) ?></td>
                    <td><?= cash($entry['commission']) ?></td><td><?= cash($entry['platform_freight']) ?></td>
                    <td><?= cash($entry['platform_points']) ?></td><td><?= cash($entry['net']) ?></td>
                    <td><?php if ($entry['settlement_id']): ?>
                        <a href="/?page=settlements&amp;batch=<?= e($entry['settlement_id']) ?>">#<?= e($entry['settlement_id']) ?></a>
                    <?php else: ?>待结算<?php endif; ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="panel" id="receipts">
    <h2>渠道支付回执</h2>
    <?php
    $receiptStates = ['accepted' => '已入账', 'refund_required' => '待原路退回',
        'needs_review' => '待核对', 'refunded' => '已登记退回'];
    $receiptReasons = ['unknown_intent' => '找不到支付意图', 'channel_mismatch' => '回执渠道与订单不符',
        'amount_or_currency_mismatch' => '金额或币种差异',
        'duplicate_charge' => '同订单重复实收', 'late_capture' => '订单关闭后实收'];
    $receipts = $app->rows('SELECT id,provider,reference,status,order_id,currency,amount,failure_code,refund_reference,received_at
        FROM payment_receipts' . ($receiptId ? ' WHERE id=?' : '') . ' ORDER BY id DESC LIMIT 100', $receiptId ? [$receiptId] : []);
    ?>
    <?php if (!$receipts): ?><p class="muted">暂无渠道回执。</p><?php endif; ?>
    <?php foreach ($receipts as $receipt): ?>
        <div class="record">
            <div><strong><?= e($receipt['provider']) ?> · <?= e($receipt['reference']) ?> · <?= e($receiptStates[$receipt['status']]) ?></strong>
                <p class="muted"><?= e($receipt['order_id'] ? '订单 #' . $receipt['order_id'] : '待匹配订单') ?> ·
                    <?= e($receipt['currency']) ?> <?= e($receipt['amount']) ?>（最小货币单位）
                    · <?= e($receipt['received_at']) ?> UTC</p>
                <?php if ($receipt['failure_code']): ?><p><?= e($receiptReasons[$receipt['failure_code']]) ?></p><?php endif; ?>
                <?php if ($receipt['refund_reference']): ?><p>退款回执 <?= e($receipt['refund_reference']) ?></p><?php endif; ?>
            </div>
            <?php if ($role === 'finance' && $receipt['provider'] === 'sandbox' && demoEnabled() && $receipt['status'] === 'refund_required'):
                form('sandbox_refund'); echo field('receipt', $receipt['id']); ?>
                <label>退款回执号<input name="reference" maxlength="80" required></label>
                <button class="secondary">登记渠道退款</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php if ($role === 'finance') { require __DIR__ . '/return_freight.php'; } ?>
<?php if ($role === 'finance'): ?>
<section class="panel" id="return-refunds">
    <h2>退货退款回执</h2>
    <?php $returnReceipts = $app->rows("SELECT r.id,r.status,r.amount,r.refund_token,r.refund_reference,r.freight_amount,r.freight_payer,
        r.points_spent,r.points_restored,r.points_reversed,
        p.reference AS payment_reference,p.provider AS payment_provider,s.name FROM refunds r JOIN order_items i ON i.id=r.item_id
        JOIN merchant_orders m ON m.id=i.merchant_order_id JOIN shops s ON s.id=m.shop_id
        JOIN payments p ON p.order_id=m.order_id WHERE r.status IN ('refund_pending','refunded')"
        . ($returnReceiptId ? ' AND r.id=?' : '') . " ORDER BY (r.status='refund_pending') DESC,r.id DESC LIMIT 50",
        $returnReceiptId ? [$returnReceiptId] : []); ?>
    <?php if (!$returnReceipts): ?><p class="muted">暂无已验收退货。</p><?php endif; ?>
    <?php foreach ($returnReceipts as $returnReceipt): ?>
        <div class="record">
            <div><strong>#<?= e($returnReceipt['id']) ?> · <?= e($returnReceipt['name']) ?> · <?= cash($returnReceipt['amount']) ?></strong>
                <?= badge($returnReceipt['status']) ?>
                <?php if ($returnReceipt['freight_amount'] > 0): ?>
                    <p>货款 <?= cash($returnReceipt['amount'] - $returnReceipt['freight_amount']) ?>
                        · 退运费 <?= cash($returnReceipt['freight_amount']) ?> · <?= e(Commerce::FREIGHT_PAYERS[$returnReceipt['freight_payer']]) ?></p>
                <?php endif; ?>
                <?php if ($returnReceipt['points_spent'] || $returnReceipt['points_reversed']): ?>
                    <p>返还抵扣 <?= e($returnReceipt['points_restored']) ?> 积分 · 扣回赠送 <?= e($returnReceipt['points_reversed']) ?> 积分</p>
                <?php endif; ?>
                <p>原支付流水 <code class="return-payment"><?= e($returnReceipt['payment_reference']) ?></code>
                    · <?= e($returnReceipt['payment_provider']) ?></p>
                <?php if ($returnReceipt['status'] === 'refund_pending'): ?>
                    <p>退款意图 <code class="return-token"><?= e($returnReceipt['refund_token']) ?></code></p>
                    <?php if ((int) $returnReceipt['amount'] === 0):
                        form('confirm_zero_return'); echo field('refund', $returnReceipt['id']); ?>
                        <button class="secondary">确认零现金退货</button></form>
                    <?php endif; ?>
                <?php elseif ($returnReceipt['refund_reference']): ?>
                    <p>退款流水 <?= e($returnReceipt['refund_reference']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($role === 'admin'): ?>
    <?php require __DIR__ . '/catalog_review.php'; ?>
    <?php require __DIR__ . '/promotion_admin.php'; ?>
    <section class="panel" id="audit">
        <h2>审计记录</h2>
        <?php foreach ($app->rows('SELECT a.*,u.email FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 50') as $event): ?>
            <div class="record"><strong><?= e($event['action']) ?> · #<?= e($event['entity_id']) ?></strong>
                <span class="muted"><?= e($event['email'] ?? '系统') ?> · <?= e($event['created_at']) ?> UTC</span></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
