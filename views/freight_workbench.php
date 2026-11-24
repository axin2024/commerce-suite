<?php
$freightHistory = $app->merchantFreightHistory($id);
$currentFreight = $freightHistory[0] ?? null;
$freightLabels = $app->freightStrategyLabels();
?>
<section class="panel" id="freight">
    <h2>运费规则</h2>
    <?php if ($currentFreight === null): ?><p>当前按发货仓计费，每仓 ¥8.00。</p><?php endif; ?>
    <?php form('freight_publish'); echo field('revision', $currentFreight['id'] ?? 0); ?>
    <div class="inputs">
        <label>计费方式<select name="strategy">
            <?php foreach ($freightLabels as $strategy => $label): ?>
                <option value="<?= e($strategy) ?>"<?= ($currentFreight['strategy'] ?? FreightStrategies::DEFAULT) === $strategy ? ' selected' : '' ?>>
                    <?= e($label) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>基础运费（元）<input type="number" name="base_fee" min="0" max="10000" step="0.01"
            value="<?= e(number_format(($currentFreight['base_fee'] ?? FreightStrategies::DEFAULT_FEE) / 100, 2, '.', '')) ?>" required></label>
        <label>本店包邮门槛（元）<input type="number" name="free_threshold" min="0.01" step="0.01" placeholder="留空则不设门槛"
            value="<?= isset($currentFreight['free_threshold']) ? e(number_format($currentFreight['free_threshold'] / 100, 2, '.', '')) : '' ?>"></label>
    </div>
    <p class="muted">按本店商品券后、积分抵扣前金额计算包邮。门店自提免运费。</p>
    <button>发布运费规则</button></form>
    <?php if ($freightHistory): ?>
        <h3>最近发布</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>版本</th><th>计费方式</th><th>基础运费</th><th>包邮门槛</th><th>发布时间（UTC）</th></tr></thead>
            <tbody><?php foreach ($freightHistory as $entry): ?>
                <tr><th scope="row">#<?= e($entry['id']) ?></th><td><?= e($freightLabels[$entry['strategy']] ?? $entry['strategy']) ?></td>
                    <td><?= cash($entry['base_fee']) ?></td><td><?= $entry['free_threshold'] === null ? '—' : cash($entry['free_threshold']) ?></td>
                    <td><?= e($entry['published_at']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
