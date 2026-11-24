<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = database();
$app = new Commerce($db);
$checks = 0;

function same(mixed $expected, mixed $actual, string $scenario): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($scenario . ': expected ' . var_export($expected, true)
            . '; got ' . var_export($actual, true));
    }
    $checks++;
}

function denied(callable $operation, string $scenario): void
{
    global $checks;
    try {
        $operation();
    } catch (RuleViolation) {
        $checks++;
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $scenario);
}

if (!demoEnabled() || !str_ends_with((string) $db->query('SELECT DATABASE()')->fetchColumn(), '_test')) {
    throw new RuntimeException('Use APP_DEMO=1 and a disposable database ending in _test.');
}

$worker = $argv[1] ?? '';
if (in_array($worker, ['race-worker', 'pickup-worker'], true)) {
    echo "ready\n";
    fgets(STDIN);
    try {
        if ($worker === 'race-worker') {
            $app->place((int) $argv[2], $argv[3]);
            echo "placed\n";
        } else {
            echo $app->redeemPickup((int) $argv[2], (int) $argv[3], (int) $argv[4], $argv[5]) ? "redeemed\n" : "already-redeemed\n";
        }
    } catch (RuleViolation) {
        echo "unavailable\n";
    }
    exit;
}

function compete(string $worker, array $jobs): array
{
    $workers = [];
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        array_push($command, '-d', 'extension=pdo_mysql');
    }
    array_push($command, __FILE__, $worker);
    foreach ($jobs as $args) {
        $process = proc_open(array_merge($command, array_map('strval', $args)), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process) || trim((string) fgets($pipes[1])) !== 'ready') {
            throw new RuntimeException('Race worker failed to start.');
        }
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [, $pipes]) {
        fwrite($pipes[0], "go\n");
        fclose($pipes[0]);
    }
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $outcomes[] = trim(stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process), 'worker exit: ' . $stderr);
    }
    sort($outcomes);
    return $outcomes;
}
$tag = bin2hex(random_bytes(5));
$pw = 'IntegrationFixture-6-2026';
$admin = $app->provisionOperator("admin-$tag@example.test", $pw, 'admin');
$finance = $app->provisionOperator("finance-$tag@example.test", $pw, 'finance');
$buyer = $app->register("buyer-$tag@example.test", $pw);
$otherBuyer = $app->register("buyer2-$tag@example.test", $pw);
$north = $app->register("north-$tag@example.test", $pw, '北仓');
$pond = $app->register("pond-$tag@example.test", $pw, '池畔');
$northShop = (int) $app->profile($north)['shop_id'];
$pondShop = (int) $app->profile($pond)['shop_id'];
same($buyer, $app->authenticate("buyer-$tag@example.test", $pw), 'buyer login');
same(null, $app->authenticate("buyer-$tag@example.test", 'wrong'), 'invalid password');
$bags = $app->createCategory($admin, "箱包-$tag");
$home = $app->createCategory($admin, "家居-$tag");
denied(fn() => $app->createCategory($north, "自定义-$tag"), 'merchant cannot create a platform category');
denied(fn() => $app->reviewShop($finance, $northShop, true), 'finance cannot approve a merchant');
denied(fn() => $app->submitSku($north, 0, 'N-1', 'Demo', 12000, 1, $bags, '测试', '', '', true, true), 'shop approval required');
denied(fn() => $app->reviewShop($admin, $northShop, true), 'approval requires a payee and agreement');
$app->reviewShop($admin, $northShop, false);
$app->submitShopAgreement($north, '北仓选物商行');
$app->submitShopAgreement($pond, '池畔早餐商行');
$app->reviewShop($admin, $northShop, true);
$app->reviewShop($admin, $pondShop, true);
denied(fn() => $app->reviewShop($admin, $northShop, false), 'shop review cannot be overwritten');
denied(fn() => $app->submitShopAgreement($north, '另一主体'), 'approved payee cannot be replaced');
$review = $app->rows("SELECT actor_id,details FROM audit_log WHERE action='shop.reviewed' AND entity_id=? ORDER BY id DESC LIMIT 1", [$northShop])[0];
same($admin, (int) $review['actor_id'], 'operator recorded on approval');
$approvalDetails = json_decode($review['details'], true, flags: JSON_THROW_ON_ERROR);
same('北仓选物商行', $approvalDetails['payee_name'], 'approved payee recorded');
same(Commerce::AGREEMENT_VERSION, $approvalDetails['agreement_version'], 'approved agreement version recorded');
denied(fn() => $app->createPlatformShop($finance, "house-$tag@example.test", $pw, '自营旗舰店', '平台商行'),
    'only operations can create platform shops');
$house = $app->createPlatformShop($admin, "house-$tag@example.test", $pw, '自营旗舰店', '平台商行');
same('platform', $app->rows('SELECT kind FROM shops WHERE id=?', [$app->profile($house)['shop_id']])[0]['kind'],
    'platform shop has distinct operating model');
$catalogEmail = "north-catalog-$tag@example.test";
$catalogStaff = $app->addShopMember($north, $catalogEmail, $pw, 'catalog');
$fulfillmentStaff = $app->addShopMember($north, "north-ship-$tag@example.test", $pw, 'fulfillment');
$supportStaff = $app->addShopMember($north, "north-cs-$tag@example.test", $pw, 'support');
$financeStaff = $app->addShopMember($pond, "pond-books-$tag@example.test", $pw, 'finance');
$main = (int) $app->merchantWarehouses($north)[0]['id'];
$foreignWarehouse = (int) $app->merchantWarehouses($pond)[0]['id'];
$northPickup = $app->savePickupLocation($north, 0, $main, 'NORTH', '北仓自提门店', '北仓园区 1 号', true);
$pondPickup = $app->savePickupLocation($pond, 0, $foreignWarehouse, 'POND', '池畔自提门店', '池畔园区 2 号', true);
$northLocations = [$northShop => $northPickup];
$pondLocations = [$pondShop => $pondPickup];
$bothLocations = $northLocations + $pondLocations;
denied(fn() => $app->savePickupLocation($north, 0, $foreignWarehouse, 'OTHER', '跨店仓', '另一店地址', true), 'warehouse ownership for pickup');
denied(fn() => $app->savePickupLocation($catalogStaff, $northPickup, 0, 'CHANGED', '越权门店', '越权地址', true),
    'catalog staff cannot configure pickup locations');
