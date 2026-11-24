<?php
$freightReturnId = filter_var($_GET['freight_return'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$pendingFreight = "r.status IN ('return_authorized','return_in_transit')";
$freightCases = $app->rows('SELECT r.id,r.status,r.freight_amount,r.freight_payer,r.freight_note,i.name,m.shipping,
    s.name AS shop_name FROM refunds r JOIN order_items i ON i.id=r.item_id
    JOIN merchant_orders m ON m.id=i.merchant_order_id JOIN shops s ON s.id=m.shop_id
    WHERE m.shipping>0 AND ' . ($freightReturnId ? 'r.id=?' : $pendingFreight) . ' ORDER BY r.id DESC LIMIT 50',
    $freightReturnId ? [$freightReturnId] : []);
?>
<section class="panel" id="freight">
    <h2>平台承担退运费</h2>
    <form method="get" action="/#freight" class="inputs">
        <?= field('page', 'platform') ?>
        <label>售后单号<input type="number" name="freight_return" min="1" value="<?= e($freightReturnId ?: '') ?>" required></label>
        <button>查询</button>
        <?php if ($freightReturnId): ?><a href="/?page=platform#freight">查看待验收售后</a><?php endif; ?>
    </form>
    <?php if (!$freightCases): ?><p class="muted">没有符合条件的配送售后单。</p><?php endif; ?>
    <?php foreach ($freightCases as $refund): ?>
        <div class="record">
            <div>
                <strong>#<?= e($refund['id']) ?> · <?= e($refund['shop_name']) ?> · <?= e($refund['name']) ?></strong>
                <?= badge($refund['status']) ?>
                <?php if ($refund['freight_amount'] > 0): ?>
                    <p>退运费 <?= cash($refund['freight_amount']) ?> · <?= e(Commerce::FREIGHT_PAYERS[$refund['freight_payer']]) ?>
                        <?= $refund['status'] === 'rejected' ? ' · 未生效' : '' ?> · <?= e($refund['freight_note']) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($refund['freight_payer'] !== 'merchant' && in_array($refund['status'], ['return_authorized', 'return_in_transit'], true)): ?>
                <?php $freightPayer = 'platform'; require __DIR__ . '/freight_decision_form.php'; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
