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
                        <?php if (!empty($line['preorder_code'])): ?>
                            <div class="muted">预售 <?= e($line['preorder_code']) ?> · 预计 <?= e($line['expected_on']) ?> 到货</div>
                        <?php endif; ?>
                        <?php if (!$line['on_sale'] || $line['available'] < $line['quantity']): ?>
                            <div class="muted"><?= $line['on_sale'] ? (!empty($line['preorder_code']) ? '预售剩余额度不足' : '可用库存不足') : '暂停销售' ?></div>
                        <?php endif; ?>
                    </div>
                    <?php form('cart'); echo field('product', $line['product_id']); ?>
                    <?php if ($role === 'buyer'): ?>
                        <?= field('revision', $line['revision']) ?>
                        <label class="inline"><input type="checkbox" name="selected" value="1"<?= $line['selected'] ? ' checked' : '' ?>>参加结算</label>
                    <?php endif; ?>
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
            <?php if ($role === 'guest'): ?>
                <p>登录后，购物车会合并到买家账号。账号中已有的商品保留原数量和勾选状态。</p>
                <a class="button" href="/?page=account">登录或注册买家账号</a>
            <?php else: ?>
            <?php
            $savedQuote = $_SESSION['quote'] ?? null;
            if (($savedQuote['channel'] ?? null) === 'store' && !isset($savedQuote['pickup_locations'])) {
                $savedQuote = null;
            }
            $selected = array_filter($cartLines, static fn(array $line): bool => (bool) $line['selected']);
            $revisions = array_column($selected, 'revision', 'product_id');
            $quote = empty($savedQuote['requires_refresh']) && ($savedQuote['cart_revisions'] ?? null) === $revisions ? $savedQuote : null;
            $coupons = array_filter($app->buyerCoupons((int) $user['id']), static fn(array $coupon): bool => $coupon['status'] === 'available');
            $loyaltyPolicy = $app->loyaltyPolicy();
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
            <?php if (!empty($loyaltyPolicy['redeem_percent'])): ?>
                <?php $available = min(Commerce::MAX_POINTS_PER_ORDER, (int) $app->loyaltyAccount((int) $user['id'])['available']); ?>
                <label>积分抵扣（1 积分抵 1 分）<input type="number" min="0" max="<?= e($available) ?>" name="points"
                    value="<?= e(min($available, $savedQuote['loyalty']['spent'] ?? 0)) ?>"></label>
                <p class="muted">可用 <?= e($available) ?> 积分，最多抵扣商品金额的 <?= e($loyaltyPolicy['redeem_percent']) ?>%。</p>
            <?php endif; ?>
            <button class="secondary">计算费用</button></form>
            <?php if ($quote): ?>
                <?php if (isset($quote['previous'])): ?>
                    <?php
                    $previous = $quote['previous'];
                    $formatAllocation = static function (array $allocation): string {
                        ksort($allocation, SORT_NUMERIC);
                        $labels = [];
                        foreach ($allocation as $warehouse => $units) {
                            $labels[] = "仓 #$warehouse × $units";
                        }
                        return implode(' / ', $labels);
                    };
                    ?>
                    <table>
                        <caption>报价变更</caption>
                        <thead><tr><th scope="col">项目</th><th scope="col">原报价</th><th scope="col">新报价</th></tr></thead>
                        <tbody>
                            <?php foreach (['subtotal' => '商品小计', 'discount' => '优惠', 'shipping' => '运费', 'total' => '应付金额'] as $key => $label): ?>
                                <?php if ($key === 'total' || $previous[$key] !== $quote[$key]): ?>
                                    <tr><th scope="row"><?= e($label) ?></th><td><?= cash($previous[$key]) ?></td><td><?= cash($quote[$key]) ?></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php $oldPoints = $previous['loyalty']['spent'] ?? 0; $newPoints = $quote['loyalty']['spent'] ?? 0; ?>
                            <?php if ($oldPoints !== $newPoints): ?>
                                <tr><th scope="row">抵扣积分</th><td><?= e($oldPoints) ?></td><td><?= e($newPoints) ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($quote['items'] as $product => $item): ?>
                                <?php $oldItem = $previous['items'][$product]; ?>
                                <?php if ($oldItem['unit_price'] !== $item['unit_price']): ?>
                                    <tr><th scope="row"><?= e($item['name']) ?>单价</th><td><?= cash($oldItem['unit_price']) ?></td><td><?= cash($item['unit_price']) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($oldItem['allocation'] != $item['allocation']): ?>
                                    <tr><th scope="row"><?= e($item['name']) ?>分仓数量</th>
                                        <td><?= e($formatAllocation($oldItem['allocation'])) ?></td>
                                        <td><?= e($formatAllocation($item['allocation'])) ?></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <dl class="totals">
                    <dt>商品标价小计</dt><dd><?= cash($quote['subtotal'] + ($quote['member_savings'] ?? 0)) ?></dd>
                    <?php if ($quote['member_savings'] ?? 0): ?><dt>会员价优惠</dt><dd><?= cash(-$quote['member_savings']) ?></dd><?php endif; ?>
                    <?php if ($quote['discount']): ?><dt><?= e($quote['promotion']['name'] ?? '跨店优惠') ?></dt><dd><?= cash(-$quote['discount']) ?></dd><?php endif; ?>
                    <?php if (!empty($quote['loyalty']['spent'])): ?><dt>积分抵扣</dt><dd><?= cash(-$quote['loyalty']['spent']) ?></dd><?php endif; ?>
                    <dt>运费</dt><dd><?= cash($quote['shipping']) ?></dd>
                    <?php if (isset($quote['shops']) && $quote['channel'] === 'web'): ?>
                        <dt>发货仓数</dt><dd><?= e(array_sum(array_map(static fn(array $shop): int => count($shop['warehouses'] ?? []), $quote['shops']))) ?></dd>
                    <?php endif; ?>
                    <dt>应付金额</dt><dd><strong><?= cash($quote['total']) ?></strong></dd>
                </dl>
                <?php if (!empty($quote['loyalty']['earned'])): ?><p class="muted">支付后预计赠 <?= e($quote['loyalty']['earned']) ?> 积分。</p><?php endif; ?>
                <?php if ($quote['channel'] === 'store'): ?>
                    <ul class="muted">
                        <?php foreach ($quote['shops'] as $totals): ?>
                            <li><?= e($totals['pickup']['name']) ?> · <?= e($totals['pickup']['address']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php foreach ($quote['items'] as $item): ?>
                    <?php if (isset($item['preorder'])): ?>
                        <p class="muted"><?= e($item['name']) ?> · 预售 <?= e($item['preorder']['code']) ?>
                            · 预计 <?= e($item['preorder']['expected_on']) ?> 到货</p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php form('place'); echo field('token', $quote['token']); ?>
                <button><?= isset($quote['previous']) ? '确认新报价并下单' : '确认订单' ?></button></form>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
