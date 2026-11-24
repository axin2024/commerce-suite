<section class="panel" id="pickup">
    <h2>门店自提</h2>
    <?php if ($isOwner):
        $locationFields = static function (array $location): void { ?>
            <label>门店编号<input name="code" value="<?= e($location['code'] ?? '') ?>" maxlength="32" required></label>
            <label>门店名称<input name="name" value="<?= e($location['name'] ?? '') ?>" maxlength="32" required></label>
            <label>门店地址<input name="address" value="<?= e($location['address'] ?? '') ?>" maxlength="80" required></label>
            <label class="inline"><input type="checkbox" name="enabled" value="1"<?= ($location['enabled'] ?? true) ? ' checked' : '' ?>>
                接受新自提订单</label>
        <?php }; ?>
        <details><summary>新增自提门店</summary>
            <?php form('pickup_location_save'); echo field('pickup_location', 0); ?>
            <label>库存仓<select name="warehouse" required>
                <option value="">选择该门店的库存仓</option>
                <?php $boundWarehouses = array_column($pickupLocations, 'warehouse_id');
                foreach ($app->merchantWarehouses((int) $user['id']) as $warehouse):
                    if (!in_array($warehouse['id'], $boundWarehouses, true)): ?>
                        <option value="<?= e($warehouse['id']) ?>"><?= e($warehouse['name']) ?></option>
                    <?php endif;
                endforeach; ?>
            </select></label>
            <?php $locationFields([]); ?>
            <button>保存门店</button></form>
        </details>
        <?php foreach ($pickupLocations as $location): ?>
            <details><summary><?= e($location['name']) ?> · <?= $location['enabled'] ? '接单中' : '已停接新单' ?></summary>
                <p class="muted">库存仓 <?= e($location['warehouse_name']) ?></p>
                <?php form('pickup_location_save'); echo field('pickup_location', $location['id']), field('warehouse', 0); ?>
                <?php $locationFields($location); ?>
                <button>保存门店</button></form>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $searchedOrder = filter_var($_GET['pickup_order'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $pickupOrders = $app->merchantPickupQueue((int) $user['id'], $searchedOrder === false ? null : $searchedOrder);
    ?>
    <form method="get" action="/#pickup">
        <?= field('page', 'merchant') ?>
        <label>查找子订单<input type="number" name="pickup_order" min="1" value="<?= $searchedOrder === false ? '' : e($searchedOrder) ?>"></label>
        <button class="secondary">查询</button>
    </form>
    <?php if (!$pickupOrders): ?><p class="muted">暂无匹配的已付款自提单。</p><?php endif; ?>
    <?php foreach ($pickupOrders as $pickupOrder): ?>
        <?php $awaitingStock = $pickupOrder['status'] === 'ready' && $app->hasPendingPreorders((int) $pickupOrder['id']); ?>
        <div class="record">
            <div>
                <strong>子订单 #<?= e($pickupOrder['id']) ?> · <?= e($pickupOrder['location_name']) ?></strong>
                <?php if ($pickupOrder['used_at']): ?><?= badge('fulfilled') ?>
                <?php elseif ($pickupOrder['prepared_at']): ?><?= badge('pickup_prepared') ?>
                <?php elseif ($awaitingStock): ?><span class="badge">等待预售到货</span>
                <?php else: ?><?= badge('pickup_pending') ?><?php endif; ?>
                <p class="muted"><?= e($pickupOrder['location_address']) ?></p>
                <?php foreach ($app->rows('SELECT sku,name,quantity FROM order_items WHERE merchant_order_id=? ORDER BY id',
                    [$pickupOrder['id']]) as $line): ?>
                    <div><?= e($line['name']) ?> × <?= e($line['quantity']) ?> <span class="muted"><?= e($line['sku']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if ($pickupOrder['status'] === 'ready' && !$awaitingStock):
                form($pickupOrder['prepared_at'] ? 'pickup_redeem' : 'pickup_prepare');
                echo field('merchant_order', $pickupOrder['id']), field('pickup_location', $pickupOrder['pickup_location_id']); ?>
                <?php if ($pickupOrder['prepared_at']): ?>
                    <label>扫码或输入提货码<input name="code" maxlength="24" autocomplete="off" spellcheck="false" required></label>
                    <button>核销提货</button></form>
                <?php else: ?>
                    <button>备货完成</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