denied(fn() => $app->addShopMember($north, "pickup-cross-$tag@example.test", $pw, 'pickup', $pondPickup), 'staff location belongs to the same shop');
denied(fn() => $app->addShopMember($north, "pickup-missing-$tag@example.test", $pw, 'pickup'), 'pickup role requires an assignment');
$pickupStaff = $app->addShopMember($north, "north-pickup-$tag@example.test", $pw, 'pickup', $northPickup);
same($catalogStaff, $app->authenticate($catalogEmail, $pw), 'active staff can sign in');
denied(fn() => $app->addShopMember($catalogStaff, "escalate-$tag@example.test", $pw, 'finance'), 'staff cannot create accounts');
denied(fn() => $app->setShopMemberEnabled($pond, $catalogStaff, false), 'cross-shop staff revocation refused');
denied(fn() => $app->setShopMemberEnabled($north, $north, false), 'owner cannot revoke their own membership');
$app->setShopMemberEnabled($north, $catalogStaff, false);
same(null, $app->authenticate($catalogEmail, $pw), 'revoked staff cannot sign in');
same(false, $app->profile($catalogStaff)['enabled'], 'revoked staff session can be invalidated');
denied(fn() => $app->submitSku($catalogStaff, 0, "N-$tag", '库存', 12000, 10, $bags, '北仓帆布包', '红色', 'M', true, true),
    'revoked staff has no catalog write access');
$app->setShopMemberEnabled($north, $catalogStaff, true);
$redRelease = $app->submitSku($catalogStaff, 0, "N-$tag", '北仓帆布包', 12000, 10, $bags, '北仓帆布包', '红色', 'M', true, true,
    memberPrice: 10000);
same([], $app->catalog("N-$tag"), 'merchant submission requires review before becoming visible');
denied(fn() => $app->reviewSku($north, $redRelease, true), 'merchant cannot approve its own SKU release');
$a = $app->reviewSku($admin, $redRelease, true);
same($a, $app->reviewSku($admin, $redRelease, true), 'approval replay returns the same SKU');
$cupRelease = $app->submitSku($pond, 0, "P-$tag", '池畔早餐杯', 8000, 10, $home, '池畔陶瓷', '湖蓝', '300ml', true, true);
$b = $app->reviewSku($admin, $cupRelease, true);
$hiddenRelease = $app->submitSku($north, 0, "D-$tag", '未公开商品', 100, 4, $bags, '待审核', '', '', true, true);
$hidden = (int) $app->rows('SELECT product_id FROM product_releases WHERE id=?', [$hiddenRelease])[0]['product_id'];
same([], $app->catalog("D-$tag"), 'draft catalog isolation');
same($a, (int) $app->catalog("N-$tag")[0]['id'], 'SKU search');
same([], $app->catalog("N-$tag", $home), 'category filter excludes products in other categories');
same([$a], array_map('intval', array_column($app->catalog('红色', $bags), 'id')), 'search by variant and category');
denied(fn() => $app->submitSku($pond, $a, "N-$tag", '跨店改名', 1, 0, $bags, '北仓帆布包', '红色', 'M', true, true, $redRelease),
    'product ownership');
denied(fn() => $app->reviewSku($admin, $hiddenRelease, false), 'rejecting SKU requires a reason');
$app->reviewSku($admin, $hiddenRelease, false, '分类不符');
denied(fn() => $app->reviewSku($admin, $hiddenRelease, true), 'rejected release cannot later be approved');
denied(fn() => $app->quote($buyer, [$hidden => 1], 'store', '', pickupLocations: $northLocations), 'draft not purchasable');
$blueRelease = $app->submitSku($catalogStaff, 0, "B-$tag", '北仓帆布包', 12000, 3, $bags, '北仓帆布包', '蓝色', 'M', false, true);
$blueSku = $app->reviewSku($admin, $blueRelease, true);
same([], $app->catalog("B-$tag", $bags, 'web'), 'pickup-only variant absent from delivery catalog');
same($blueSku, (int) $app->catalog("B-$tag", $bags, 'store')[0]['id'], 'pickup-only variant appears in store catalog');
denied(fn() => $app->quote($buyer, [$blueSku => 1], 'web', '测试配送地址'), 'checkout revalidates sales channel');
$duplicateVariant = $app->submitSku($north, 0, "X-$tag", '同红色规格', 12000, 1, $bags, '北仓帆布包', '红色', 'M', true, true);
denied(fn() => $app->reviewSku($admin, $duplicateVariant, true), 'duplicate variant refused at publication');
$blueQuote = $app->quote($buyer, [$blueSku => 1], 'store', '', pickupLocations: $northLocations);
$app->unlistSku($catalogStaff, $blueSku);
same([], $app->catalog("B-$tag"), 'unlisted variant disappears from product search');
denied(fn() => $app->place($buyer, $blueQuote['token']), 'stale catalog result cannot be checked out after unlisting');
same([1 => 0, 2 => 0, 3 => 1], Commerce::allocate(1, [1 => 1, 2 => 1, 3 => 1]), 'last SKU receives the rounding remainder');
same([3 => 1, 1 => 0, 2 => 1], Commerce::allocate(2, [3 => 1, 1 => 3, 2 => 3]), 'rounding moves backward when last line lacks capacity');
same(19600, $app->quote($buyer, [$a => 1, $b => 1], 'web', '测试配送地址')['total'], 'per-shop shipping');
denied(fn() => $app->quote($buyer, [$a => 1, $b => 1], 'store', '', pickupLocations: $northLocations),
    'all pickup shops need separate selections');
