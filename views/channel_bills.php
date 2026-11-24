<?php
$billId = filter_var($_GET['bill'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$before = filter_var($_GET['before'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$states = [
    'queued' => '待核对', 'invalid' => '格式错误', 'duplicate' => '重复行', 'conflict' => '流水冲突',
    'awaiting_receipt' => '待补回执', 'matched' => '已匹配', 'mismatch' => '存在差异',
    'receipt_exception' => '本地收款待处理', 'outside_shop' => '商户归属不符', 'reviewed' => '已核对',
];
$reasons = [
    'invalid_fields' => '请按模板检查列数、订单号、流水、币种和金额',
    'repeated_reference' => '同一文件内重复使用该流水',
    'reference_not_found' => '暂未找到可核对的本地回执',
    'order_mismatch' => '本地回执对应的订单号不同',
    'amount_or_currency_mismatch' => '金额或币种与本地回执不符',
    'payment_receipt_exception' => '本地收款回执已进入异常处理',
    'merchant_order_missing' => '该母单没有本店子单',
];
$kinds = ['capture' => '收款', 'return_refund' => '退货退款', 'exception_refund' => '异常实收退回'];
try {
    if ($billId) {
        $bill = $app->channelBillDetails($id, $billId);
    } else {
        $bills = $app->channelBillList($id, $before);
    }
} catch (RuleViolation $error) {
    http_response_code(403);
    echo '<p class="flash error" role="alert">', e($error->getMessage()), '</p>';
    return;
}
?>
<div class="heading"><h1><?= $role === 'finance' ? '渠道对账' : '渠道回执上传' ?></h1></div>
<?php if ($billId):
    $counts = array_count_values(array_column($bill['rows'], 'status')); ?>
    <section class="panel">
        <div class="heading">
            <h2>账单任务 #<?= e($billId) ?></h2>
            <a href="/?page=channel_bills">返回任务列表</a>
        </div>
        <p><?= e($bill['shop_name'] ?? '平台账单') ?> · <?= e($bill['created_at']) ?> UTC · 共 <?= e($bill['row_count']) ?> 条</p>
        <p class="inline">
            <?php foreach ($states as $state => $label): ?>
                <?php if (isset($counts[$state])): ?><span><?= e($label) ?> <?= e($counts[$state]) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </p>
        <?php if (!empty($counts['queued'])): ?>
            <p><a href="/?page=channel_bills&amp;bill=<?= e($billId) ?>">刷新处理进度</a></p>
        <?php endif; ?>
        <?php if ($role === 'finance' && !empty($counts['awaiting_receipt'])):
            form('bill_retry'); echo field('bill', $billId); ?>
            <button class="secondary">重新核对待补回执</button></form>
        <?php endif; ?>
        <div class="table-wrap"><table>
            <thead><tr><th>记录序号</th><th>业务</th><th>母单</th><th>流水</th><th>币种</th><th>金额（分）</th><th>处理结果</th></tr></thead>
            <tbody><?php foreach ($bill['rows'] as $row): ?>
                <tr>
                    <td><?= e($row['line_no']) ?></td>
                    <td><?= e($kinds[$row['kind']] ?? '—') ?></td>
                    <td><?= e($row['order_id'] ?? '—') ?></td>
                    <td><?= e($row['reference'] ?? '—') ?><?php if ($row['reference'] !== null): ?>
                        <small><?= e($row['provider']) ?></small>
                    <?php endif; ?></td>
                    <td><?= e($row['currency'] ?? '—') ?></td>
                    <td><?= e($row['amount'] ?? '—') ?></td>
                    <td>
                        <?= e($states[$row['status']]) ?>
                        <?php if ($row['result_code']): ?><div class="muted"><?= e($reasons[$row['result_code']]) ?></div><?php endif; ?>
                        <?php if ($row['local_id']): ?>
                            <?php $receiptField = $row['kind'] === 'return_refund' ? 'return_receipt' : 'receipt'; ?>
                            <a href="/?page=platform&amp;<?= e($receiptField) ?>=<?= e($row['local_id']) ?>#<?= $row['kind'] === 'return_refund'
                                ? 'return-refunds' : 'receipts' ?>">本地回执 #<?= e($row['local_id']) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </section>
    <?php return;
endif;
$hasMoreBills = count($bills) > 50;
if ($hasMoreBills) {
    array_pop($bills);
}
?>
<section class="panel">
    <h2>导入账单</h2>
    <form method="post" action="/" enctype="multipart/form-data">
        <?= field('csrf', $_SESSION['csrf']), field('action', 'bill_import') ?>
        <label>渠道 CSV 文件<input type="file" name="bill" accept=".csv,text/csv" required></label>
        <p>使用 UTF-8，每次最多 1,000 条、256 KiB。金额填写整数分，订单号填写母单号。</p>
        <div class="inline"><button>上传并排队</button><a href="/channel-bill-sample.csv" download>下载 CSV 模板</a></div>
    </form>
</section>
<section class="panel">
    <h2>导入任务</h2>
    <?php if (!$bills): ?><p class="muted">暂无账单任务。</p><?php endif; ?>
    <?php foreach ($bills as $bill): ?>
        <div class="record">
            <a href="/?page=channel_bills&amp;bill=<?= e($bill['id']) ?>">#<?= e($bill['id']) ?> · <?= e($bill['shop_name'] ?? '平台账单') ?></a>
            <span><?= e($bill['row_count']) ?> 条 · <?= e($bill['created_at']) ?> UTC</span>
        </div>
    <?php endforeach; ?>
    <?php if ($hasMoreBills): ?>
        <a href="/?page=channel_bills&amp;before=<?= e(end($bills)['id']) ?>">下一页</a>
    <?php endif; ?>
</section>
