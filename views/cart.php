<div class="heading"><h1>购物车</h1><a href="/">继续选购</a></div>
<?php if (!$cart): ?>
    <p class="panel">购物车为空。</p>
<?php else: ?>
    <div class="two">
        <section class="panel">
            <h2>已选商品</h2>
            <?php foreach ($cart as $product => $qty):
                $p = $app->rows('SELECT p.name,p.price,p.status,s.name AS shop_name FROM products p
                    JOIN shops s ON s.id=p.shop_id WHERE p.id=? AND p.status=\'active\' AND s.status=\'active\'', [$product])[0] ?? null;
                ?>
                <div class="record">
                    <div><strong><?= e($p['name'] ?? '不可用商品') ?></strong>
                        <div class="muted"><?= e($p['shop_name'] ?? '') ?> · <?= cash($p['price'] ?? 0) ?></div></div>
                    <?php form('cart'); echo field('product', $product); ?>
                    <div class="inline">
                        <input type="number" min="0" max="99" name="quantity" value="<?= $qty ?>" aria-label="购买数量，填 0 移除" required>
                        <button class="secondary">更新</button>
                        <button class="secondary" name="remove" value="1" formnovalidate>移除</button>
                    </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="panel">
            <h2>结算</h2>
            <?php $quote = $_SESSION['quote'] ?? null; form('quote'); ?>
            <label>履约方式<select name="channel">
                <option value="web">快递配送</option>
                <option value="store"<?= ($quote['channel'] ?? '') === 'store' ? ' selected' : '' ?>>门店自提</option>
            </select></label>
            <label>配送地址或自提点<input name="destination" value="<?= e($quote['destination'] ?? '') ?>" maxlength="80" required></label>
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