denied(fn() => $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: [$northShop => $pondPickup]),
    'another merchant location cannot be substituted');
$quote = $app->quote($buyer, [$b => 1, $a => 1], 'store', '', pickupLocations: $bothLocations);
same(18000, $quote['total'], 'combined shopper payment');
same(10800, $quote['shops'][$northShop]['total'], 'merchant A split');
same(7200, $quote['shops'][$pondShop]['total'], 'merchant B split');
denied(fn() => $app->place($otherBuyer, $quote['token']), 'quote ownership');
$order = $app->place($buyer, $quote['token']);
same($order, $app->place($buyer, $quote['token']), 'checkout replay returns same order');
same(1, (int) $app->rows('SELECT reserved FROM products WHERE id=?', [$a])[0]['reserved'], 'stock held once');
denied(fn() => $app->adjustStock($north, $a, 0, 1), 'stock cannot go below holds');
denied(fn() => $app->demoPay($otherBuyer, $order, "pay-$tag", 18000), 'payment ownership');
denied(fn() => $app->demoPay($buyer, $order, "pay-$tag", 18001), 'payment amount validation');
$payment = $app->demoPay($buyer, $order, "pay-$tag", 18000);
same($payment, $app->demoPay($buyer, $order, "pay-$tag", 18000), 'payment replay');
denied(fn() => $app->demoPay($buyer, $order, "alt-$tag", 18000), 'one receipt per order');
same(false, $app->cancel($order, $buyer), 'paid order cannot be cancelled');
$inventory = $app->rows('SELECT stock,reserved FROM products WHERE id=?', [$a])[0];
same([9, 0], array_map('intval', array_values($inventory)), 'stock consumed exactly once');
denied(fn() => $app->adjustStock($north, $a, 10, 0),
    'outdated stock edit cannot restore paid inventory');
$merchantOrders = $app->rows('SELECT id,shop_id FROM merchant_orders WHERE order_id=? ORDER BY shop_id', [$order]);
[$northOrder, $pondOrder] = array_map('intval', array_column($merchantOrders, 'id'));
denied(fn() => $app->preparePickup($pond, $northOrder, $northPickup), 'pickup order ownership');
denied(fn() => $app->preparePickup($catalogStaff, $northOrder, $northPickup), 'catalog staff cannot prepare pickup');
denied(fn() => $app->preparePickup($fulfillmentStaff, $northOrder, $northPickup), 'delivery staff cannot prepare pickup');
denied(fn() => $app->issuePickupCode($buyer, $northOrder), 'buyer cannot issue code while shop prepares the order');
$aItem = (int) $app->rows('SELECT id FROM order_items WHERE merchant_order_id=?', [$northOrder])[0]['id'];
$sold = $app->rows('SELECT i.sku,r.color,r.size FROM order_items i JOIN product_releases r ON r.id=i.product_release_id WHERE i.id=?', [$aItem])[0];
same(["N-$tag", '红色', 'M'], array_values($sold), 'checkout records the actual red M SKU and its release');
same([3, 0], array_map('intval', array_values($app->rows('SELECT stock,reserved FROM products WHERE id=?', [$blueSku])[0])),
    'checkout of red variant leaves blue inventory unchanged');
denied(fn() => $app->requestRefund($buyer, $aItem, 1, '测试', bin2hex(random_bytes(16))), 'refund requires fulfillment');
$beforeSuspension = $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: $northLocations);
denied(fn() => $app->setShopSuspended($finance, $northShop, true, '暂停营业'), 'finance cannot suspend a shop');
$app->setShopSuspended($admin, $northShop, true, '停业盘点');
same([], $app->catalog("N-$tag"), 'suspended shop removed from catalog');
denied(fn() => $app->place($buyer, $beforeSuspension['token']), 'stale quote cannot order from suspended shop');
denied(fn() => $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: $northLocations), 'suspended shop cannot receive a new quote');
same($order, $app->place($buyer, $quote['token']), 'existing order replay remains available after closing');
$app->preparePickup($pickupStaff, $northOrder, $northPickup);
$app->preparePickup($pond, $pondOrder, $pondPickup);
denied(fn() => $app->issuePickupCode($otherBuyer, $northOrder), 'another buyer cannot issue code');
$firstCode = $app->issuePickupCode($buyer, $northOrder);
$secondCode = $app->issuePickupCode($buyer, $northOrder);
$pondCode = $app->issuePickupCode($buyer, $pondOrder);
denied(fn() => $app->redeemPickup($pickupStaff, $northOrder, $northPickup, $firstCode), 'reissued pickup code invalidates first code');
denied(fn() => $app->redeemPickup($pickupStaff, $northOrder, $northPickup, $pondCode), 'code is scoped to the merchant order');
$db->prepare('UPDATE order_pickups SET code_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE merchant_order_id=?')
    ->execute([$northOrder]);
denied(fn() => $app->redeemPickup($pickupStaff, $northOrder, $northPickup, $secondCode), 'expired pickup code is rejected');
$currentCode = $app->issuePickupCode($buyer, $northOrder);
same(true, $app->redeemPickup($pickupStaff, $northOrder, $northPickup, $currentCode), 'store staff redeems its own pickup');
same(false, $app->redeemPickup($north, $northOrder, $northPickup, $currentCode), 'pickup replay cannot duplicate the sale');
same(true, $app->redeemPickup($pond, $pondOrder, $pondPickup, $pondCode), 'another shop can redeem its own pickup');
same($pickupStaff, (int) $app->rows('SELECT used_by FROM order_pickups WHERE merchant_order_id=?', [$northOrder])[0]['used_by'],
    'pickup redemption keeps the first operator across replay');
