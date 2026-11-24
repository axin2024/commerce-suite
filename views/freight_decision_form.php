<details><summary><?= e(Commerce::FREIGHT_PAYERS[$freightPayer]) ?>退运费</summary>
    <p class="muted">子订单原运费 <?= cash($refund['shipping']) ?>。已有决定可填 0 撤回。</p>
    <?php form('return_freight'); echo field('refund', $refund['id']); ?>
    <label>退款金额（元）<input name="freight" inputmode="decimal" maxlength="12"
        value="<?= e($refund['freight_amount'] > 0 ? number_format($refund['freight_amount'] / 100, 2, '.', '') : '') ?>"
        required></label>
    <label>处理依据<input name="note" maxlength="80" required></label>
    <button>保存运费决定</button></form>
</details>
