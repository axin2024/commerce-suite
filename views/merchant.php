<?php
$shop = $app->rows('SELECT * FROM shops WHERE id=?', [$user['shop_id']])[0];
$canOperate = in_array($shop['status'], ['active', 'suspended'], true);
$isOwner = Commerce::canManage($user, 'owner');
$canCatalog = $canOperate && Commerce::canManage($user, 'catalog');
$canFulfill = $canOperate && Commerce::canManage($user, 'fulfillment');
$canPickup = $canOperate && Commerce::canManage($user, 'pickup');
$canSupport = $canOperate && Commerce::canManage($user, 'support');
$canFinance = $canOperate && Commerce::canManage($user, 'finance');
$pickupLocations = $isOwner && $canOperate ? $app->merchantPickupLocations((int) $user['id']) : [];
?>
<div class="heading"><h1><?= e($shop['name']) ?></h1><?= badge($shop['status']) ?></div>
<p class="muted"><?= $shop['kind'] === 'platform' ? '平台自营' : '入驻商家' ?> ·
    <?= e(Commerce::STAFF_POSITIONS[$user['position']] ?? '店主') ?></p>
<?php if (!$canOperate): ?><p class="panel">店铺通过平台审核后即可经营。</p><?php endif; ?>
<?php if ($shop['status'] === 'suspended'): ?><p class="panel">店铺暂停接单，已接订单可继续履约和售后。</p><?php endif; ?>
<div class="tabs">
    <?php if ($isOwner): ?><a href="#business">入驻资料</a><a href="#staff">店铺成员</a><?php endif; ?>
    <?php if ($canCatalog): ?><a href="#products">商品库存</a><?php endif; ?>
    <?php if ($isOwner && $canOperate): ?>
        <a href="#freight">运费规则</a><a href="/?page=sales_channels">外部销售渠道</a>
    <?php endif; ?>
    <?php if ($canFulfill): ?><a href="#fulfillment">履约</a><?php endif; ?>
    <?php if ($canPickup): ?><a href="#pickup">门店自提</a><?php endif; ?>
    <?php if ($canSupport || $canFulfill): ?><a href="#refunds">售后</a><?php endif; ?>
    <?php if ($canFinance): ?><a href="#finance">本店账单</a><?php endif; ?>
</div>
<?php if ($isOwner): ?>
<section class="panel" id="business">
    <h2>入驻资料</h2>
    <?php if ($canOperate): ?>
        <p>收款主体 <?= e($shop['payee_name'] ?? '旧版入驻记录未登记') ?> · 协议 <?= e($shop['agreement_version'] ?? '旧版未登记') ?></p>
        <?php if ($shop['legacy_payee_request']): ?><p>待平台核验 <?= e($shop['legacy_payee_request']) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if (!$canOperate || ($shop['payee_name'] === null && $shop['agreement_version'] === null)): ?>
        <?php form('shop_agreement'); ?>
        <label>收款主体名称<input name="payee" value="<?= e($shop['legacy_payee_request'] ?? $shop['payee_name']) ?>" maxlength="160" required></label>
        <p>结算协议 <?= e(Commerce::AGREEMENT_VERSION) ?> · 按履约订单净额入账，平台按约定费率收取佣金，退款与对应佣金按原单冲回。</p>
        <button>确认协议并提交审核</button></form>
    <?php endif; ?>
