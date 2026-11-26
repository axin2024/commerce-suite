<?php foreach ($app->procurementStoreAwards($id,(int)$store['id'],$orderFilter ?: null) as $award): ?>
<section><h4>授标 #<?= e($award['id']) ?> · <?= e($award['subject']) ?></h4>
<p><?= e(['pending'=>'待总部审批','approved'=>'已批准','rejected'=>'已驳回','ordered'=>'已下单'][$award['status']]) ?>
· 金额 <?= cash($award['total_minor']) ?> · 成本中心可用预算 <?= cash($award['remaining_minor']) ?>
<?php if ((int)$store['manager_id']===$id): ?> · 法人可用授信 <?= cash($award['credit_available_minor']) ?><?php endif; ?>
<?php if ($award['order_id']): ?> · <a href="/?page=procurement&amp;store=<?= e($store['id']) ?>&amp;order=<?= e($award['order_id']) ?>">采购订单 #<?= e($award['order_id']) ?></a> · <?= e($award['delivery_state']) ?><?php endif; ?></p>
<?php if ($award['decision_reason']): ?><p>审批意见 · <?= e($award['decision_reason']) ?></p><?php endif; ?>
<?php if ($award['status']==='pending' && (int)$store['manager_id']===$id):
    form('procurement_decision'); echo field('award',$award['id']),field('store',$store['id']); ?>
<?php foreach ($award['lines'] as $line): ?><label><?= e($line['specification']) ?> · 批准数量（填 0 驳回本行）
    <input required name="qty[<?= e($line['line_no']) ?>]" type="number" min="0" max="<?= e($line['requested_qty']) ?>" value="<?= e($line['requested_qty']) ?>"></label>
<?php endforeach; ?><label>核减或驳回理由 <input name="reason" maxlength="200"></label><button>确认审批</button></form>
<?php endif; ?>
<?php if ($award['order_id']): ?>
<?php $canReceive = (int)$store['manager_id']===$id || $store['duty']==='receiver'; if ($canReceive):
form('procurement_receive'); echo field('order',$award['order_id']),field('store',$store['id']); ?>
<label>采购行 <select name="line"><?php foreach ($award['lines'] as $line): if (!$line['approved_qty']) continue; ?>
<option value="<?= e($line['line_no']) ?>"><?= e($line['specification']) ?> · 订单数量 <?= e($line['approved_qty']) ?></option>
<?php endforeach; ?></select></label><label>送货单号 <input name="delivery_ref" required maxlength="96"></label>
<label>本批实收 <input name="accepted" type="number" min="0" required></label>
<label>本批破损 <input name="damaged" type="number" min="0" required value="0"></label><button>登记到货</button></form>
<?php endif; foreach ($app->procurementReceipts($id,(int)$award['order_id']) as $r): ?>
<p>回执 #<?= e($r['id']) ?> · 采购行 <?= e($r['line_no']) ?> · 送货单 <?= e($r['delivery_ref']) ?> · 实收 <?= e($r['accepted_qty']) ?> ·
破损 <?= e($r['damaged_qty']) ?> · 签收后待补 <?= e($r['open_qty']) ?> · 货值 <?= cash($r['amount_minor']) ?></p>
<a href="/?page=procurement&amp;download=receipt&amp;document=<?= e($r['id']) ?>">下载收货单</a>
<?php if ($canReceive && $r['accepted_qty']): form('procurement_return'); echo field('store',$store['id']),field('receipt',$r['id']); ?>
<label>退货单号 <input name="reference" required maxlength="96"></label>
<label>退货数量 <input type="number" name="quantity" min="1" max="<?= e($r['accepted_qty']) ?>" required></label><button>登记退货</button></form>
<?php endif; ?>
<?php endforeach; endif; ?></section><?php endforeach; ?>
