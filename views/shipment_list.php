<?php if ($parcels):
    $trackingStates = ['in_transit' => '运输中', 'out_for_delivery' => '派送中', 'exception' => '配送异常'];
    ?>
    <ul class="muted">
        <?php foreach ($parcels as $parcel): ?>
            <li><?= e($parcel['warehouse_name']) ?> · <?= e($parcel['carrier']) ?> <?= e($parcel['tracking']) ?>
                · <?= e($parcel['units']) ?> 件 ·
                <?php if ($parcel['delivered_at']): ?>
                    已签收 <?= e($parcel['delivered_at']) ?> UTC
                <?php elseif (isset($trackingStates[$parcel['event_type']])): ?>
                    <?= e($trackingStates[$parcel['event_type']]) ?> <?= e($parcel['occurred_at']) ?> UTC
                <?php else: ?>
                    已发货 <?= e($parcel['dispatched_at']) ?> UTC
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
