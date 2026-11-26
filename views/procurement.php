<h1>企业采购</h1>
<?php $skuSearch = is_string($_GET['sku_search'] ?? null) ? trim($_GET['sku_search']) : ''; ?>
<?php if (in_array($role,['admin','finance'],true)): ?>
<section>
    <h2>登记签约法人</h2>
    <?php form('procurement_company'); ?>
    <label>法人编码 <input name="code" required maxlength="48"></label>
    <label>结算抬头 <input name="billing_name" required maxlength="120"></label>
    <label>总部负责人账号 <input type="email" name="email" required></label>
    <label>法人账务时区 <input name="time_zone" required value="Asia/Shanghai" placeholder="Asia/Shanghai"></label>
    <button>登记法人</button></form>
</section>
<?php endif; if ($role === 'finance'): ?>
<h2>法人授信</h2>
<?php foreach ($app->procurementFinanceAccounts($id) as $account): ?>
<section><h3><?= e($account['billing_name']) ?></h3><p>已占用 <?= cash($account['credit_used_minor']) ?></p>
<?php form('procurement_limit'); echo field('company',$account['id']); ?>
<label>授信总额（分）<input required type="number" min="0" name="credit" value="<?= e($account['credit_limit_minor']) ?>"></label>
<button>保存授信</button></form>
<?php require __DIR__ . '/procurement-billing.php'; ?>
</section><?php endforeach; ?>
<?php elseif ($role === 'merchant'): ?>
<h2>企业协议价</h2>
<?php $companies = $app->procurementSupplierAccounts($id); if (!$companies): ?>
<p>尚未获得企业签约授权。</p>
<?php else: $supplierCatalog = $app->procurementMerchantSkus($id,$skuSearch); ?>
<form method="get" action="/"><?= field('page','procurement') ?><label>查询 SKU 或商品名称
<input name="sku_search" value="<?= e($skuSearch) ?>"></label><button>查找商品</button></form>
<?php form('procurement_pricing'); ?>
<label>签约企业 <select name="company"><?php foreach ($companies as $company): ?>
<option value="<?= e($company['id']) ?>"><?= e($company['billing_name']) ?></option>
<?php endforeach; ?></select></label>
<label>SKU <select name="product"><?php foreach ($supplierCatalog as $p): if ($p['status'] !== 'active') continue; ?>
<option value="<?= e($p['id']) ?>"><?= e($p['sku']) ?> · <?= e($p['name']) ?></option>
<?php endforeach; ?></select></label>
<label>基版号（首次填 0）<input type="number" name="revision" min="0" required value="0"></label>
<label>起始日 <input type="date" name="from" required></label>
<label>截止日 <input type="date" name="until" required></label>
<label>每行填数量下限,上限,优先级,单价（分）<textarea name="tiers" required placeholder="1,99,0,1000&#10;100,1000000,0,900"></textarea></label>
<button>发布协议价</button></form>
<form method="get" action="/"><?= field('page','procurement'),field('sku_search',$skuSearch) ?>
<label>试算企业 <select name="preview_company"><?php foreach ($companies as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['billing_name']) ?></option><?php endforeach; ?></select></label>
<label>SKU <select name="preview_sku"><?php foreach ($supplierCatalog as $p): ?><option value="<?= e($p['id']) ?>"><?= e($p['sku']) ?></option><?php endforeach; ?></select></label>
<label>试算数量 <input type="number" required min="1" name="quantity"></label><label>日期 <input type="date" required name="date" value="<?= e(gmdate('Y-m-d')) ?>"></label>
<button>试算当前协议</button></form>
<?php if (isset($_GET['preview_company'])): try {
    $preview = $app->procurementSupplierPreview($id,(int)filter_var($_GET['preview_company'],FILTER_VALIDATE_INT),
        (int)filter_var($_GET['preview_sku'] ?? '',FILTER_VALIDATE_INT),(int)filter_var($_GET['quantity'] ?? '',FILTER_VALIDATE_INT),
        is_string($_GET['date'] ?? null) ? $_GET['date'] : ''); ?>
    <p>协议版 <?= e($preview['revision']) ?> · <?= e($preview['quantity_min']) ?>–<?= e($preview['quantity_max']) ?> 件 · 单价 <?= cash($preview['price_minor']) ?> · 有效至 <?= e($preview['valid_until']) ?></p>
<?php } catch (RuleViolation $error) { ?><p role="alert"><?= e($error->getMessage()) ?></p><?php } endif; ?>
<h2>受邀询价</h2>
<?php foreach ($app->procurementTenderInbox($id) as $tender): ?>
<section><h3><?= e($tender['subject']) ?></h3>
<p><?= e($tender['store_name']) ?> · 截止 <?= e($tender['deadline']) ?> UTC · 本店当前报价版 <?= e($tender['revision']) ?></p>
<?php foreach ($tender['history'] as $previous): ?>
<p>报价 #<?= e($previous['id']) ?> · 版 <?= e($previous['revision']) ?> · <?= cash($previous['total_minor']) ?>
· <?= e($previous['submitted_at']) ?> UTC · <?= $previous['late'] ? '迟到归档' : '已送达' ?></p>
<?php endforeach; ?>
<?php form('procurement_bid'); echo field('rfq',$tender['id']),field('revision',$tender['revision']); ?>
<?php foreach ($tender['lines'] as $line): ?>
<label><?= e($line['specification']) ?> × <?= e($line['quantity']) ?>
<select required name="products[<?= e($line['line_no']) ?>]">
<?php foreach ($supplierCatalog as $p): if ($p['status'] !== 'active') continue; ?>
<option value="<?= e($p['id']) ?>"><?= e($p['sku']) ?> · <?= e($p['name']) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?><label>整单运费（分）<input type="number" required min="0" name="freight" value="0"></label>
<button>提交密封报价</button></form></section>
<?php endforeach; ?>
<?php endif; elseif ($role==='buyer'): ?>
<?php foreach ($app->managedProcurementCompanies($id) as $company): ?>
<section>
    <h2><?= e($company['billing_name']) ?> · 新增门店</h2>
    <?php form('procurement_store'); echo field('company', $company['id']); ?>
    <label>门店编码 <input name="code" required maxlength="48"></label>
    <label>名称 <input name="name" required maxlength="100"></label>
    <label>成本中心 <input name="center" required maxlength="80"></label>
    <label>收货地址 <input name="address" required maxlength="255"></label>
    <button>登记门店</button></form>
    <?php form('procurement_supplier'); echo field('company', $company['id']); ?>
    <label>供货商家 <select name="shop" required>
    <?php foreach ($app->procurementSupplierOptions($id, (int) $company['id']) as $supplier): ?>
        <option value="<?= e($supplier['id']) ?>"><?= e($supplier['name']) ?>（<?= $supplier['enabled'] ? '已签约' : '未签约' ?>）</option>
    <?php endforeach; ?></select></label>
    <label>签约状态 <select name="enabled"><option value="1">授权供货</option><option value="0">暂停供货</option></select></label>
    <button>保存签约关系</button></form>
