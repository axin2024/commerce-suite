<?php
$shops = $app->rows('SELECT s.*, COALESCE(b.net,0) AS net, COALESCE(b.entries,0) AS entries FROM shops s
    LEFT JOIN (SELECT shop_id,SUM(gross-commission) AS net,COUNT(*) AS entries FROM ledger WHERE settlement_id IS NULL
    GROUP BY shop_id) b ON b.shop_id=s.id ORDER BY s.id DESC LIMIT 100');
$shopFilter = filter_var($_GET['shop'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filter = $shopFilter ? ' WHERE l.shop_id=?' : '';
$arguments = $shopFilter ? [$shopFilter] : [];
$ledger = $app->rows('SELECT l.*,s.name FROM ledger l JOIN shops s ON s.id=l.shop_id' . $filter . ' ORDER BY l.id DESC LIMIT 80', $arguments);
$batches = $app->rows('SELECT l.*,s.name FROM settlements l JOIN shops s ON s.id=l.shop_id' . $filter . ' ORDER BY l.id DESC LIMIT 50', $arguments);
?>
<div class="heading"><h1>平台运营</h1></div>
<div class="tabs">
    <a href="#shops">店铺管理</a><a href="#ledger">结算分录</a><a href="#batches">结算批次</a>
    <?php if ($role === 'admin'): ?><a href="#audit">审计记录</a><?php endif; ?>
</div>
<section class="panel" id="shops">
    <h2>店铺管理</h2>
    <?php if (!$shops): ?><p class="muted">暂无店铺。</p><?php endif; ?>
    <?php foreach ($shops as $shop): ?>
        <div class="record">
            <div><a href="/?page=platform&amp;shop=<?= e($shop['id']) ?>#ledger"><?= e($shop['name']) ?></a> <?= badge($shop['status']) ?>
                <p class="muted">佣金 <?= e($shop['commission_bps'] / 100 . '%') ?> · 未结净额 <?= cash($shop['net']) ?> · <?= e($shop['entries']) ?> 笔</p></div>
            <?php if ($shop['status'] === 'pending' && $role === 'admin'):
                form('review'); echo field('shop', $shop['id']); ?>
                <div class="inline"><button name="decision" value="approve">通过</button>
                    <button class="secondary" name="decision" value="reject">拒绝</button></div></form>
            <?php elseif ($shop['entries'] && $shop['net'] >= 0):
                form('settle'); echo field('shop', $shop['id']); ?><button>生成结算批次</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="panel" id="ledger">
    <div class="heading"><h2>结算分录</h2>
        <?php if ($shopFilter): ?><a href="/?page=platform#ledger">查看全部店铺</a><?php endif; ?></div>
    <?php if (!$ledger): ?><p class="muted">暂无分录。</p><?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>店铺</th><th>事件</th><th>子订单</th><th>总额</th><th>佣金</th><th>应结</th><th>批次</th></tr></thead>
            <tbody><?php foreach ($ledger as $entry): ?>
                <tr><td><?= e($entry['name']) ?></td><td><?= $entry['kind'] === 'sale' ? '销售' : '退款冲正' ?></td>
                    <td>#<?= e($entry['merchant_order_id']) ?></td><td><?= cash($entry['gross']) ?></td>
                    <td><?= cash($entry['commission']) ?></td><td><?= cash($entry['gross'] - $entry['commission']) ?></td>
                    <td><?= e($entry['settlement_id'] ?? '待结算') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="panel" id="batches">
    <h2>结算批次</h2>
    <?php if (!$batches): ?><p class="muted">暂无批次。</p><?php endif; ?>
    <?php foreach ($batches as $batch): ?>
        <div class="record"><span>#<?= e($batch['id']) ?> · <?= e($batch['name']) ?> · <?= e($batch['created_at']) ?> UTC</span>
            <strong><?= cash($batch['amount']) ?></strong></div>
    <?php endforeach; ?>
</section>
<?php if ($role === 'admin'): ?>
    <section class="panel" id="audit">
        <h2>审计记录</h2>
        <?php foreach ($app->rows('SELECT a.*,u.email FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 50') as $event): ?>
            <div class="record"><strong><?= e($event['action']) ?> · #<?= e($event['entity_id']) ?></strong>
                <span class="muted"><?= e($event['email'] ?? '系统') ?> · <?= e($event['created_at']) ?> UTC</span></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
