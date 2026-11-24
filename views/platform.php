<?php
$shops = $app->rows('SELECT s.*, COALESCE(b.net,0) AS net, COALESCE(b.entries,0) AS entries FROM shops s
    LEFT JOIN (SELECT shop_id,SUM(gross-commission) AS net,COUNT(*) AS entries FROM ledger WHERE settlement_id IS NULL
    GROUP BY shop_id) b ON b.shop_id=s.id ORDER BY s.id DESC LIMIT 100');
$shopFilter = filter_var($_GET['shop'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filter = $shopFilter ? ' WHERE l.shop_id=?' : '';
$arguments = $shopFilter ? [$shopFilter] : [];
$ledger = $app->rows('SELECT l.*,s.name FROM ledger l JOIN shops s ON s.id=l.shop_id' . $filter . ' ORDER BY l.id DESC LIMIT 80', $arguments);
$batches = $app->rows('SELECT l.*,s.name FROM settlements l JOIN shops s ON s.id=l.shop_id' . $filter . ' ORDER BY l.id DESC LIMIT 50', $arguments);
?>
<div class="heading"><h1>平台运营</h1></div>
<div class="tabs">
    <a href="#shops">店铺管理</a><a href="#ledger">结算分录</a><a href="#batches">结算批次</a>
    <?php if (demoEnabled()): ?><a href="#receipts">沙箱支付回执</a><?php endif; ?>
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
                <details><summary><?= $shop['status'] === 'active' ? '暂停营业' : '恢复营业' ?></summary>
                    <?php form('shop_status'); echo field('shop', $shop['id']), field('decision', $shop['status'] === 'active' ? 'suspend' : 'resume'); ?>
                    <label>原因<input name="reason" maxlength="250" required></label>
                    <button class="secondary">确认变更</button></form>
                </details>
            <?php endif; ?>
            <?php if ($shop['entries'] && $shop['net'] >= 0):
                form('settle'); echo field('shop', $shop['id']); ?><button>生成结算批次</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="panel" id="ledger">
    <div class="heading"><h2>结算分录</h2>
        <?php if ($shopFilter): ?><a href="/?page=platform#ledger">查看全部店铺</a><?php endif; ?></div>
    <?php if (!$ledger): ?><p class="muted">暂无分录。</p><?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>店铺</th><th>事件</th><th>子订单</th><th>总额</th><th>佣金</th><th>应结</th><th>批次</th></tr></thead>
            <tbody><?php foreach ($ledger as $entry): ?>
                <tr><td><?= e($entry['name']) ?></td><td><?= $entry['kind'] === 'sale' ? '销售' : '退款冲正' ?></td>
                    <td>#<?= e($entry['merchant_order_id']) ?></td><td><?= cash($entry['gross']) ?></td>
                    <td><?= cash($entry['commission']) ?></td><td><?= cash($entry['gross'] - $entry['commission']) ?></td>
                    <td><?= e($entry['settlement_id'] ?? '待结算') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="panel" id="batches">
    <h2>结算批次</h2>
    <?php if (!$batches): ?><p class="muted">暂无批次。</p><?php endif; ?>
    <?php foreach ($batches as $batch): ?>
        <div class="record"><span>#<?= e($batch['id']) ?> · <?= e($batch['name']) ?> · <?= e($batch['created_at']) ?> UTC</span>
            <strong><?= cash($batch['amount']) ?></strong></div>
    <?php endforeach; ?>
</section>
<?php if (demoEnabled()): ?>
<section class="panel" id="receipts">
    <h2>沙箱支付回执</h2>
    <?php
    $receiptStates = ['accepted' => '已入账', 'refund_required' => '待原路退回',
        'needs_review' => '待核对', 'refunded' => '已登记退回'];
    $receiptReasons = ['unknown_intent' => '找不到支付意图', 'amount_or_currency_mismatch' => '金额或币种差异',
        'duplicate_charge' => '同订单重复实收', 'late_capture' => '订单关闭后实收'];
    $receipts = $app->rows('SELECT id,reference,status,order_id,currency,amount,failure_code,refund_reference,received_at
        FROM payment_receipts ORDER BY id DESC LIMIT 100');
    ?>
    <?php if (!$receipts): ?><p class="muted">暂无外部沙箱回执。</p><?php endif; ?>
    <?php foreach ($receipts as $receipt): ?>
        <div class="record">
            <div><strong><?= e($receipt['reference']) ?> · <?= e($receiptStates[$receipt['status']]) ?></strong>
                <p class="muted"><?= e($receipt['order_id'] ? '订单 #' . $receipt['order_id'] : '待匹配订单') ?> ·
                    <?= e($receipt['currency']) ?> <?= e($receipt['amount']) ?>（最小货币单位）
                    · <?= e($receipt['received_at']) ?> UTC</p>
                <?php if ($receipt['failure_code']): ?><p><?= e($receiptReasons[$receipt['failure_code']]) ?></p><?php endif; ?>
                <?php if ($receipt['refund_reference']): ?><p>退款回执 <?= e($receipt['refund_reference']) ?></p><?php endif; ?>
            </div>
            <?php if ($role === 'finance' && $receipt['status'] === 'refund_required'):
                form('sandbox_refund'); echo field('receipt', $receipt['id']); ?>
                <label>退款回执号<input name="reference" maxlength="80" required></label>
                <button class="secondary">登记渠道退款</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($role === 'admin'): ?>
    <section class="panel" id="audit">
        <h2>审计记录</h2>
        <?php foreach ($app->rows('SELECT a.*,u.email FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 50') as $event): ?>
            <div class="record"><strong><?= e($event['action']) ?> · #<?= e($event['entity_id']) ?></strong>
                <span class="muted"><?= e($event['email'] ?? '系统') ?> · <?= e($event['created_at']) ?> UTC</span></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
