<?php
$categories = $app->categories();
$pending = $app->rows("SELECT r.*,p.sku,p.release_id,s.name AS shop_name,c.name AS category_name,f.name AS family_name
    FROM product_releases r JOIN products p ON p.id=r.product_id JOIN shops s ON s.id=p.shop_id
    JOIN product_categories c ON c.id=r.category_id JOIN product_families f ON f.id=r.family_id
    WHERE r.state='pending' ORDER BY r.id LIMIT 100");
?>
<section class="panel" id="catalog-review">
    <h2>商品发布审核</h2>
    <?php form('catalog_category'); ?>
    <div class="inline">
        <label>新分类<input name="name" maxlength="80" required></label>
        <button class="secondary">添加分类</button>
    </div>
    </form>
    <?php if ($categories): ?><p class="muted"><?= e(implode(' · ', array_column($categories, 'name'))) ?></p><?php endif; ?>
    <?php if (!$pending): ?><p class="muted">暂无待审商品版本。</p><?php endif; ?>
    <?php foreach ($pending as $proposal): ?>
        <div class="record">
            <div>
                <strong><?= e($proposal['shop_name']) ?> · <?= e($proposal['sku']) ?> · <?= e($proposal['name']) ?> · <?= cash($proposal['price']) ?></strong>
                <?php if ($proposal['member_price'] !== null): ?><span>会员价 <?= cash($proposal['member_price']) ?></span><?php endif; ?>
                <p><?= e($proposal['category_name']) ?> / <?= e($proposal['family_name']) ?>
                    <?= e(trim($proposal['color'] . ' ' . $proposal['size'])) ?> ·
                    <?= e(implode('、', array_filter([$proposal['delivery'] ? '配送' : '', $proposal['pickup'] ? '门店自提' : '']))) ?></p>
                <p class="muted">待审版本 #<?= e($proposal['id']) ?>
                    <?php if ($proposal['release_id']): ?> · 在售记录版本 #<?= e($proposal['release_id']) ?><?php endif; ?>
                </p>
            </div>
            <?php form('sku_review'); echo field('release', $proposal['id']); ?>
            <label>处理依据<input name="reason" maxlength="250" placeholder="拒绝时必填"></label>
            <div class="inline">
                <button name="decision" value="approve">通过并发布</button>
                <button class="secondary" name="decision" value="reject">拒绝</button>
            </div></form>
        </div>
    <?php endforeach; ?>
</section>
