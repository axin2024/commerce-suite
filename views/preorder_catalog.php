<details class="inventory">
    <summary>预售批次</summary>
    <?php foreach ($batches as $batch): ?>
        <div class="record">
            <div>
                <strong><?= e($batch['code']) ?> · <?= e($batch['warehouse_name']) ?></strong>
                <p class="muted"><?= match (true) {
                    $batch['state'] === 'received' => '已入库',
                    $batch['state'] === 'closed' || (bool) $batch['ended'] => '已截单',
                    default => '接单中',
                } ?> · 预计 <?= e($batch['expected_on']) ?> 到货</p>
                <p class="muted">截单 <?= e($batch['closes_at']) ?> UTC</p>
                <p class="muted">额度 <?= e($batch['quota']) ?> · 待付款 <?= e($batch['held']) ?> · 已售 <?= e($batch['sold']) ?></p>
                <?php if ($batch['state'] === 'received'): ?>
                    <p class="muted">实收到货 <?= e($batch['received_units']) ?> 件 · <?= e($batch['received_at']) ?> UTC</p>
                <?php endif; ?>
            </div>
            <?php if ($batch['state'] !== 'received'): ?>
                <?php if ($batch['state'] === 'open' && !$batch['ended']):
                    form('preorder_close'); echo field('batch', $batch['id']); ?>
                    <button class="secondary">停止接单</button></form>
                <?php endif; ?>
                <?php form('preorder_receive'); echo field('batch', $batch['id']); ?>
                <label>整批到货数量<input name="units" type="number" min="<?= max(1, $batch['held'] + $batch['sold']) ?>"
                    max="1000000000" required></label>
                <button>确认到货并入库</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($product['status'] === 'active'): ?>
        <?php form('preorder_create'); echo field('product', $product['id']); ?>
        <div class="inputs">
            <label>批次编号<input name="code" pattern="[A-Za-z0-9_\-]+" maxlength="32" required></label>
            <label>库存仓<select name="warehouse" required>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= e($warehouse['id']) ?>"><?= e($warehouse['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>可售额度<input name="quota" type="number" min="1" max="1000000" required></label>
            <label>截单时间（UTC）<input name="cutoff" type="datetime-local" required></label>
            <label>预计到货日（UTC）<input name="expected" type="date" required></label>
        </div>
        <button class="secondary">发布预售批次</button></form>
    <?php endif; ?>
</details>
