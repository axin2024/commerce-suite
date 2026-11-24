<?php if ($parcels): ?>
    <ul class="muted">
        <?php foreach ($parcels as $parcel): ?>
            <li><?= e($parcel['warehouse_name']) ?> · <?= e($parcel['carrier']) ?> <?= e($parcel['tracking']) ?>
                · <?= e($parcel['units']) ?> 件 ·
                <?php if ($parcel['delivered_at']): ?>
                    已签收 <?= e($parcel['delivered_at']) ?> UTC
                <?php else: ?>
                    已发货 <?= e($parcel['dispatched_at']) ?> UTC
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
