<?php
$merchant = (int) $user['id'];
$channels = $app->catalogChannels($merchant);
$channelId = filter_var($_GET['channel'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$after = filter_var($_GET['after'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$credential = $_SESSION['catalog_credential'] ?? null;
unset($_SESSION['catalog_credential']);
?>
<div class="heading"><h1>外部销售渠道</h1><a href="/?page=merchant">返回商家中心</a></div>
<section class="panel">
    <h2>新建渠道</h2>
    <?php form('catalog_channel_create'); ?>
        <label>渠道代码 <input name="code" required maxlength="32" pattern="[a-z][a-z0-9_\-]{0,31}" placeholder="partner-shop"></label>
        <label>渠道名称 <input name="label" required maxlength="80" placeholder="合作方商城"></label>
        <button>新建并签发密钥</button>
    </form>
    <h2>本店渠道</h2>
    <?php if (!$channels): ?><p class="muted">本店还没有外部渠道。</p><?php endif; ?>
    <ul>
    <?php foreach ($channels as $item): ?>
        <li><a href="/?page=sales_channels&amp;channel=<?= e($item['id']) ?>"><?= e($item['label']) ?></a>
            <code><?= e($item['code']) ?></code> · <?= $item['revoked_at'] === null ? '可访问' : '已撤销' ?></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php
if (!$channelId) {
    return;
}
try {
    [$channel, $listings] = $app->catalogChannelListings($merchant, $channelId, $after);
} catch (RuleViolation $error) {
    http_response_code(403);
    echo '<p class="flash error" role="alert">', e($error->getMessage()), '</p>';
    return;
}
$active = $channel['revoked_at'] === null;
$nextPage = count($listings) > 50;
if ($nextPage) {
    array_pop($listings);
}
?>
<section class="panel">
    <h2><?= e($channel['label']) ?> · <code><?= e($channel['code']) ?></code></h2>
    <?php if ($active): ?>
        <p>读取接口 <code>GET /catalog-feed.php</code>，请求头使用 <code>Authorization: Bearer …</code>。</p>
        <?php if ($credential !== null && $credential['id'] === $channelId): ?>
            <p class="flash" role="status">请保存密钥，刷新后将隐藏。
                <code class="catalog-credential"><?= e($credential['token']) ?></code></p>
        <?php endif; ?>
        <p class="muted">轮换会立即使旧密钥失效。撤销后该渠道将无法恢复访问。</p>
        <div class="inline">
            <?php form('catalog_channel_rotate'); echo field('channel', $channelId), field('generation', $channel['generation']); ?>
                <button class="secondary">轮换密钥</button>
            </form>
            <details>
                <summary>撤销渠道</summary>
                <?php form('catalog_channel_revoke'); echo field('channel', $channelId), field('generation', $channel['generation']); ?>
                    <button class="secondary">确认撤销</button>
                </form>
            </details>
        </div>
        <hr>
        <h3>加入商品</h3>
        <?php form('catalog_channel_listing');
        echo field('channel', $channelId), field('enabled', 1), field('revision', 0); ?>
            <label>本店在售配送 SKU <input name="sku" required maxlength="64" placeholder="NORTH-01"></label>
            <button>加入渠道</button>
        </form>
    <?php else: ?>
        <p>该渠道已撤销，以下商品关联保留供核对。</p>
    <?php endif; ?>
    <h3>渠道商品</h3>
    <?php if (!$listings): ?><p class="muted">没有更多商品。</p><?php endif; ?>
    <?php foreach ($listings as $listing): ?>
        <div class="record">
            <span><?= e($listing['name']) ?> · <code><?= e($listing['sku']) ?></code>
                 · <?= $listing['enabled'] ? '已选入' : '已移除' ?></span>
            <?php if ($active): ?>
                <?php form('catalog_channel_listing'); echo field('channel', $channelId), field('sku', $listing['sku']),
                    field('enabled', $listing['enabled'] ? 0 : 1), field('revision', $listing['revision']); ?>
                    <button class="secondary"><?= $listing['enabled'] ? '从渠道移除' : '恢复到渠道' ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($after > 0): ?><a href="/?page=sales_channels&amp;channel=<?= e($channelId) ?>">返回第一页</a><?php endif; ?>
    <?php if ($nextPage): ?>
        <a href="/?page=sales_channels&amp;channel=<?= e($channelId) ?>&amp;after=<?= e(end($listings)['id']) ?>">下一页</a>
    <?php endif; ?>
</section>
