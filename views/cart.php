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
            $selected = array_filter($cartLines, static fn(array $line): bool => (bool) $line['selected']);
            $revisions = array_column($selected, 'revision', 'product_id');
            $quote = ($savedQuote['cart_revisions'] ?? null) === $revisions ? $savedQuote : null;
            form('quote');
            ?>
            <label>履约方式<select name="channel">
                <option value="web">快递配送</option>
                <option value="store"<?= ($savedQuote['channel'] ?? '') === 'store' ? ' selected' : '' ?>>门店自提</option>
            </select></label>
            <label>配送地址或自提点<input name="destination" value="<?= e($savedQuote['destination'] ?? '') ?>" maxlength="80" required></label>
            <button class="secondary">计算费用</button></form>
            <?php if ($quote): ?>
                <dl class="totals">
                    <dt>商品小计</dt><dd><?= cash($quote['subtotal']) ?></dd>
                    <dt>跨店优惠</dt><dd><?= cash(-$quote['discount']) ?></dd>
                    <dt>运费</dt><dd><?= cash($quote['shipping']) ?></dd>
                    <dt>应付金额</dt><dd><strong><?= cash($quote['total']) ?></strong></dd>
                </dl>
                <?php form('place'); echo field('token', $quote['token']); ?>
                <button>确认订单</button></form>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