denied(fn() => $app->issuePickupCode($buyer, $northOrder), 'fulfilled pickup cannot reissue a code');
$refundKey = bin2hex(random_bytes(16));
$refund = $app->requestRefund($buyer, $aItem, 1, '申请退款', $refundKey);
same($refund, $app->requestRefund($buyer, $aItem, 1, '申请退款', $refundKey), 'refund replay');
denied(fn() => $app->requestRefund($buyer, $aItem, 1, '更换原因', $refundKey), 'refund replay payload cannot change');
denied(fn() => $app->requestRefund($buyer, $aItem, 1, '重复申请', bin2hex(random_bytes(16))), 'pending amount is held');
denied(fn() => $app->decideRefund($pond, $refund, true), 'refund approval ownership');
denied(fn() => $app->decideRefund($fulfillmentStaff, $refund, true), 'fulfillment staff cannot approve a refund');
same(10800, $app->decideRefund($supportStaff, $refund, true), 'discount-adjusted refund after shop suspension');
same(10800, $app->decideRefund($north, $refund, true), 'refund decision replay');
$ledger = $app->rows('SELECT gross,commission FROM ledger WHERE merchant_order_id=? ORDER BY id', [$northOrder]);
same([[10800,1080],[-10800,-1080]], array_map(static fn(array $row): array => array_map('intval', array_values($row)), $ledger),
    'gross and commission reversals balance');
denied(fn() => $app->settle($buyer, $pondShop), 'settlement authorization');
$northBatch = $app->settle($finance, $northShop);
$pondBatch = $app->settle($finance, $pondShop);
same([0, 6480], array_map('intval', array_column($app->rows('SELECT amount FROM settlements WHERE id IN (?,?) ORDER BY id',
    [$northBatch, $pondBatch]), 'amount')), 'finance settlement matches PRD example');
denied(fn() => $app->settle($finance, $pondShop), 'settlement entries cannot be booked twice');
same([$northOrder, $northOrder], array_map('intval', array_column($app->merchantLedger($north), 'merchant_order_id')),
    'shop A statement contains only its shop orders while suspended');
same([$pondOrder], array_map('intval', array_column($app->merchantLedger($financeStaff), 'merchant_order_id')),
    'shop B statement contains only its shop orders');
denied(fn() => $app->merchantLedger($catalogStaff), 'product staff cannot read financial records');
denied(fn() => $app->settle($financeStaff, $pondShop), 'shop finance cannot book a settlement batch');
$app->setShopSuspended($admin, $northShop, false, '盘点完成');
same($a, (int) $app->catalog("N-$tag")[0]['id'], 'resumed shop returns to sales');

$memberEmail = "member-$tag@example.test";
$memberBuyer = $app->register($memberEmail, $pw);
denied(fn() => $app->setMemberLevel($memberBuyer, $memberEmail, 'plus'), 'buyers cannot upgrade themselves');
$app->setMemberLevel($admin, $memberEmail, 'plus');
$memberQuote = $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', pickupLocations: $bothLocations);
same(18000, $memberQuote['total'], 'member prices are applied before automatic campaign threshold');
same(2000, $memberQuote['member_savings'], 'member saving recorded');
same(0, $memberQuote['discount'], 'automatic campaign does not apply below its member-price threshold');
$couponVersion = $app->publishPromotion($admin, "cross-$tag", '跨店不叠加券', 20000, 2000, true, false);
$campaign = (int) $app->rows('SELECT promotion_id FROM promotion_revisions WHERE id=?', [$couponVersion])[0]['promotion_id'];
same(true, $app->claimCoupon($memberBuyer, $campaign), 'coupon claimed once');
same(false, $app->claimCoupon($memberBuyer, $campaign), 'second coupon claim already received');
denied(fn() => $app->quote($buyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations), 'cannot use another buyer’s claim');
$couponQuote = $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
same(0, $couponQuote['member_savings'], 'non-stackable campaign uses listed prices');
same([10800, 7200], array_column($couponQuote['shops'], 'total'), 'coupon apportioned to shop totals in cents');
$newCouponVersion = $app->publishPromotion($admin, "cross-$tag", '跨店券新版', 20000, 1900, true, false);
$stillOriginal = $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
same($couponVersion, $stillOriginal['promotion']['revision_id'], 'existing claims keep the issued campaign version');
same(true, $app->claimCoupon($otherBuyer, $campaign), 'new buyer may claim the latest campaign');
$otherCoupon = $app->quote($otherBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
same([$newCouponVersion, 1900], [$otherCoupon['promotion']['revision_id'], $otherCoupon['discount']], 'new claim uses current version');
$couponEnds = gmdate('Y-m-d\TH:i', time() + 3600);
$scopedVersion = $app->publishPromotion($admin, "scoped-$tag", '箱包分类专享', 10000, 500, true, true, $bags, $couponEnds);
$couponEndsStored = $app->rows('SELECT ends_at FROM promotion_revisions WHERE id=?', [$scopedVersion])[0]['ends_at'];
same(str_replace('T', ' ', $couponEnds) . ':00', $couponEndsStored, 'campaign end time stored as UTC');
$scoped = (int) $app->rows('SELECT promotion_id FROM promotion_revisions WHERE id=?', [$scopedVersion])[0]['promotion_id'];
$app->claimCoupon($memberBuyer, $scoped);
denied(fn() => $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $scoped, $bothLocations),
    'out-of-scope seller does not count toward a cross-shop coupon');
