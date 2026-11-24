<?php
$returnFilter = $canSupport ? '' : " AND r.return_warehouse_id IS NOT NULL";
$refunds = $app->rows("SELECT r.*,i.name,w.name AS warehouse_name FROM refunds r JOIN order_items i ON i.id=r.item_id
    JOIN merchant_orders m ON m.id=i.merchant_order_id LEFT JOIN warehouses w ON w.id=r.return_warehouse_id
    WHERE m.shop_id=?$returnFilter ORDER BY FIELD(r.status,'return_in_transit','pending','return_authorized','refund_pending')=0,
    r.id DESC LIMIT 50", [$shop['id']]);
$returnWarehouses = $canSupport ? $app->rows('SELECT id,name,address FROM warehouses WHERE shop_id=? ORDER BY id', [$shop['id']]) : [];
?>
<section class="panel" id="refunds">
    <h2>退货与退款</h2>
    <?php if (!$refunds): ?><p class="muted">暂无售后申请。</p><?php endif; ?>
    <?php foreach ($refunds as $refund): ?>
        <div class="record">
            <div>
                <strong>#<?= e($refund['id']) ?> · <?= e($refund['name']) ?> × <?= e($refund['quantity']) ?></strong>
                <?= badge($refund['status']) ?>
                <p>买家说明 <?= e($refund['reason']) ?></p>
                <?php if ($refund['review_note']): ?><p>审核备注 <?= e($refund['review_note']) ?></p><?php endif; ?>
                <?php if ($refund['warehouse_name']): ?><p>退货仓 <?= e($refund['warehouse_name']) ?> · <?= e($refund['return_address']) ?></p><?php endif; ?>
                <?php if ($refund['tracking']): ?><p>退货运单 <?= e($refund['carrier']) ?> · <?= e($refund['tracking']) ?></p><?php endif; ?>
                <?php if ($refund['inspected_at']): ?>
                    <p>验收 <?= e($refund['accepted_quantity']) ?> 件 · 回补 <?= e($refund['restocked_quantity']) ?> 件
                        · <?= e($refund['inspected_at']) ?> UTC</p>
                <?php endif; ?>
                <?php if ($refund['inspection_note']): ?><p>验收备注 <?= e($refund['inspection_note']) ?></p><?php endif; ?>
                <?php if (in_array($refund['status'], ['approved', 'refund_pending', 'refunded'], true)): ?>
                    <p>退款金额 <?= cash($refund['amount']) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($canSupport && $refund['status'] === 'pending'): ?>
                <div>
                    <details><summary>同意退货</summary>
                        <?php form('return_approve'); echo field('refund', $refund['id']); ?>
                        <label>退货仓<select name="warehouse" required>
                            <?php foreach ($returnWarehouses as $location): ?>
                                <option value="<?= e($location['id']) ?>"><?= e($location['name']) ?>
                                    <?= $location['address'] ? ' · ' . e($location['address']) : '' ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>寄回地址（留空时使用仓库地址）<input name="address" maxlength="80"></label>
                        <label>备注<input name="note" maxlength="80"></label>
                        <button>同意退货</button></form>
                    </details>
                    <details><summary>拒绝申请</summary>
                        <?php form('return_reject'); echo field('refund', $refund['id']); ?>
                        <label>拒绝原因<input name="note" maxlength="80" required></label>
                        <button class="secondary">拒绝申请</button></form>
                    </details>
                </div>
            <?php elseif ($canFulfill && $refund['status'] === 'return_in_transit'): ?>
                <?php form('return_inspect'); echo field('refund', $refund['id']); ?>
                <label>同意退款件数<input name="accepted" type="number" min="0" max="<?= e($refund['quantity']) ?>" required></label>
                <label>可重新销售件数<input name="restocked" type="number" min="0" max="<?= e($refund['quantity']) ?>" value="0" required></label>
                <label>验收备注（少收或拒收时必填）<input name="note" maxlength="80"></label>
                <button>确认验收</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
