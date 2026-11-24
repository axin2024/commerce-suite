<?php
$categories = $app->categories();
$products = $app->merchantCatalog((int) $user['id']);
$releaseStates = ['legacy' => '旧版导入', 'pending' => '待审核', 'approved' => '通过', 'rejected' => '拒绝', 'withdrawn' => '已撤回'];
$skuForm = static function (?array $product) use ($categories): void {
    $current = $product['proposed_price'] ?? $product['price'] ?? null;
    $selectedCategory = $product['category_id'] ?? null;
    form('sku_submit');
    echo field('product', $product['id'] ?? 0), field('revision', $product['latest_id'] ?? 0);
    if ($product) {
        echo field('sku', $product['sku']), field('stock', 0);
    }
    ?>
    <div class="inputs">
        <?php if (!$product): ?>
            <label>SKU<input name="sku" required maxlength="64" pattern="[A-Za-z0-9_\-]+"></label>
            <label>初始在库<input type="number" name="stock" value="0" min="0" max="1000000000" required></label>
        <?php endif; ?>
        <label>名称<input name="name" value="<?= e($product['proposed_name'] ?? $product['name'] ?? '') ?>" required maxlength="160"></label>
        <label>系列<input name="family" value="<?= e($product['family_name'] ?? '') ?>" required maxlength="160"></label>
        <label>分类<select name="category" required>
            <option value="">请选择</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category['id']) ?>"<?= $selectedCategory === $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>颜色<input name="color" value="<?= e($product['color'] ?? '') ?>" maxlength="40"></label>
        <label>尺码或规格<input name="size" value="<?= e($product['size'] ?? '') ?>" maxlength="40"></label>
        <label>售价（元）<input name="price" value="<?= e($current === null ? '' : number_format($current / 100, 2, '.', '')) ?>" inputmode="decimal" required></label>
    </div>
    <div class="inline">
        <label class="inline"><input type="checkbox" name="delivery" value="1"<?= ($product['delivery'] ?? 1) ? ' checked' : '' ?>>配送</label>
        <label class="inline"><input type="checkbox" name="pickup" value="1"<?= ($product['pickup'] ?? 1) ? ' checked' : '' ?>>门店自提</label>
    </div>
    <button>提交发布审核</button></form>
    <?php
};
?>
<section class="panel" id="products">
    <h2>商品与库存</h2>
    <details><summary>新增商品</summary>
        <?php if ($categories): $skuForm(null); else: ?><p class="muted">请先由平台添加商品分类。</p><?php endif; ?>
    </details>
    <?php foreach ($products as $product): ?>
        <details class="inventory">
            <summary><?= e($product['name']) ?> · <?= e($product['sku']) ?> · <?= cash($product['price']) ?> · <?= e([
                'active' => '在售', 'draft' => '待发布', 'archived' => '已下架',
            ][$product['status']]) ?>
                <span class="muted">在库 <?= e($product['stock']) ?> / 预留 <?= e($product['reserved']) ?></span>
            </summary>
            <?php if ($product['latest_id']): ?>
                <p class="muted">最新版本 #<?= e($product['latest_id']) ?> · <?= e($releaseStates[$product['review_state']]) ?>
                    <?php if ($product['review_reason']): ?> · <?= e($product['review_reason']) ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php $skuForm($product); ?>
            <?php form('sku_stock'); echo field('product', $product['id']), field('version', $product['version']); ?>
            <div class="inline">
                <label>在库总量<input name="stock" type="number" min="<?= e($product['reserved']) ?>" max="1000000000" value="<?= e($product['stock']) ?>" required></label>
                <button class="secondary">调整库存</button>
            </div>
            </form>
            <?php if ($product['status'] === 'active' || $product['review_state'] === 'pending'):
                form('sku_unlist'); echo field('product', $product['id']); ?>
                <button class="secondary"><?= $product['status'] === 'active' ? '立即下架' : '撤回待审版本' ?></button></form>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>