$app->setPromotionEnabled($admin, $campaign, false);
denied(fn() => $app->place($memberBuyer, $couponQuote['token']), 'disabled campaign invalidates coupon quote');
$app->setPromotionEnabled($admin, $campaign, true);
$db->prepare('UPDATE quotes SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token=?')->execute([$couponQuote['token']]);
denied(fn() => $app->place($memberBuyer, $couponQuote['token']), 'expired quote cannot create a discounted order');
$heldCouponOrder = $app->place($memberBuyer, $stillOriginal['token']);
same('held', array_column($app->buyerCoupons($memberBuyer), 'status', 'id')[$campaign], 'unpaid order holds coupon');
denied(fn() => $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations), 'held coupon cannot fund another order');
same(true, $app->cancel($heldCouponOrder, $memberBuyer), 'cancel coupon order');
same('available', array_column($app->buyerCoupons($memberBuyer), 'status', 'id')[$campaign], 'cancel returns coupon to the same member');
$requoted = $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
$paidCouponOrder = $app->place($memberBuyer, $requoted['token']);
$app->demoPay($memberBuyer, $paidCouponOrder, "coupon-pay-$tag", 18000);
same('used', array_column($app->buyerCoupons($memberBuyer), 'status', 'id')[$campaign], 'payment consumes coupon');
same($couponVersion, (int) $app->rows('SELECT promotion_revision_id FROM orders WHERE id=?', [$paidCouponOrder])[0]['promotion_revision_id'],
    'order freezes the issued promotions revision');
$freeVersion = $app->publishPromotion($admin, "free-$tag", '全额优惠验收', 20000, 20000, true, false);
$freeCampaign = (int) $app->rows('SELECT promotion_id FROM promotion_revisions WHERE id=?', [$freeVersion])[0]['promotion_id'];
$app->claimCoupon($buyer, $freeCampaign);
$zero = $app->quote($buyer, [$a => 1, $b => 1], 'store', '', $freeCampaign, $bothLocations);
same(0, $zero['total'], 'full discount creates a zero amount order');
$zeroOrder = $app->place($buyer, $zero['token']);
same('promotion', $app->rows('SELECT provider FROM payments WHERE order_id=?', [$zeroOrder])[0]['provider'],
    'zero amount checkout posts locally and requires no external payment');

$app->setCartLine($buyer, $a, 1, true);
$app->setCartLine($buyer, $b, 2, false);
same([], $app->cartLines($otherBuyer), 'cart isolated to buyer account');
$cartQuote = $app->quoteCart($buyer, 'store', '', pickupLocations: $northLocations);
same([$a => 1], $cartQuote['cart'], 'unselected lines excluded from quote');
same(12000, $cartQuote['total'], 'single selected SKU has no cross-shop discount');
$oldRevision = $cartQuote['cart_revisions'][$a];
$app->setCartLine($buyer, $a, 2, true, $oldRevision);
denied(fn() => $app->place($buyer, $cartQuote['token'], requireCartQuote: true), 'changed cart requires requote');
denied(fn() => $app->setCartLine($buyer, $a, 0, true, $oldRevision), 'stale cart edit cannot delete a newer selection');
$app->setCartLine($buyer, $a, 0, true);
$app->setCartLine($buyer, $a, 1, true);
denied(fn() => $app->place($buyer, $cartQuote['token'], requireCartQuote: true), 'reinserted line retains no stale quote');
$app->importSessionCart($buyer, [$a => 3, $b => 5, PHP_INT_MAX => 1]);
same([1, 2], array_map('intval', array_column($app->cartLines($buyer), 'quantity')),
    'legacy session merge preserves existing persistent cart');
$app->importSessionCart($otherBuyer, [$b => 1]);
same([$b], array_map('intval', array_column($app->cartLines($otherBuyer), 'product_id')), 'legacy cart copied once');
$app->setCartLine($otherBuyer, $b, 1, false);
denied(fn() => $app->quoteCart($otherBuyer, 'store', ''), 'no selected cart lines');
$directQuote = $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: $northLocations);
denied(fn() => $app->place($buyer, $directQuote['token'], requireCartQuote: true), 'web checkout requires a cart-bound quote');
$cartQuote = $app->quoteCart($buyer, 'store', '', pickupLocations: $northLocations);
$cartOrder = $app->place($buyer, $cartQuote['token'], requireCartQuote: true);
same($cartOrder, $app->place($buyer, $cartQuote['token'], requireCartQuote: true), 'cart checkout replay after clearing');
same([$b], array_map('intval', array_column($app->cartLines($buyer), 'product_id')), 'checkout keeps unselected lines');
same(true, $app->cancel($cartOrder, $buyer), 'test cart hold released');