</section>
<section class="panel" id="staff">
    <h2>店铺成员</h2>
    <details><summary>添加成员</summary>
        <?php form('shop_member'); ?>
        <div class="inputs">
            <label>邮箱<input name="email" type="email" maxlength="190" required></label>
            <label>初始密码<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>
            <label>岗位<select name="position">
                <?php foreach (Commerce::STAFF_POSITIONS as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
            <?php if ($pickupLocations): ?>
                <label>自提门店（门店核销岗位）<select name="pickup_location"><option value="">请选择</option>
                    <?php foreach ($pickupLocations as $location): ?>
                        <option value="<?= e($location['id']) ?>"><?= e($location['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <?php endif; ?>
        </div>
        <button>添加成员</button></form>
    </details>
    <?php foreach ($app->rows("SELECT m.user_id,m.position,m.enabled,u.email,l.name AS location_name FROM shop_members m
        JOIN users u ON u.id=m.user_id LEFT JOIN pickup_locations l ON l.id=m.pickup_location_id
        WHERE m.shop_id=? AND m.position<>'owner' ORDER BY m.user_id", [$shop['id']]) as $member): ?>
        <div class="record">
            <span><?= e($member['email']) ?> · <?= e(Commerce::STAFF_POSITIONS[$member['position']]) ?> ·
                <?php if ($member['location_name']): ?><?= e($member['location_name']) ?> · <?php endif; ?>
                <?= $member['enabled'] ? '已启用' : '已停用' ?></span>
            <?php form('shop_member_access'); echo field('member', $member['user_id']), field('enabled', $member['enabled'] ? 0 : 1); ?>
            <button class="secondary"><?= $member['enabled'] ? '停用' : '启用' ?></button></form>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($canCatalog) { require __DIR__ . '/catalog_workbench.php'; } ?>
<?php if ($isOwner && $canOperate) { require __DIR__ . '/freight_workbench.php'; } ?>
<?php if ($canFulfill):
    $merchantOrders = $app->rows("SELECT m.*,o.destination FROM merchant_orders m JOIN orders o ON o.id=m.order_id
        WHERE m.shop_id=? AND o.channel='web' AND m.status IN ('ready','partial','shipped','fulfilled')
        ORDER BY m.id DESC LIMIT 50", [$shop['id']]); ?>
<section class="panel" id="fulfillment">
    <div class="heading"><h2>订单履约</h2><a href="/?page=merchant_orders">查询历史订单</a></div>
    <?php if (!$merchantOrders): ?><p class="muted">暂无已付款订单。</p><?php endif; ?>
    <?php foreach ($merchantOrders as $order): ?>
        <div class="record">
            <div><strong>#<?= e($order['id']) ?> · <?= cash($order['total']) ?></strong> <?= badge($order['status']) ?>
                <p class="muted"><?= e($order['destination']) ?></p>
                <?php if ($app->hasPendingPreorders((int) $order['id'])): ?><p class="muted">部分商品等待预售到货。</p><?php endif; ?>
                <?php foreach ($app->rows('SELECT i.name,i.sku,i.quantity,r.color,r.size FROM order_items i
                    LEFT JOIN product_releases r ON r.id=i.product_release_id WHERE i.merchant_order_id=? ORDER BY i.id', [$order['id']]) as $line): ?>
                    <div><?= e($line['name']) ?> × <?= e($line['quantity']) ?>
                        <span class="muted"><?= e($line['sku']) ?> <?= e(trim((string) $line['color'] . ' ' . (string) $line['size'])) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php
            $groups = [];
            if (in_array($order['status'], ['ready', 'partial'], true)) {
                foreach ($app->unshippedLines((int) $order['id']) as $line) {
                    $groups[$line['warehouse_id']][] = $line;
                }
            }
            foreach ($groups as $warehouse => $lines): ?>
                <details><summary><?= e($lines[0]['warehouse_name']) ?> · 新建包裹</summary>
                    <?php form('shipment_dispatch'); echo field('merchant_order', $order['id']),
                        field('warehouse', $warehouse), field('key', bin2hex(random_bytes(16))); ?>
                    <?php foreach ($lines as $line): ?>
                        <label><?= e($line['sku']) ?> · 待发 <?= e($line['remaining']) ?>
                            <input type="number" name="quantities[<?= e($line['id']) ?>]" min="0"
                                max="<?= e($line['remaining']) ?>" value="<?= e($line['remaining']) ?>" required>
                        </label>
                    <?php endforeach; ?>
                    <label>承运商代码<input name="carrier" maxlength="32" pattern="[A-Za-z0-9_\-]+" required></label>
                    <label>运单号<input name="tracking" maxlength="80" pattern="[A-Za-z0-9_\-]+" required></label>
                    <button>登记发货</button></form>
                </details>
            <?php endforeach;
            $parcels = $app->shipments((int) $order['id']);
            require __DIR__ . '/shipment_list.php'; ?>
            <?php if ($order['tracking']): ?><span><?= e($order['tracking']) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($canPickup) { require __DIR__ . '/pickup_workbench.php'; } ?>
<?php if ($canSupport || $canFulfill) { require __DIR__ . '/return_workbench.php'; } ?>
<?php if ($canFinance): ?>
<section class="panel" id="finance">
    <div class="heading"><h2>本店账单</h2>
        <div class="inline"><a href="/?page=settlements">结算单与导出</a><a href="/?page=channel_bills">上传渠道回执</a></div>
    </div>
    <?php $entries = $app->merchantLedger((int) $user['id']); ?>
    <?php if (!$entries): ?><p class="muted">暂无结算分录。</p><?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>子订单</th><th>事件</th><th>现金</th><th>佣金</th><th>平台承担运费</th><th>积分出资</th><th>净额</th><th>结算批次</th></tr></thead>
            <tbody><?php foreach ($entries as $entry): ?>
                <tr><td>#<?= e($entry['merchant_order_id']) ?></td><td><?= $entry['kind'] === 'sale' ? '销售' : '退款冲正' ?></td>
                    <td><?= cash($entry['gross']) ?></td><td><?= cash($entry['commission']) ?></td>
                    <td><?= cash($entry['platform_freight']) ?></td><td><?= cash($entry['platform_points']) ?></td><td><?= cash($entry['net']) ?></td>
                    <td><?php if ($entry['settlement_id']): ?>
                        <a href="/?page=settlements&amp;batch=<?= e($entry['settlement_id']) ?>">#<?= e($entry['settlement_id']) ?></a>
                    <?php else: ?>待结算<?php endif; ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