</section>
<?php endforeach; ?>
<h2>可访问的门店</h2>
<?php if ($procurementStores): ?>
<form method="get" action="/"><?= field('page','procurement') ?>
<label>法人 <select name="company"><option value="">全部</option>
<?php foreach (array_column($procurementStores,'billing_name','company_id') as $cid=>$name): ?>
<option value="<?= e($cid) ?>"><?= e($name) ?></option><?php endforeach; ?></select></label>
<label>门店 <select name="receiving_store"><option value="">全部</option><?php foreach ($procurementStores as $s): ?>
<option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
<label>收货周（选取该周任一天，UTC）<input name="week" type="date" required value="<?= e(gmdate('Y-m-d')) ?>"></label><button>查询收货</button></form>
<?php if (isset($_GET['week'])):
try {
    if (!is_string($_GET['week'])) throw new RuleViolation('日期无效。');
    $deliveries = $app->procurementWeekDeliveries($id,$_GET['week'],filter_var($_GET['company'] ?? '',FILTER_VALIDATE_INT) ?: null,
        filter_var($_GET['receiving_store'] ?? '',FILTER_VALIDATE_INT) ?: null); ?>
    <?php if (!$deliveries): ?><p>本周无匹配的收货记录。</p><?php endif; ?>
    <?php foreach ($deliveries as $delivery): ?><p><?= e($delivery['billing_name']) ?> · <?= e($delivery['store_name']) ?> · 采购单 #<?= e($delivery['order_id']) ?>
    · 实收 <?= e($delivery['accepted_qty']) ?> · <a href="/?page=procurement&amp;download=receipt&amp;document=<?= e($delivery['id']) ?>">回执 #<?= e($delivery['id']) ?></a></p><?php endforeach; ?>
<?php } catch (RuleViolation $error) { ?><p role="alert"><?= e($error->getMessage()) ?></p><?php } endif; endif; ?>
<?php if (!$procurementStores): ?><p>当前账号尚未获得门店授权。</p><?php endif; ?>
<?php foreach ($procurementStores as $store): ?>
<section>
    <h3><a href="/?page=procurement&amp;store=<?= e($store['id']) ?>"><?= e($store['name']) ?></a></h3>
    <p><?= e($store['billing_name']) ?> · <?= e($store['cost_center']) ?> · <?= e($store['delivery_address']) ?></p>
    <?php if ($storeFilter !== null): ?>
    <?php if ((int)$store['manager_id']===$id): ?>
    <h4>成本中心月度预算</h4><?php form('procurement_budget'); echo field('store',$store['id']); ?>
    <label>月份 <input type="month" name="period" required value="<?= e(gmdate('Y-m')) ?>"></label>
    <label>额度（分）<input name="limit" type="number" min="0" required></label>
    <label>超过此金额送总部复核（分）<input name="threshold" type="number" min="0" required></label>
    <button>保存月度预算</button></form><?php endif; ?>
    <?php require __DIR__ . '/procurement-awards.php'; ?>
    <?php if ($store['duty']!=='receiver' || (int)$store['manager_id']===$id): ?>
    <h4>发起询价</h4><?php form('procurement_tender'); echo field('store',$store['id']); ?>
    <label>询价主题 <input name="subject" required maxlength="120"></label>
    <label>截止时间（UTC）<input name="deadline" type="datetime-local" step="1" required></label>
    <label>每行填写规格,数量 <textarea name="lines" required></textarea></label>
    <fieldset><legend>受邀商家</legend>
    <?php foreach ($app->procurementInvitableShops($id,(int)$store['id']) as $shop): ?>
    <label><input type="checkbox" name="shops[]" value="<?= e($shop['id']) ?>"><?= e($shop['name']) ?></label>
    <?php endforeach; ?></fieldset><button>发送询价</button></form>
    <?php foreach ($app->procurementStoreTenders($id,(int)$store['id']) as $tender): ?>
    <h4>#<?= e($tender['id']) ?> <?= e($tender['subject']) ?></h4>
    <p>截止 <?= e($tender['deadline']) ?> UTC · <?= $tender['closed'] ? '已开标' : '密封中' ?></p>
    <?php if ($tender['closed']): foreach ($app->procurementBidComparison($id,(int)$tender['id']) as $bid): ?>
    <p><?= e($bid['supplier']) ?> · 报价 #<?= e($bid['id']) ?> · 版 <?= e($bid['revision']) ?> · 运费 <?= cash($bid['freight_minor']) ?>
    · 含运总额 <?= cash($bid['total_minor']) ?> · <?= $bid['eligible'] ? '可授标' : ($bid['late'] ? '迟到归档' : '历史版本') ?></p>
    <details><summary>查看报价明细</summary><table><thead><tr>
    <th>需求规格</th><th>SKU</th><th>协议版</th><th>数量</th><th>含税单价</th></tr></thead><tbody>
    <?php foreach ($bid['lines'] as $line): ?><tr><td><?= e($line['specification']) ?></td><td><?= e($line['sku']) ?></td>
    <td><?= e($line['agreement_revision']) ?></td><td><?= e($line['quantity']) ?></td><td><?= cash($line['price_minor']) ?></td></tr>
    <?php endforeach; ?></tbody></table></details>
    <?php if ($bid['eligible']):
        form('procurement_award'); echo field('rfq',$tender['id']),field('bid',$bid['id']),field('store',$store['id']),field('key',bin2hex(random_bytes(16))); ?>
    <label>收货门店 <select name="ship_to"><?php foreach ($app->procurementStores($id) as $destination):
        if ($destination['company_id']!=$store['company_id']) continue; ?>
        <option value="<?= e($destination['id']) ?>" <?= $destination['id']==$store['id'] ? 'selected' : '' ?>><?= e($destination['name']) ?></option>
    <?php endforeach; ?></select></label>
    <?php foreach ($bid['lines'] as $line): ?><label><?= e($line['specification']) ?> · 授标数量
        <input required name="qty[<?= e($line['line_no']) ?>]" type="number" min="0" max="<?= e($line['quantity']) ?>" value="<?= e($line['quantity']) ?>"></label>
    <?php endforeach; ?><button>提交授标</button></form><?php endif; ?>
    <?php endforeach; endif; endforeach; ?>
    <form method="get" action="/"><?= field('page','procurement'), field('store',$store['id']) ?>
    <label>查找协议商品 <input name="sku_search" value="<?= e($skuSearch) ?>"></label><button>查找</button></form>
    <form method="get" action="/"><?= field('page','procurement'),field('store',$store['id']),field('sku_search',$skuSearch) ?>
    <label>协议商品 <select name="product"><?php foreach ($app->procurementStoreCatalog($id,(int) $store['id'],$skuSearch) as $p): ?>
    <option value="<?= e($p['id']) ?>"><?= e($p['supplier']) ?> · <?= e($p['sku']) ?> · <?= e($p['name']) ?></option>
    <?php endforeach; ?></select></label>
    <label>采购数量 <input name="quantity" type="number" min="1" max="1000000" required></label>
    <label>报价日期 <input name="date" type="date" value="<?= e(gmdate('Y-m-d')) ?>" required></label>
    <button>试算协议价</button></form>
    <?php if (isset($_GET['product'])):
        try {
            $product = filter_var($_GET['product'], FILTER_VALIDATE_INT);
            $qty = filter_var($_GET['quantity'] ?? null, FILTER_VALIDATE_INT);
            $date = $_GET['date'] ?? null;
            if (!$product || !$qty || !is_string($date)) {
                throw new RuleViolation('请检查商品、日期与数量。');
            }
            $quote = $app->procurementQuote($id, (int) $store['id'], $product, $qty, $date); ?>
            <p>协议版 <?= e($quote['revision']) ?> · 数量 <?= e($quote['quantity']) ?> · 单价 <?= cash($quote['price_minor']) ?> · 总价 <?= cash($quote['total_minor']) ?></p>
        <?php } catch (RuleViolation $error) { ?><p role="alert"><?= e($error->getMessage()) ?></p><?php } ?>
    <?php endif; endif; endif; ?>
    <?php if ((int) $store['manager_id'] === $id): ?>
    <?php foreach (['procurement_grant' => '授权采购员', 'procurement_revoke' => '移除采购员'] as $action => $label): ?>
    <?php form($action); echo field('store', $store['id']); ?>
    <label>成员邮箱 <input type="email" name="email" required></label>
    <?php if ($action==='procurement_grant'): ?><label>岗位 <select name="duty"><option value="purchaser">采购员</option><option value="receiver">收货员</option></select></label><?php endif; ?>
    <button><?= e($label) ?></button></form>
    <?php endforeach; endif; ?>
</section>
<?php endforeach; endif; ?>