$east = $app->createWarehouse($catalogStaff, "EAST-$tag", '东区仓', '示例东区配送中心');
denied(fn() => $app->createWarehouse($catalogStaff, 'MAIN', '第二主仓', '示例地址'), 'warehouse code unique inside shop');
denied(fn() => $app->createWarehouse($supportStaff, 'NO', '售后无权限', '示例地址'), 'warehouse creation requires catalog access');
$multiRelease = $app->submitSku($north, 0, "WH-$tag", '分仓验收商品', 1200, 1, $bags, '分仓商品', '', '', true, true);
$multiSku = $app->reviewSku($admin, $multiRelease, true);
denied(fn() => $app->adjustStock($north, $multiSku, 3, 0, $foreignWarehouse), 'cannot adjust stock in another shop’s warehouse');
$app->adjustStock($catalogStaff, $multiSku, 2, 0, $east);
denied(fn() => $app->quote($buyer, [$multiSku => 2], 'store', '', pickupLocations: $northLocations), 'pickup cannot allocate remote delivery stock');
$multiQuote = $app->quote($buyer, [$multiSku => 3], 'web', '分仓配送地址');
same([$main => 1, $east => 2], $multiQuote['items'][$multiSku]['allocation'], 'one SKU distributed between two warehouses');
same(1600, $multiQuote['shipping'], 'delivery charge calculated per shipping group');
$multiOrder = $app->place($buyer, $multiQuote['token']);
$multiBalance = static function () use ($app, $multiSku): array {
    return array_map(static fn(array $row): array => array_map('intval', array_values($row)), $app->rows(
        'SELECT warehouse_id,stock,reserved FROM warehouse_stock WHERE product_id=? ORDER BY warehouse_id', [$multiSku]));
};
same([[$main, 1, 1], [$east, 2, 2]], $multiBalance(), 'stock held separately in each warehouse');
denied(fn() => $app->adjustStock($north, $multiSku, 1, 2, $east), 'cannot lower a warehouse below its hold');
same(true, $app->cancel($multiOrder, $buyer), 'cancel releases all warehouse holds');
same([[$main, 1, 0], [$east, 2, 0]], $multiBalance(), 'cancel restores availability for both warehouse balances');
$staleAllocation = $app->quote($buyer, [$multiSku => 2], 'web', '分仓配送地址');
$app->adjustStock($north, $multiSku, 0, 3, $main);
denied(fn() => $app->place($buyer, $staleAllocation['token']), 'changed fulfillment plan requires a new confirmation');
$app->adjustStock($north, $multiSku, 1, 4, $main);
$multiPaidQuote = $app->quote($buyer, [$multiSku => 3], 'web', '分仓配送地址');
$multiPaidOrder = $app->place($buyer, $multiPaidQuote['token']);
$app->demoPay($buyer, $multiPaidOrder, "multi-$tag", $multiPaidQuote['total']);
same([[$main, 0, 0], [$east, 0, 0]], $multiBalance(), 'payment consumes each warehouse hold and stock');
$soldAllocations = $app->rows('SELECT a.id,a.state,a.quantity,m.id AS merchant_order_id FROM order_inventory_allocations a
    JOIN order_items i ON i.id=a.order_item_id JOIN merchant_orders m ON m.id=i.merchant_order_id
    WHERE m.order_id=? ORDER BY a.warehouse_id', [$multiPaidOrder]);
same(['consumed', 'consumed'], array_column($soldAllocations, 'state'), 'paid order keeps consumed allocation history');
same([1, 2], array_map('intval', array_column($soldAllocations, 'quantity')), 'paid order keeps fulfillment quantities by warehouse');
$deliveryOrder = (int) $soldAllocations[0]['merchant_order_id'];
[$mainLine, $eastLine] = array_map('intval', array_column($soldAllocations, 'id'));
$shipmentKey = bin2hex(random_bytes(16));
$trackingA = "A-$tag";
$trackingB = "B-$tag";
$trackingC = "C-$tag";
$deliveryStatus = static fn(): string => $app->rows('SELECT status FROM merchant_orders WHERE id=?', [$deliveryOrder])[0]['status'];
$deliveryLedger = static fn(): array => $app->rows('SELECT kind,gross FROM ledger WHERE merchant_order_id=?', [$deliveryOrder]);
denied(fn() => $app->preparePickup($north, $deliveryOrder, $northPickup), 'delivery cannot use the pickup action');
denied(fn() => $app->dispatchShipment($pond, $deliveryOrder, $main, 'demo', $trackingA, [$mainLine => 1], $shipmentKey),
    'other shop cannot dispatch delivery');
denied(fn() => $app->dispatchShipment($catalogStaff, $deliveryOrder, $main, 'demo', $trackingA, [$mainLine => 1], $shipmentKey),
    'catalog staff cannot dispatch delivery');
denied(fn() => $app->dispatchShipment($fulfillmentStaff, $deliveryOrder, $east, 'demo', $trackingA, [$mainLine => 1], $shipmentKey),
    'allocation cannot be shipped from another warehouse');
denied(fn() => $app->dispatchShipment($fulfillmentStaff, $deliveryOrder, $main, 'demo', $trackingA, [$mainLine => 2], $shipmentKey),
    'parcel cannot exceed its allocation');
$shipmentA = $app->dispatchShipment($fulfillmentStaff, $deliveryOrder, $main, 'DEMO', $trackingA, [$mainLine => 1], $shipmentKey);
same($shipmentA, $app->dispatchShipment($north, $deliveryOrder, $main, 'demo', $trackingA, [$mainLine => 1], $shipmentKey),
    'normalized repeat dispatch returns the same package');
denied(fn() => $app->dispatchShipment($north, $deliveryOrder, $main, 'demo', $trackingB, [$mainLine => 1], $shipmentKey),
    'request key cannot change package contents');
same('partial', $deliveryStatus(), 'some warehouse allocations still awaiting dispatch');
$receiptA = $app->recordDelivery('demo', $trackingA, "delivered-a-$tag");
same($receiptA, $app->recordDelivery('demo', $trackingA, "delivered-a-$tag"), 'signed event repeat is idempotent');
same('partial', $deliveryStatus(), 'first delivery can arrive before another parcel is sent');
same([], $deliveryLedger(), 'partial delivery cannot create a sale ledger entry');
denied(fn() => $app->dispatchShipment($north, $deliveryOrder, $east, 'demo', $trackingA, [$eastLine => 1], bin2hex(random_bytes(16))),
    'carrier tracking number must be unique');
$app->dispatchShipment($north, $deliveryOrder, $east, 'demo', $trackingB, [$eastLine => 1], bin2hex(random_bytes(16)));
same('partial', $deliveryStatus(), 'a single warehouse may send multiple parcels');
$app->dispatchShipment($north, $deliveryOrder, $east, 'demo', $trackingC, [$eastLine => 1], bin2hex(random_bytes(16)));
same('shipped', $deliveryStatus(), 'every item dispatched, waiting for delivery');
denied(fn() => $app->recordDelivery('demo', $trackingB, "delivered-a-$tag"), 'one carrier event cannot be reassigned to another package');
$app->recordDelivery('demo', $trackingC, "delivered-c-$tag");
same([], $deliveryLedger(), 'out-of-order delivery waits for the remaining parcel');
$receiptB = $app->recordDelivery('demo', $trackingB, "delivered-b-$tag");
same($receiptB, $app->recordDelivery('demo', $trackingB, "delivered-b-$tag"), 'replayed final event cannot create another sale');
same('fulfilled', $deliveryStatus(), 'last parcel completes the merchant order');
same([['kind' => 'sale', 'gross' => $multiPaidQuote['total']]], $deliveryLedger(), 'one sale ledger entry includes split shipping');
same([1, 1, 1], array_map('intval', array_column($app->shipments($deliveryOrder), 'units')), 'one shipment row per parcel');
same([[$main, 0, 0], [$east, 0, 0]], $multiBalance(), 'shipping does not deduct paid stock again');

$eastPickup = $app->savePickupLocation($north, 0, $east, 'EAST', '东区自提门店', '东区 10 号', true);
$eastStaff = $app->addShopMember($north, "north-east-$tag@example.test", $pw, 'pickup', $eastPickup);
$eastLocations = [$northShop => $eastPickup];
$app->adjustStock($north, $multiSku, 2, (int) $app->rows('SELECT version FROM products WHERE id=?', [$multiSku])[0]['version'], $east);
$eastQuote = $app->quote($buyer, [$multiSku => 1], 'store', '', pickupLocations: $eastLocations);
same([$east => 1], $eastQuote['items'][$multiSku]['allocation'], 'pickup selects the store inventory warehouse');
$app->savePickupLocation($north, $eastPickup, 0, 'EAST', '东区自提门店', '东区 12 号', true);
denied(fn() => $app->place($buyer, $eastQuote['token']), 'location address change requires a new quote');
$eastQuote = $app->quote($buyer, [$multiSku => 1], 'store', '', pickupLocations: $eastLocations);
$eastParent = $app->place($buyer, $eastQuote['token']);
$eastOrder = (int) $app->rows('SELECT id FROM merchant_orders WHERE order_id=?', [$eastParent])[0]['id'];
same([[$main, 0, 0], [$east, 2, 1]], $multiBalance(), 'pickup hold stays in the selected store inventory');
denied(fn() => $app->preparePickup($eastStaff, $eastOrder, $eastPickup), 'unpaid order cannot be prepared');
denied(fn() => $app->issuePickupCode($buyer, $eastOrder), 'unpaid order cannot reveal a code');
$app->demoPay($buyer, $eastParent, "east-pickup-$tag", $eastQuote['total']);
$app->savePickupLocation($north, $eastPickup, 0, 'EAST', '东区自提门店', '东区 16 号', false);
denied(fn() => $app->quote($buyer, [$multiSku => 1], 'store', '', pickupLocations: $eastLocations), 'closed location cannot take a new quote');
same('东区 12 号', $app->merchantPickupQueue($eastStaff)[0]['location_address'], 'paid order retains its address snapshot');
same(false, in_array($eastOrder, array_column($app->merchantPickupQueue($pickupStaff), 'id'), true),
    'store staff queue excludes another location of the same merchant');
denied(fn() => $app->preparePickup($pickupStaff, $eastOrder, $eastPickup), 'another location cannot prepare the order');
$app->preparePickup($eastStaff, $eastOrder, $eastPickup);
$eastCode = $app->issuePickupCode($buyer, $eastOrder);
same(hash('sha256', $eastCode), $app->rows('SELECT code_digest FROM order_pickups WHERE merchant_order_id=?', [$eastOrder])[0]['code_digest'],
    'database stores a digest rather than the redeemable code');
denied(fn() => $app->redeemPickup($pickupStaff, $eastOrder, $northPickup, $eastCode), 'another location cannot claim a valid code');
$competing = [$eastStaff, $eastOrder, $eastPickup, $eastCode];
same(['already-redeemed', 'redeemed'], compete('pickup-worker', [$competing, $competing]), 'simultaneous scans redeem only once');
same(1, count($app->rows("SELECT id FROM ledger WHERE merchant_order_id=? AND kind='sale'", [$eastOrder])),
    'simultaneous scans produce one sale');
same(1, count($app->rows("SELECT id FROM audit_log WHERE entity_id=? AND action='pickup.redeemed'", [$eastOrder])),
    'simultaneous scans record one successful audit event');
same([[$main, 0, 0], [$east, 1, 0]], $multiBalance(), 'pickup completion leaves the paid warehouse balance unchanged');

$raceRelease = $app->submitSku($north, 0, "R-$tag", '最后一件', 200, 1, $home, '并发用例', '', '', true, true);
$race = $app->reviewSku($admin, $raceRelease, true);
$q1 = $app->quote($buyer, [$race => 1], 'store', '', pickupLocations: $northLocations);
$q2 = $app->quote($otherBuyer, [$race => 1], 'store', '', pickupLocations: $northLocations);
$outcomes = compete('race-worker', [[$buyer, $q1['token']], [$otherBuyer, $q2['token']]]);
same(['placed', 'unavailable'], $outcomes, 'simultaneous checkout never oversells');
$holdingOrder = $app->rows('SELECT o.id,o.buyer_id FROM orders o JOIN merchant_orders m ON m.order_id=o.id
    JOIN order_items i ON i.merchant_order_id=m.id WHERE i.product_id=?', [$race])[0];
$expired = (int) $holdingOrder['id'];
$db->prepare('UPDATE orders SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=?')->execute([$expired]);
denied(fn() => $app->demoPay((int) $holdingOrder['buyer_id'], $expired, "expired-$tag", 200), 'late receipt cannot resurrect expired order');
same(true, $app->expireOrders() >= 1, 'expiry worker processed order');
same(0, (int) $app->rows('SELECT reserved FROM products WHERE id=?', [$race])[0]['reserved'], 'expired stock released');
same(false, $app->cancel($expired), 'expiry replay is a no-op');

$stale = $app->quote($buyer, [$b => 1], 'store', '', pickupLocations: $pondLocations);
$cupUpdate = $app->submitSku($pond, $b, "P-$tag", '池畔早餐杯', 8100, 0, $home, '池畔陶瓷', '湖蓝', '300ml', true, true, $cupRelease);
same(8000, (int) $app->catalog("P-$tag")[0]['price'], 'pending price does not overwrite the current release');
denied(fn() => $app->submitSku($pond, $b, "P-$tag", '旧页面', 1, 0, $home, '池畔陶瓷', '湖蓝', '300ml', true, true, $cupRelease),
    'stale edit cannot overwrite a newer request');
$app->reviewSku($admin, $cupUpdate, true);
denied(fn() => $app->place($buyer, $stale['token']), 'price changes invalidate quote');
$redQuote = $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: $northLocations);
$redUpdate = $app->submitSku($north, $a, "N-$tag", '北仓帆布包', 12000, 0, $bags, '北仓帆布包', '朱红', 'M', true, true, $redRelease,
    memberPrice: 10000);
$app->reviewSku($admin, $redUpdate, true);
denied(fn() => $app->place($buyer, $redQuote['token']), 'same-price variant revision invalidates quote');
same('红色', $app->rows('SELECT r.color FROM order_items i JOIN product_releases r ON r.id=i.product_release_id WHERE i.id=?', [$aItem])[0]['color'],
    'historical line retains the original approved attributes');
$newQuote = $app->quote($buyer, [$b => 1], 'store', '', pickupLocations: $pondLocations);
$newOrder = $app->place($buyer, $newQuote['token']);
denied(fn() => $app->demoPay($buyer, $newOrder, "pay-$tag", 8100), 'gateway reference unique across orders');
same(true, $app->cancel($newOrder, $buyer), 'buyer can release an unpaid order');

$intent = static fn(int $id): string => $app->rows('SELECT payment_token FROM orders WHERE id=?', [$id])[0]['payment_token'];
$receipt = static function (string $token, string $reference, int $amount, string $currency = 'CNY') use ($app): array {
    $digest = hash('sha256', json_encode([$token, $reference, $amount, $currency], JSON_THROW_ON_ERROR));
    return $app->recordSandboxReceipt($token, $reference, $currency, $amount, $digest);
};
$resaleQuote = $app->quote($otherBuyer, [$race => 1], 'store', '', pickupLocations: $northLocations);
$resaleOrder = $app->place($otherBuyer, $resaleQuote['token']);
$wrongReceipt = $receipt($intent($resaleOrder), "currency-$tag", 200, 'USD');
same('needs_review', $wrongReceipt['status'], 'currency discrepancy requires review');
same('unpaid', $app->rows('SELECT status FROM orders WHERE id=?', [$resaleOrder])[0]['status'], 'currency mismatch leaves order unpaid');
same('needs_review', $receipt(str_repeat('0', 32), "unknown-$tag", 200)['status'], 'unknown payment intent retained for review');
$accepted = $receipt($intent($resaleOrder), "callback-$tag", 200);
same('accepted', $accepted['status'], 'signed receipt workflow accepts matching capture');
same($accepted, $receipt($intent($resaleOrder), "callback-$tag", 200), 'second delivery returns original payment');
same($accepted, $receipt($intent($resaleOrder), "callback-$tag", 200), 'third delivery returns original payment');
denied(fn() => $receipt($intent($resaleOrder), "callback-$tag", 201), 'same channel reference cannot change payload');
same(1, (int) $app->rows('SELECT COUNT(*) AS total FROM payments WHERE order_id=?', [$resaleOrder])[0]['total'],
    'three identical callbacks record exactly one payment');
$duplicate = $receipt($intent($resaleOrder), "overpaid-$tag", 200);
same('refund_required', $duplicate['status'], 'second capture with distinct reference requires refund');
$late = $receipt($intent($expired), "late-$tag", 200);
same('refund_required', $late['status'], 'late capture after resale requires refund');
same('cancelled', $app->rows('SELECT status FROM orders WHERE id=?', [$expired])[0]['status'], 'late capture cannot revive cancelled order');
same([0, 0], array_map('intval', array_values($app->rows('SELECT stock,reserved FROM products WHERE id=?', [$race])[0])),
    'late capture leaves resold inventory unchanged');
denied(fn() => $app->recordSandboxRefund($admin, $late['receipt_id'], "refund-$tag"), 'only finance records exception refunds');
$app->recordSandboxRefund($finance, $late['receipt_id'], "refund-$tag");
$app->recordSandboxRefund($finance, $late['receipt_id'], "refund-$tag");
same('refunded', $receipt($intent($expired), "late-$tag", 200)['status'], 'late capture replay reports current refund progress');
denied(fn() => $app->recordSandboxRefund($finance, $duplicate['receipt_id'], "refund-$tag"), 'refund reference cannot resolve two receipts');

$unprocessed = $app->place($buyer, $app->quote($buyer, [$b => 1], 'store', '', pickupLocations: $pondLocations)['token']);
$db->prepare('UPDATE orders SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=?')->execute([$unprocessed]);
same('refund_required', $receipt($intent($unprocessed), "expires-before-worker-$tag", 8100)['status'],
    'late receipt before worker recorded for refund');
same(false, $app->cancel($unprocessed), 'late receipt closed expired order and released its hold');
same(0, (int) $app->rows('SELECT reserved FROM products WHERE id=?', [$b])[0]['reserved'], 'late receipt releases expired stock once');
putenv('APP_DEMO=0');
denied(fn() => $app->demoPay($buyer, $order, "pay-$tag", 18000), 'demo payment requires opt-in');
denied(fn() => $receipt($intent($resaleOrder), "disabled-$tag", 200), 'webhook processing requires sandbox opt-in');

echo "$checks integration checks passed.\n";
