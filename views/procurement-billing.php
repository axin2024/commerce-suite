<?php
$companyId = (int)$account['id'];
$invoiceLines = $app->procurementInvoiceLines($id,$companyId);
$payments = $app->procurementBankBalances($id,$companyId);
?>
<?php form('procurement_intake'); echo field('company',$companyId),field('enabled',$account['accepting_requests'] ? 0 : 1); ?>
<button><?= $account['accepting_requests'] ? '停收新询价，继续处理存量单' : '恢复受理新询价' ?></button></form>
<h4>月末关账</h4>
<?php form('procurement_close'); echo field('company',$companyId); ?>
<label>关闭月份 <input name="month" type="month" required></label><button>冻结账单</button></form>
<?php foreach ($app->procurementAging($id,$companyId) as $aging): ?>
<p><a href="#proc-order-<?= e($aging['order_id']) ?>">采购单 #<?= e($aging['order_id']) ?></a> · 已关账余额 <?= cash($aging['outstanding_minor']) ?>
<?php if ($aging['age_days']!==null): ?> · 最早未清收货距今 <?= e($aging['age_days']) ?> 天<?php endif; ?></p>
<?php endforeach; $seenOrders=[]; ?>
<?php if ($invoiceLines): ?>
<table><thead><tr><th>账期</th><th>采购单 / 行</th><th>收货单</th><th>应收项目</th><th>货龄（天）</th><th>金额</th><th>已核销</th><th>账单</th></tr></thead><tbody>
<?php foreach ($invoiceLines as $item): ?>
<tr<?php if (!isset($seenOrders[$item['order_id']])): $seenOrders[$item['order_id']]=true; ?> id="proc-order-<?= e($item['order_id']) ?>"<?php endif; ?>>
<td><?= e(substr($item['period'],0,7)) ?></td><td>#<?= e($item['order_id']) ?> / <?= e($item['line_no']) ?></td>
<td><a href="/?page=procurement&amp;download=receipt&amp;document=<?= e($item['receipt_id']) ?>">#<?= e($item['receipt_id']) ?></a></td>
<td><?php if ($item['kind']==='return'): ?><a href="/?page=procurement&amp;download=credit&amp;document=<?= e($item['id']) ?>">退货贷项 #<?= e($item['id']) ?></a>
<?php else: ?><?= $item['kind']==='freight' ? '运费' : '货款' ?> #<?= e($item['id']) ?><?php endif; ?></td>
<td><?= e($item['age_days']) ?></td><td><?= cash($item['amount_minor']) ?></td><td><?= cash($item['applied_minor']) ?></td>
<td><?php if ($item['statement_id']): ?><a href="/?page=procurement&amp;download=statement&amp;document=<?= e($item['statement_id']) ?>">下载 #<?= e($item['statement_id']) ?></a>
<?php else: ?>待关账<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<h4>银行到账</h4>
<form method="post" action="/" enctype="multipart/form-data">
<?= field('csrf',$_SESSION['csrf']),field('action','procurement_import'),field('company',$companyId) ?>
<label>流水 CSV（bank_ref,value_date,amount_minor）<input type="file" name="bill" accept=".csv" required></label><button>导入来款</button></form>
<?php foreach ($payments as $payment): ?>
<p><?= e($payment['value_date']) ?> · <?= e($payment['bank_ref']) ?> · 到账 <?= cash($payment['amount_minor']) ?> · 待核销 <?= cash($payment['unapplied_minor']) ?></p>
<?php if ($payment['unapplied_minor']): form('procurement_allocate');
echo field('bank',$payment['id']),field('company',$companyId),field('key',bin2hex(random_bytes(16))); ?>
<label>应收行 <select name="line" required>
<?php foreach ($invoiceLines as $item): if (!$item['statement_id'] || $item['amount_minor']<=$item['applied_minor']) continue; ?>
<option value="<?= e($item['id']) ?>">订单 #<?= e($item['order_id']) ?> / 采购行 <?= e($item['line_no']) ?> / 应收行 #<?= e($item['id']) ?> · 未核销 <?= cash($item['amount_minor']-$item['applied_minor']) ?></option>
<?php endforeach; ?></select></label><label>核销金额（分）<input type="number" name="amount" min="1" max="<?= e($payment['unapplied_minor']) ?>" required></label>
<button>确认分摊</button></form><?php endif; endforeach; ?>
