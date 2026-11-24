<div class="heading"><h1>购物车</h1><a href="/">继续选购</a></div>
<?php if (!$cartLines): ?>
    <p class="panel">购物车为空。</p>
<?php else: ?>
    <div class="two">
        <section class="panel">
            <h2>购物车商品</h2>
            <?php foreach ($cartLines as $line): ?>
                <div class="record">
                    <div><strong><?= e($line['name']) ?></strong>
                        <div class="muted"><?= e($line['shop_name']) ?> · <?= e($line['sku']) ?>
                            <?= e(trim((string) $line['color'] . ' ' . (string) $line['size'])) ?> · <?= cash($line['price']) ?></div>
                        <?php if (!$line['on_sale'] || $line['available'] < $line['quantity']): ?>
                            <div class="muted"><?= $line['on_sale'] ? '可用库存不足' : '暂停销售' ?></div>
                        <?php endif; ?>
                    </div>
                    <?php form('cart'); echo field('product', $line['product_id']), field('revision', $line['revision']); ?>
                    <label class="inline"><input type="checkbox" name="selected" value="1"<?= $line['selected'] ? ' checked' : '' ?>>参加结算</label>
                    <div class="inline">
                        <input type="number" min="0" max="99" name="quantity" value="<?= e($line['quantity']) ?>" aria-label="购买数量，填 0 移除" required>
                        <button class="secondary">更新</button>
                        <button class="secondary" name="remove" value="1" formnovalidate>移除</button>
                    </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="panel">
            <h2>结算</h2>
            <?php
            $savedQuote = $_SESSION['quote'] ?? null;
            if (($savedQuote['channel'] ?? null) === 'store' && !isset($savedQuote['pickup_locations'])) {
                $savedQuote = null;
            }
            $selected = array_filter($cartLines, static fn(array $line): bool => (bool) $line['selected']);
            $revisions = array_column($selected, 'revision', 'product_id');
            $quote = ($savedQuote['cart_revisions'] ?? null) === $revisions ? $savedQuote : null;
            $coupons = array_filter($app->buyerCoupons((int) $user['id']), static fn(array $coupon): bool => $coupon['status'] === 'available');
            $pickupOptions = [];
            foreach ($app->checkoutPickupLocations((int) $user['id']) as $location) {
                $pickupOptions[$location['shop_id']][] = $location;
            }
            form('quote');
            ?>
            <label>履约方式<select name="channel">
                <option value="web">快递配送</option>
                <option value="store"<?= ($savedQuote['channel'] ?? '') === 'store' ? ' selected' : '' ?>>门店自提</option>
            </select></label>
            <label>配送地址<input name="destination" value="<?= e(($savedQuote['channel'] ?? '') === 'web' ? $savedQuote['destination'] : '') ?>"
                maxlength="80" placeholder="快递配送时填写"></label>
            <?php foreach (array_column($selected, 'shop_name', 'shop_id') as $shop => $name): ?>
                <label><?= e($name) ?>自提门店
                    <select name="pickup_locations[<?= e($shop) ?>]">
                        <option value=""><?= isset($pickupOptions[$shop]) ? '门店自提时选择' : '暂无可选门店' ?></option>
                        <?php foreach ($pickupOptions[$shop] ?? [] as $location): ?>
                            <option value="<?= e($location['id']) ?>"<?= ($savedQuote['pickup_locations'][$shop] ?? null) === $location['id'] ? ' selected' : '' ?>>
                                <?= e($location['name']) ?> · <?= e($location['address']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
            <?php if ($coupons): ?>
                <label>优惠券<select name="coupon">
                    <option value="">系统匹配可用活动</option>
                    <?php foreach ($coupons as $coupon): ?>
                        <option value="<?= e($coupon['id']) ?>"<?= ($savedQuote['coupon'] ?? null) === (int) $coupon['id'] ? ' selected' : '' ?>>
                            <?= e($coupon['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <button class="secondary">计算费用</button></form>
            <?php if ($quote): ?>
                <dl class="totals">
                    <dt>商品标价小计</dt><dd><?= cash($quote['subtotal'] + ($quote['member_savings'] ?? 0)) ?></dd>
                    <?php if ($quote['member_savings'] ?? 0): ?><dt>会员价优惠</dt><dd><?= cash(-$quote['member_savings']) ?></dd><?php endif; ?>
                    <?php if ($quote['discount']): ?><dt><?= e($quote['promotion']['name'] ?? '跨店优惠') ?></dt><dd><?= cash(-$quote['discount']) ?></dd><?php endif; ?>
                    <dt>运费</dt><dd><?= cash($quote['shipping']) ?></dd>
                    <?php if (isset($quote['shops']) && $quote['channel'] === 'web'): ?>
                        <dt>发货仓数</dt><dd><?= e(array_sum(array_map(static fn(array $shop): int => count($shop['warehouses'] ?? []), $quote['shops']))) ?></dd>
                    <?php endif; ?>
                    <dt>应付金额</dt><dd><strong><?= cash($quote['total']) ?></strong></dd>
                </dl>
                <?php if ($quote['channel'] === 'store'): ?>
                    <ul class="muted">
                        <?php foreach ($quote['shops'] as $totals): ?>
                            <li><?= e($totals['pickup']['name']) ?> · <?= e($totals['pickup']['address']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php form('place'); echo field('token', $quote['token']); ?>
                <button>确认订单</button></form>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
