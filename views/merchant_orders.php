<?php
$from = $_GET['from'] ?? gmdate('Y-m-d', time() - 29 * 86400);
$through = $_GET['through'] ?? gmdate('Y-m-d');
$before = $_GET['before'] ?? '';
$history = null;
$searchError = null;
try {
    if (!is_string($from) || !is_string($through) || !is_string($before)) {
        throw new RuleViolation('订单日期或翻页位置无效，请重新查询。');
    }
    $history = $app->merchantOrderHistory($id, $from, $through, $before);
} catch (RuleViolation $error) {
    http_response_code(400);
    $searchError = $error->getMessage();
}
?>
<div class="heading"><h1>店铺订单</h1><a href="/?page=merchant">返回商家中心</a></div>
<section class="panel">
    <form method="get" action="/" class="inputs">
        <?= field('page', 'merchant_orders') ?>
        <label>下单日期从（UTC）<input type="date" name="from" value="<?= e(is_string($from) ? $from : '') ?>" required></label>
        <label>至（UTC）<input type="date" name="through" value="<?= e(is_string($through) ? $through : '') ?>" required></label>
        <button>查询订单</button>
    </form>
    <?php if ($searchError): ?>
        <p class="flash error" role="alert"><?= e($searchError) ?></p>
    <?php elseif (!$history['rows']): ?>
        <p class="muted">所选范围内没有订单。</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>子订单</th><th>母订单</th><th>下单时间（UTC）</th><th>渠道</th><th>状态</th><th>金额</th><th>运费</th></tr></thead>
            <tbody>
                <?php foreach ($history['rows'] as $entry): ?>
                    <tr><th scope="row">#<?= e($entry['id']) ?></th><td>#<?= e($entry['order_id']) ?></td>
                        <td><?= e($entry['placed_at']) ?></td><td><?= $entry['channel'] === 'web' ? '快递配送' : '门店自提' ?></td>
                        <td><?= badge($entry['status']) ?></td><td><?= cash($entry['total']) ?></td><td><?= cash($entry['shipping']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if (!$searchError && ($before !== '' || $history['next'] !== null)): ?>
        <?php $filters = ['page' => 'merchant_orders', 'from' => $from, 'through' => $through]; ?>
        <div class="inline">
            <?php if ($before !== ''): ?><a href="/?<?= e(http_build_query($filters)) ?>">第一页</a><?php endif; ?>
            <?php if ($history['next'] !== null): ?><a href="/?<?= e(http_build_query($filters + ['before' => $history['next']])) ?>">下一页</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
