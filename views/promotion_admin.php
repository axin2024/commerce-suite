<?php
$campaigns = $app->rows('SELECT p.id,p.code,p.enabled,r.id AS revision,r.name,r.threshold,r.discount,r.requires_claim,
    r.stack_member_price,r.refund_coupon,r.ends_at,c.name AS category_name
    FROM promotions p JOIN promotion_revisions r ON r.id=p.current_revision_id
    LEFT JOIN product_categories c ON c.id=r.category_id ORDER BY p.id DESC LIMIT 100');
$categories = $app->categories();
?>
<section class="panel" id="marketing">
    <h2>会员与营销</h2>
    <details><summary>调整会员等级</summary>
        <?php form('member_level'); ?>
        <div class="inputs">
            <label>买家邮箱<input name="email" type="email" maxlength="190" required></label>
            <label>等级<select name="level"><option value="standard">普通会员</option><option value="plus">优享会员</option></select></label>
        </div>
        <button class="secondary">保存等级</button></form>
    </details>
    <details><summary>发布跨店活动</summary>
        <?php form('promotion_publish'); ?>
        <div class="inputs">
            <label>活动编号<input name="code" maxlength="48" pattern="[a-z0-9][a-z0-9_-]{2,47}" required></label>
            <label>活动名称<input name="name" maxlength="120" required></label>
            <label>门槛（元）<input name="threshold" inputmode="decimal" required></label>
            <label>减免（元）<input name="discount" inputmode="decimal" required></label>
            <label>活动分类<select name="category">
                <option value="">全场</option>
                <?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>结束时间（UTC，选填）<input name="ends_at" type="datetime-local"></label>
        </div>
        <div class="inline">
            <label class="inline"><input name="requires_claim" type="checkbox" value="1" checked>需要先领取优惠券</label>
            <label class="inline"><input name="stack_member_price" type="checkbox" value="1" checked>允许叠加会员价</label>
            <label class="inline"><input name="refund_coupon" type="checkbox" value="1">全单退完返还未过期优惠券</label>
        </div>
        <p class="muted">使用已有编号发布新版本，已发券仍按领取时的条款使用，停用活动会暂停领券及结账使用。</p>
        <button>发布活动</button></form>
    </details>
    <?php foreach ($campaigns as $campaign): ?>
        <div class="record">
            <div><strong><?= e($campaign['name']) ?></strong> <?= $campaign['enabled'] ? '启用' : '停用' ?>
                <p><?= e($campaign['code']) ?> · 版本 #<?= e($campaign['revision']) ?> · <?= e($campaign['category_name'] ?? '全场') ?>
                    · 满 <?= cash($campaign['threshold']) ?> 减 <?= cash($campaign['discount']) ?></p>
                <p class="muted"><?= $campaign['requires_claim'] ? '领券使用' : '自动匹配' ?>
                    · <?= $campaign['stack_member_price'] ? '可叠加会员价' : '活动行按标价' ?>
                    <?= $campaign['refund_coupon'] ? ' · 全单退完返券' : '' ?>
                    <?= $campaign['ends_at'] ? ' · ' . e($campaign['ends_at']) . ' UTC 截止' : '' ?></p></div>
            <?php form('promotion_access'); echo field('promotion', $campaign['id']), field('enabled', $campaign['enabled'] ? 0 : 1); ?>
            <button class="secondary"><?= $campaign['enabled'] ? '停用活动' : '启用活动' ?></button></form>
        </div>
    <?php endforeach; ?>
</section>
