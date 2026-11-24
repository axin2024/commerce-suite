<?php
$shopFilter = filter_var($_GET['shop'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$batchId = filter_var($_GET['batch'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : '';
$before = filter_var($_GET['before'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$after = filter_var($_GET['after'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$showEntries = $role !== 'payout';
?>
<div class="heading"><h1><?= $role === 'payout' ? '打款登记' : '结算中心' ?></h1></div>
<?php if ($batchId):
    try {
        $settlement = $app->settlementDetail($id, $batchId);
        $entries = $showEntries ? $app->settlementEntries($id, $batchId, $after) : [];
    } catch (RuleViolation $error) {
        http_response_code(403);
        echo '<p class="flash error" role="alert">', e($error->getMessage()), '</p>';
        return;
    }
    $hasMoreEntries = count($entries) > 100;
    if ($hasMoreEntries) {
        array_pop($entries);
    }
    ?>
    <section class="panel">
        <div class="heading">
            <h2>结算单 #<?= e($batchId) ?> · <?= e($settlement['shop_name']) ?>
                <?= badge($settlement['state'] === 'paid' ? 'transfer_paid' : $settlement['state']) ?></h2>
            <a href="/?page=settlements">返回结算列表</a>
        </div>
        <p>月份 <?= e($settlement['period'] ? substr($settlement['period'], 0, 7) : '历史批次') ?>
            · 币种 <?= e($settlement['currency']) ?> · 应结 <strong><?= cash($settlement['amount']) ?></strong></p>
        <p>收款主体 <?= e($settlement['payee_name'] ?? '未登记') ?> · 协议 <?= e($settlement['agreement_version'] ?? '未登记') ?></p>
        <p>冻结 <?= e($settlement['created_at']) ?> UTC
            <?php if ($settlement['reviewed_at']): ?> · 复核 <?= e($settlement['reviewed_at']) ?> UTC<?php endif; ?></p>
        <?php if ($showEntries): ?>
            <p>销售 <?= cash($settlement['sales']) ?> · 退款 <?= cash($settlement['refunds']) ?>
                · 净佣金 <?= cash($settlement['commission']) ?> · 平台承担运费 <?= cash($settlement['platform_freight']) ?>
                · 平台积分出资 <?= cash($settlement['platform_points']) ?> · <?= e($settlement['entries']) ?> 笔</p>
        <?php endif; ?>
        <?php if ($settlement['paid_at']): ?>
            <p>银行流水 <code><?= e($settlement['transfer_reference']) ?></code> · 登记 <?= e($settlement['paid_at']) ?> UTC</p>
        <?php endif; ?>
        <?php if ($role === 'finance' && $settlement['state'] === 'review_pending'):
            form('settlement_review'); echo field('batch', $batchId); ?>
            <label>复核金额（分）<input name="verified_amount" inputmode="numeric" pattern="[0-9]+" required></label>
            <button>确认复核</button></form>
        <?php elseif ($role === 'payout' && $settlement['state'] === 'payable'):
            form('settlement_transfer'); echo field('batch', $batchId); ?>
            <label>银行流水号<input name="reference" maxlength="80" required></label>
            <label>实付金额（分）<input name="transfer_amount" inputmode="numeric" pattern="[0-9]+" required></label>
            <button>登记已完成打款</button></form>
        <?php endif; ?>
    </section>
    <?php if ($showEntries): ?>
        <section class="panel">
            <div class="heading"><h2>冻结分录</h2>
                <a href="/?page=settlements&amp;batch=<?= e($batchId) ?>&amp;download=csv">下载 CSV</a></div>
            <div class="table-wrap"><table>
                <thead><tr><th>源事件</th><th>子订单</th><th>现金</th><th>佣金</th><th>平台承担运费</th><th>积分出资</th><th>净额</th><th>入账时间 UTC</th></tr></thead>
                <tbody><?php foreach ($entries as $entry): ?>
                    <tr><td><?= e($entry['event_key']) ?></td><td>#<?= e($entry['merchant_order_id']) ?></td>
                        <td><?= cash($entry['gross']) ?></td><td><?= cash($entry['commission']) ?></td>
                        <td><?= cash($entry['platform_freight']) ?></td><td><?= cash($entry['platform_points']) ?></td>
                        <td><?= cash($entry['net']) ?></td><td><?= e($entry['created_at']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if ($hasMoreEntries): ?>
                <a href="/?page=settlements&amp;batch=<?= e($batchId) ?>&amp;after=<?= e(end($entries)['id']) ?>">下一页</a>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php return;
endif;
$preview = null;
$previewError = null;
try {
    $batches = $app->settlementList($id, $shopFilter, $period, $before);
} catch (RuleViolation $error) {
    http_response_code(403);
    echo '<p class="flash error" role="alert">', e($error->getMessage()), '</p>';
    return;
}
if ($role === 'finance' && $shopFilter && $period !== '') {
    try {
        $preview = $app->previewSettlement($id, $shopFilter, $period, 'CNY');
    } catch (RuleViolation $error) {
        $previewError = $error->getMessage();
    }
}
$hasMoreBatches = count($batches) > 50;
if ($hasMoreBatches) {
    array_pop($batches);
}
?>
<section class="panel">
    <form method="get" action="/">
        <?= field('page', 'settlements') ?>
        <div class="inputs">
            <?php if ($role !== 'merchant'): ?>
                <label>店铺编号<input name="shop" type="number" min="1" value="<?= $shopFilter ?: '' ?>"></label>
            <?php endif; ?>
            <label>结算月份（UTC）<input name="period" type="month" max="<?= e(gmdate('Y-m')) ?>" value="<?= e($period) ?>"></label>
        </div>
        <button><?= $role === 'finance' ? '查询并预览' : '查询结算单' ?></button>
    </form>
</section>
<?php if ($previewError): ?><p class="flash error"><?= e($previewError) ?></p><?php endif; ?>
<?php if ($preview): ?>
<section class="panel">
    <h2>结算预览 · <?= e($preview['shop_name']) ?> · <?= e($preview['month']) ?> · <?= e($preview['currency']) ?></h2>
    <p>销售 <?= cash($preview['sales']) ?> · 退款 <?= cash($preview['refunds']) ?> · 净佣金 <?= cash($preview['commission']) ?>
        · 平台承担运费 <?= cash($preview['platform_freight']) ?> · 平台积分出资 <?= cash($preview['platform_points']) ?>
        · 待结净额 <strong><?= cash($preview['net']) ?></strong>
        · <?= e($preview['entries']) ?> 笔</p>
    <?php if ($preview['blocked']): ?><p><?= e($preview['blocked']) ?></p>
    <?php else:
        form('settlement_freeze');
        echo field('shop', $shopFilter), field('period', $preview['month']), field('currency', $preview['currency']),
            field('preview_hash', $preview['hash']); ?>
        <p>冻结后，该月份的结算单保持原明细，后续入账分录结转。</p>
        <button>冻结并提交复核</button></form>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="panel">
    <h2><?= $role === 'payout' ? '已复核结算单' : '结算单' ?></h2>
    <?php if (!$batches): ?><p class="muted">暂无符合条件的结算单。</p><?php endif; ?>
    <?php foreach ($batches as $batch): ?>
        <div class="record">
            <div><a href="/?page=settlements&amp;batch=<?= e($batch['id']) ?>">#<?= e($batch['id']) ?> · <?= e($batch['shop_name']) ?></a>
                <span><?= e($batch['period'] ? substr($batch['period'], 0, 7) : '历史批次') ?> · <?= e($batch['currency']) ?></span>
                <?= badge($batch['state'] === 'paid' ? 'transfer_paid' : $batch['state']) ?></div>
            <strong><?= cash($batch['amount']) ?></strong>
        </div>
    <?php endforeach; ?>
    <?php if ($hasMoreBatches): ?>
        <a href="/?<?= e(http_build_query(['page' => 'settlements', 'shop' => $shopFilter, 'period' => $period,
            'before' => end($batches)['id']])) ?>">下一页</a>
    <?php endif; ?>
</section>
