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
if (in_array($worker, ['race-worker', 'pickup-worker', 'preorder-worker', 'settlement-worker', 'bill-worker', 'freight-worker',
    'refund-worker'], true)) {
    echo "ready\n";
    fgets(STDIN);
    try {
        if ($worker === 'race-worker') {
            $app->place((int) $argv[2], $argv[3]);
            echo "placed\n";
        } elseif ($worker === 'pickup-worker') {
            echo $app->redeemPickup((int) $argv[2], (int) $argv[3], (int) $argv[4], $argv[5]) ? "redeemed\n" : "already-redeemed\n";
        } elseif ($worker === 'settlement-worker') {
            echo 'frozen-' . $app->freezeSettlement((int) $argv[2], (int) $argv[3], $argv[4], 'CNY', $argv[5]) . "\n";
        } elseif ($worker === 'bill-worker') {
            echo 'checked-' . $app->processChannelBills() . "\n";
        } elseif ($worker === 'freight-worker') {
            $app->decideReturnFreight((int) $argv[2], (int) $argv[3], (int) $argv[4], '原运费退款');
            echo "saved\n";
        } elseif ($worker === 'refund-worker') {
            $app->recordReturnReceipt($argv[2], $argv[3], $argv[4], 'CNY', (int) $argv[5]);
            echo "refunded\n";
        } elseif ($argv[2] === 'receive') {
            $app->receivePreorder((int) $argv[3], (int) $argv[4], (int) $argv[5]);
            echo "received\n";
        } else {
            $app->demoPay((int) $argv[3], (int) $argv[4], $argv[5], (int) $argv[6]);
            echo "paid\n";
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
$payout = $app->provisionOperator("payout-$tag@example.test", $pw, 'payout');
$calendar = new DateTimeImmutable(gmdate('Y-m-01'), new DateTimeZone('UTC'));
$currentPeriod = $calendar->format('Y-m');
$deliveryPeriod = $calendar->modify('-1 month')->format('Y-m');
$openingPeriod = $calendar->modify('-2 months')->format('Y-m');
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
$houseShop = (int) $app->profile($house)['shop_id'];
same('platform', $app->rows('SELECT kind FROM shops WHERE id=?', [$houseShop])[0]['kind'],
    'platform shop has distinct operating model');
$app->reviewShop($admin, $houseShop, true);
$db->prepare('UPDATE shops SET payee_name=NULL,agreement_version=NULL WHERE id=?')->execute([$houseShop]);
$app->submitShopAgreement($house, '补录自营主体');
denied(fn() => $app->reviewLegacyPayee($finance, $houseShop, '补录自营主体', true), 'finance cannot approve legal payee');
denied(fn() => $app->reviewLegacyPayee($admin, $houseShop, '旧页面主体名', true), 'payee review cannot confirm stale application');
same(['status' => 'active', 'payee_name' => null], $app->rows('SELECT status,payee_name FROM shops WHERE id=?', [$houseShop])[0],
    'legacy shop stays active before legal payee review');
$app->reviewLegacyPayee($admin, $houseShop, '补录自营主体', true);
same(['status' => 'active', 'payee_name' => '补录自营主体'], $app->rows('SELECT status,payee_name FROM shops WHERE id=?', [$houseShop])[0],
    'legacy legal payee is verified without changing shop status');
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
$placedAt = $app->rows('SELECT created_at FROM orders WHERE id=?', [$order])[0]['created_at'];
$orderDay = substr($placedAt, 0, 10);
$shopHistory = $app->merchantOrderHistory($fulfillmentStaff, $orderDay, $orderDay);
same([$northOrder], array_map('intval', array_column($shopHistory['rows'], 'id')), 'order history is scoped to the fulfillment member shop');
same($placedAt, $shopHistory['rows'][0]['placed_at'], 'child history keeps the parent UTC timestamp');
same(null, $shopHistory['next'], 'one order needs no history cursor');
same([$pondOrder], array_map('intval', array_column($app->merchantOrderHistory($pond, $orderDay, $orderDay)['rows'], 'id')),
    'another owner sees its own child order');
denied(fn() => $app->merchantOrderHistory($pickupStaff, $orderDay, $orderDay), 'pickup staff cannot browse all shop orders');
denied(fn() => $app->merchantOrderHistory($north, '2026-02-30', $orderDay), 'order history rejects invalid calendar dates');
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
denied(fn() => $app->reviewReturn($pond, $refund, $foreignWarehouse, '池畔仓', ''), 'refund approval ownership');
denied(fn() => $app->reviewReturn($fulfillmentStaff, $refund, $main, '北仓园区', ''), 'fulfillment staff cannot approve a return');
denied(fn() => $app->reviewReturn($supportStaff, $refund, $foreignWarehouse, '池畔仓', ''), 'returns use a warehouse at the selling shop');
denied(fn() => $app->reviewReturn($supportStaff, $refund, $main, '', ''), 'return destination needs an address');
$app->reviewReturn($supportStaff, $refund, $main, '北仓园区', '');
$app->reviewReturn($north, $refund, $main, '北仓园区', '');
denied(fn() => $app->decideReturnFreight($supportStaff, $refund, 1, '补偿'), 'pickup has no paid freight to refund');
denied(fn() => $app->reviewReturn($north, $refund, $main, '其他地址', ''), 'review replay cannot change the destination');
denied(fn() => $app->inspectReturn($fulfillmentStaff, $refund, 1, 1, ''), 'inspection requires a dispatched return');
denied(fn() => $app->dispatchReturn($otherBuyer, $refund, 'demo', "return-$tag"), 'another buyer cannot register the parcel');
$app->dispatchReturn($buyer, $refund, 'DEMO', "return-$tag");
$app->dispatchReturn($buyer, $refund, 'demo', "return-$tag");
denied(fn() => $app->dispatchReturn($buyer, $refund, 'demo', "other-return-$tag"), 'registered tracking cannot change on replay');
denied(fn() => $app->inspectReturn($supportStaff, $refund, 1, 1, ''), 'support staff cannot receive stock');
denied(fn() => $app->inspectReturn($fulfillmentStaff, $refund, 1, 2, ''), 'restock cannot exceed accepted quantity');
$app->inspectReturn($fulfillmentStaff, $refund, 1, 1, '');
$app->inspectReturn($fulfillmentStaff, $refund, 1, 1, '');
same(10, (int) $app->rows('SELECT stock FROM products WHERE id=?', [$a])[0]['stock'], 'sellable unit returns to inventory once');
same(10, (int) $app->rows('SELECT stock FROM warehouse_stock WHERE product_id=? AND warehouse_id=?', [$a, $main])[0]['stock'],
    'inspection restocks the designated warehouse');
same([['kind' => 'sale']], $app->rows('SELECT kind FROM ledger WHERE merchant_order_id=?', [$northOrder]),
    'inspection does not book a refund before channel confirmation');
$returnDetails = static fn(int $id): array => $app->rows('SELECT * FROM refunds WHERE id=?', [$id])[0];
$returnToken = $returnDetails($refund)['refund_token'];
$returnReceipt = static fn(string $reference, int $amount, string $payment = ''): array =>
    $app->recordReturnReceipt($returnToken, $reference, $payment ?: "pay-$tag", 'CNY', $amount);
$postDate = $db->prepare('UPDATE ledger SET created_at=? WHERE merchant_order_id=?');
$postDate->execute([$openingPeriod . '-10 12:00:00', $northOrder]);
$postDate->execute([$openingPeriod . '-10 12:00:00', $pondOrder]);
$staleSettlement = $app->previewSettlement($finance, $northShop, $openingPeriod, 'CNY');
same(9720, $staleSettlement['net'], 'sale preview before the merchant return is confirmed');
denied(fn() => $returnReceipt("return-paid-$tag", 10801), 'channel cannot exceed the authorized amount');
denied(fn() => $returnReceipt("return-paid-$tag", 10800, "unrelated-$tag"), 'refund is bound to the original payment');
same(['refund_id' => $refund, 'status' => 'refunded'], $returnReceipt("return-paid-$tag", 10800), 'accepted return refunds 108');
same(['refund_id' => $refund, 'status' => 'refunded'], $returnReceipt("return-paid-$tag", 10800), 'signed refund repeat has no side effects');
denied(fn() => $returnReceipt("changed-ref-$tag", 10800), 'confirmed intent cannot be assigned to another refund receipt');
same($fulfillmentStaff, (int) $returnDetails($refund)['inspected_by'], 'return history preserves the inspector');
$db->prepare('UPDATE ledger SET created_at=? WHERE event_key=?')->execute([$openingPeriod . '-16 12:00:00', 'refund-' . $refund]);
$ledger = $app->rows('SELECT gross,commission FROM ledger WHERE merchant_order_id=? ORDER BY id', [$northOrder]);
same([[10800,1080],[-10800,-1080]], array_map(static fn(array $row): array => array_map('intval', array_values($row)), $ledger),
    'gross and commission reversals balance');
denied(fn() => $app->previewSettlement($buyer, $pondShop, $openingPeriod, 'CNY'), 'settlement authorization');
denied(fn() => $app->previewSettlement($finance, $pondShop, '2026-15', 'CNY'), 'invalid calendar month rejected');
denied(fn() => $app->previewSettlement($finance, $pondShop, $openingPeriod, 'USD'), 'currency must match ledger');
denied(fn() => $app->freezeSettlement($finance, $northShop, $openingPeriod, 'CNY', $staleSettlement['hash']),
    'newly posted refund invalidates the earlier preview');
$northPreview = $app->previewSettlement($finance, $northShop, $openingPeriod, 'CNY');
$pondPreview = $app->previewSettlement($finance, $pondShop, $openingPeriod, 'CNY');
same([10800, 10800, 0, 0, 2], [$northPreview['sales'], $northPreview['refunds'], $northPreview['commission'],
    $northPreview['net'], $northPreview['entries']], 'net zero statement separately reports the returned sale');
same([7200, 0, 720, 6480], [$pondPreview['sales'], $pondPreview['refunds'], $pondPreview['commission'], $pondPreview['net']],
    'another merchant preview excludes the returned sale');
denied(fn() => $app->freezeSettlement($admin, $pondShop, $openingPeriod, 'CNY', $pondPreview['hash']), 'operations cannot freeze');
$freeze = static fn(int $actor, int $shop, string $month, array $preview): int =>
    $app->freezeSettlement($actor, $shop, $month, 'CNY', $preview['hash']);
$northBatch = $freeze($finance, $northShop, $openingPeriod, $northPreview);
$jobs = array_fill(0, 2, [$finance, $pondShop, $openingPeriod, $pondPreview['hash']]);
$frozen = compete('settlement-worker', $jobs);
$pondBatch = (int) substr($frozen[0], strlen('frozen-'));
same(["frozen-$pondBatch", "frozen-$pondBatch"], $frozen, 'concurrent confirmation returns the same frozen statement');
same([0, 6480], array_map('intval', array_column($app->rows('SELECT amount FROM settlements WHERE id IN (?,?) ORDER BY id',
    [$northBatch, $pondBatch]), 'amount')), 'finance settlement matches PRD example');
same($pondBatch, $freeze($finance, $pondShop, $openingPeriod, $pondPreview), 'identical submission returns the frozen statement');
denied(fn() => $app->freezeSettlement($finance, $pondShop, $openingPeriod, 'CNY', str_repeat('0', 64)), 'closed month rejects a changed snapshot');
denied(fn() => $app->settlementDetail($payout, $pondBatch), 'cashier queue excludes statements pending review');
denied(fn() => $app->recordSettlementTransfer($payout, $pondBatch, "bank-$tag", 6480), 'cashier cannot pay an unreviewed statement');
denied(fn() => $app->reviewSettlement($payout, $pondBatch, 6480), 'cashier cannot review a statement');
denied(fn() => $app->reviewSettlement($finance, $pondBatch, 6481), 'review must reconcile the exact amount');
$app->reviewSettlement($finance, $northBatch, 0);
same('cleared', $app->settlementDetail($finance, $northBatch)['state'], 'zero statement clears without a bank transfer');
$app->reviewSettlement($finance, $pondBatch, 6480);
same([$pondBatch], array_map('intval', array_column($app->settlementList($payout, $pondShop), 'id')), 'cashier sees reviewed positive statements');
denied(fn() => $app->recordSettlementTransfer($finance, $pondBatch, "bank-$tag", 6480), 'finance cannot register a bank transfer');
denied(fn() => $app->recordSettlementTransfer($payout, $pondBatch, "bank-$tag", 6479), 'bank transfer must match reviewed amount');
$app->recordSettlementTransfer($payout, $pondBatch, "bank-$tag", 6480);
$app->recordSettlementTransfer($payout, $pondBatch, "bank-$tag", 6480);
denied(fn() => $app->recordSettlementTransfer($payout, $pondBatch, "another-bank-$tag", 6480), 'bank receipt cannot be overwritten');
$pondStatement = $app->settlementDetail($financeStaff, $pondBatch);
same(['paid', $finance, $payout, 6480], [$pondStatement['state'], (int) $pondStatement['reviewed_by'],
    (int) $pondStatement['paid_by'], (int) $pondStatement['amount']], 'statement preserves both operators and approved amount');
$pondCsv = iterator_to_array($app->exportSettlement($financeStaff, $pondBatch), false);
same([$pondShop, $openingPeriod, $pondBatch, 'sale', 'CNY', 7200, 720, 6480], [
    (int) $pondCsv[1][0], $pondCsv[1][1], (int) $pondCsv[1][2], $pondCsv[1][6], $pondCsv[1][7],
    (int) $pondCsv[1][8], (int) $pondCsv[1][9], $pondCsv[1][10]], 'CSV identifies shop, month, source and amount in cents');
denied(fn() => iterator_to_array($app->exportSettlement($north, $pondBatch)), 'merchant cannot export another shop statement');
denied(fn() => iterator_to_array($app->exportSettlement($payout, $pondBatch)), 'cashier cannot export source orders');
same([$northOrder, $northOrder], array_map('intval', array_column($app->merchantLedger($north), 'merchant_order_id')),
    'shop A statement contains only its shop orders while suspended');
same([$pondOrder], array_map('intval', array_column($app->merchantLedger($financeStaff), 'merchant_order_id')),
    'shop B statement contains only its shop orders');
denied(fn() => $app->merchantLedger($catalogStaff), 'product staff cannot read financial records');
denied(fn() => $freeze($financeStaff, $pondShop, $openingPeriod, $pondPreview), 'shop finance cannot freeze a settlement');
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
denied(fn() => $app->publishPromotion($admin, "auto-$tag", '自动折扣', 20000, 2000, false, false, refundCoupon: true),
    'automatic discounts cannot be restored as a coupon');
$couponVersion = $app->publishPromotion($admin, "cross-$tag", '跨店不叠加券', 20000, 2000, true, false, refundCoupon: true);
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
$freeVersion = $app->publishPromotion($admin, "free-$tag", '全额优惠验收', 20000, 20000, true, false, refundCoupon: true);
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
$multiRelease = $app->submitSku($north, 0, "WH-$tag", '分仓验收商品', 1207, 1, $bags, '分仓商品', '', '', true, true);
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

$postDate->execute([$deliveryPeriod . '-11 12:00:00', $deliveryOrder]);
$deliveryPreview = $app->previewSettlement($finance, $northShop, $deliveryPeriod, 'CNY');
same((int) $multiPaidQuote['total'], $deliveryPreview['sales'], 'new period freezes only sales posted before its cutoff');
$deliveryBatch = $freeze($finance, $northShop, $deliveryPeriod, $deliveryPreview);
$batchSnapshot = static fn(): array => $app->rows('SELECT s.*,l.gross,l.commission FROM settlements s
    JOIN ledger l ON l.settlement_id=s.id WHERE s.id=? ORDER BY l.id', [$deliveryBatch]);
$settledBeforeReturn = $batchSnapshot();
$deliveryItem = (int) $app->rows('SELECT id FROM order_items WHERE merchant_order_id=?', [$deliveryOrder])[0]['id'];
$openReturn = static fn(int $quantity): int =>
    $app->requestRefund($buyer, $deliveryItem, $quantity, '退回分仓商品', bin2hex(random_bytes(16)));
$firstReturn = $openReturn(2);
denied(fn() => $openReturn(2), 'overlapping applications cannot exceed the purchased units');
$app->reviewReturn($supportStaff, $firstReturn, $east, '', '');
same('示例东区配送中心', $returnDetails($firstReturn)['return_address'], 'review snapshots the configured warehouse address');
denied(fn() => $app->decideReturnFreight($pond, $firstReturn, 800, '送达延误'), 'other shop cannot decide freight');
denied(fn() => $app->decideReturnFreight($fulfillmentStaff, $firstReturn, 800, '送达延误'), 'warehouse cannot decide freight');
denied(fn() => $app->decideReturnFreight($supportStaff, $firstReturn, 1601, '送达延误'), 'freight decision cannot exceed original shipping');
$app->decideReturnFreight($supportStaff, $firstReturn, 800, '送达延误');
$app->decideReturnFreight($supportStaff, $firstReturn, 800, '送达延误');
same([800, 'merchant', $supportStaff], [(int) $returnDetails($firstReturn)['freight_amount'],
    $returnDetails($firstReturn)['freight_payer'], (int) $returnDetails($firstReturn)['freight_decided_by']],
    'merchant-funded decision records exact amount and operator');
denied(fn() => $app->decideReturnFreight($finance, $firstReturn, 800, '平台运费'), 'finance cannot replace a merchant decision');
$app->dispatchReturn($buyer, $firstReturn, 'demo', "r-one-$tag");
denied(fn() => $app->inspectReturn($fulfillmentStaff, $firstReturn, 1, 1, ''), 'short shipment requires an inspection note');
$app->inspectReturn($fulfillmentStaff, $firstReturn, 1, 1, '包裹仅收到一件');
denied(fn() => $app->decideReturnFreight($supportStaff, $firstReturn, 0, '撤回运费'), 'accepted inspection freezes freight');
$refusedReturn = $openReturn(2);
$app->reviewReturn($supportStaff, $refusedReturn, null, '', '请按实际寄回件数重新申请');
$emptyReturn = $openReturn(2);
$app->reviewReturn($supportStaff, $emptyReturn, $east, '', '');
$app->decideReturnFreight($finance, $emptyReturn, 800, '分仓延误');
$app->dispatchReturn($buyer, $emptyReturn, 'demo', "r-empty-$tag");
$app->inspectReturn($fulfillmentStaff, $emptyReturn, 0, 0, '空包裹');
same([0, 800, 'rejected'], [(int) $returnDetails($emptyReturn)['amount'],
    (int) $returnDetails($emptyReturn)['freight_amount'], $returnDetails($emptyReturn)['status']],
    'rejected inspection releases the hold and preserves its decision');
$secondReturn = $openReturn(2);
$app->reviewReturn($supportStaff, $secondReturn, $east, '', '');
denied(fn() => $app->decideReturnFreight($finance, $secondReturn, 801, '配送延误'), 'pending receipt holds freight against the suborder cap');
$app->decideReturnFreight($supportStaff, $secondReturn, 800, '配送延误');
$app->decideReturnFreight($supportStaff, $secondReturn, 0, '平台接手');
$app->decideReturnFreight($finance, $secondReturn, 800, '平台配送延误补偿');
denied(fn() => $app->decideReturnFreight($supportStaff, $secondReturn, 0, '撤回运费'), 'merchant cannot withdraw platform funding');
denied(fn() => $app->dispatchReturn($buyer, $secondReturn, 'demo', "r-one-$tag"), 'one return parcel cannot be reused');
$app->dispatchReturn($buyer, $secondReturn, 'demo', "r-two-$tag");
$app->inspectReturn($fulfillmentStaff, $secondReturn, 2, 0, '两件商品破损，按验收结果退款');
same([[$main, 0, 0], [$east, 1, 0]], $multiBalance(), 'unsellable accepted units are not returned to available stock');
$firstPending = $returnDetails($firstReturn);
$secondPending = $returnDetails($secondReturn);
same([2007, 120, 3214, 242], [(int) $firstPending['amount'], (int) $firstPending['commission'],
    (int) $secondPending['amount'], (int) $secondPending['commission']], 'freight joins the rounded refund without commission');
denied(fn() => $openReturn(1), 'both accepted returns remain reserved while waiting for confirmation');
denied(fn() => $app->recordReturnReceipt($secondPending['refund_token'], "r-paid-two-$tag", "multi-$tag", 'CNY', 2414),
    'channel receipt must include approved freight');
$app->recordReturnReceipt($secondPending['refund_token'], "r-paid-two-$tag", "multi-$tag", 'CNY', 3214);
denied(fn() => $app->recordReturnReceipt($firstPending['refund_token'], "r-paid-two-$tag", "multi-$tag", 'CNY', 2007),
    'a channel reference cannot confirm two intents');
$app->recordReturnReceipt($firstPending['refund_token'], "r-paid-one-$tag", "multi-$tag", 'CNY', 2007);
same(3, (int) $app->rows('SELECT refunded_quantity FROM order_items WHERE id=?', [$deliveryItem])[0]['refunded_quantity'],
    'out-of-order channel confirmations complete the line once');
same($settledBeforeReturn, $batchSnapshot(), 'late refund leaves the historical batch and its sale unchanged');
same([-4059, 2], array_map('intval', array_values($app->rows('SELECT SUM(' . Commerce::LEDGER_NET . '),COUNT(*) FROM ledger
    WHERE merchant_order_id=? AND settlement_id IS NULL', [$deliveryOrder])[0])), 'merchant bears only its own freight decision');
$carry = $app->previewSettlement($finance, $northShop, $currentPeriod, 'CNY');
same([-4059, 5221, -362, 800], [$carry['net'], $carry['refunds'], $carry['commission'], $carry['platform_freight']],
    'current month picks up the prior reversal and platform funding');
denied(fn() => $freeze($finance, $northShop, $currentPeriod, $carry), 'negative net from a settled sale rolls into the next batch');

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

$newBillQuote = $app->quote($buyer, [$a => 1], 'store', '', pickupLocations: $northLocations);
$newBillOrder = $app->place($buyer, $newBillQuote['token']);
$billHeader = "kind,order_id,reference,currency,amount_minor\r\n";
$statement = "\xEF\xBB\xBF" . $billHeader . implode("\r\n", [
    "capture,$resaleOrder,callback-$tag,CNY,200",
    "capture,$resaleOrder,callback-$tag,CNY,200",
    "return_refund,$order,\"return-paid-$tag\",CNY,10800",
    "exception_refund,$expired,refund-$tag,CNY,200",
    "capture,$resaleOrder,currency-$tag,CNY,200",
    "capture,$resaleOrder,overpaid-$tag,CNY,200",
    "capture,$resaleOrder,late-$tag,CNY,200",
    "capture,$newBillOrder,bill-late-$tag,CNY,{$newBillQuote['total']}",
    "capture,$resaleOrder,conflict-$tag,CNY,200",
    "capture,$resaleOrder,conflict-$tag,CNY,201",
    "capture,$resaleOrder,\"unterminated,CNY,200",
    "capture,$resaleOrder,=invalid,CNY,200",
]) . "\r\n";
denied(fn() => $app->importChannelBill($buyer, $statement), 'buyer cannot submit channel evidence');
denied(fn() => $app->importChannelBill($supportStaff, $statement), 'shop support cannot submit financial evidence');
denied(fn() => $app->importChannelBill($finance, "invalid_header\n"), 'file without required columns rejected');
denied(fn() => $app->importChannelBill($finance, $billHeader . str_repeat("capture,1,x,CNY,100\n", 1001)), 'row count bound enforced');
denied(fn() => $app->importChannelBill($finance, $billHeader . str_repeat('x', Commerce::CHANNEL_BILL_MAX_BYTES)),
    'oversized bill rejected before parsing');
$billId = $app->importChannelBill($finance, $statement);
same($billId, $app->importChannelBill($finance, $statement), 'identical upload recovers the same task');
$billRows = static fn(int $actor, int $bill): array => $app->channelBillDetails($actor, $bill)['rows'];
same(['queued','duplicate','queued','queued','queued','queued','queued','queued','conflict','conflict','invalid','invalid'],
    array_column($billRows($finance, $billId), 'status'), 'import preserves original row order and reports malformed and repeated records');
$shopBill = $app->importChannelBill($north, $billHeader . "capture,$unprocessed,expires-before-worker-$tag,CNY,8100\n"
    . "return_refund,$order,return-paid-$tag,CNY,10800\n");
denied(fn() => $app->channelBillDetails($pond, $shopBill), 'merchant cannot see another shop task');
denied(fn() => $app->channelBillDetails($north, $billId), 'merchant cannot read platform statement');
denied(fn() => $app->retryChannelBill($north, $shopBill), 'only platform finance requeues statement checks');
$processed = compete('bill-worker', [[], []]);
same(9, array_sum(array_map(static fn(string $result): int => (int) substr($result, strlen('checked-')), $processed)),
    'independent workers only process the nine queued records once');
$financeRows = $billRows($finance, $billId);
same(['matched','duplicate','matched','matched','mismatch','receipt_exception','mismatch','awaiting_receipt','conflict','conflict','invalid','invalid'],
    array_column($financeRows, 'status'), 'batch compares three receipt classes and preserves distinct differences');
same(['amount_or_currency_mismatch', 'payment_receipt_exception', 'order_mismatch'],
    array_column(array_slice($financeRows, 4, 3), 'result_code'), 'reconciliation labels show the reason for each difference');
same([$accepted['receipt_id'], $refund, $late['receipt_id']], array_map('intval',
    [$financeRows[0]['local_id'], $financeRows[2]['local_id'], $financeRows[3]['local_id']]), 'matches point to existing evidence');
same(['outside_shop', 'matched'], array_column($billRows($finance, $shopBill), 'status'), 'shop origin limits worker matching scope');
same([['reviewed', null, null], ['reviewed', null, null]], array_map(static fn(array $row): array =>
    [$row['status'], $row['result_code'], $row['local_id']], $billRows($north, $shopBill)), 'shop view does not reveal platform-only matching evidence');
same('unpaid', $app->rows('SELECT status FROM orders WHERE id=?', [$newBillOrder])[0]['status'], 'CSV cannot confirm payment');
same(0, (int) $app->rows('SELECT COUNT(*) AS count FROM payments WHERE order_id=?', [$newBillOrder])[0]['count'],
    'CSV adds no payment row');
same('accepted', $receipt($intent($newBillOrder), "bill-late-$tag", $newBillQuote['total'])['status'],
    'ordinary signed callback confirms the payment later');
same(1, $app->retryChannelBill($finance, $billId), 'retry queues only records for missing evidence');
same(0, $app->retryChannelBill($finance, $billId), 'repeat retry leaves already queued evidence alone');
same(1, $app->processChannelBills(), 'worker processes the single requeued record');
same('matched', $billRows($finance, $billId)[7]['status'], 'late signed receipt resolves the missing record');
same(0, $app->processChannelBills(), 'worker replay leaves completed records alone');
$largeStatement = $billHeader;
for ($number = 1; $number <= 102; $number++) {
    $largeStatement .= "capture,$resaleOrder,page-$tag-$number,CNY,200\n";
}
$largeBill = $app->importChannelBill($finance, $largeStatement);
same(100, $app->processChannelBills(), 'worker caps each transaction at 100 records');
same(['awaiting_receipt' => 100, 'queued' => 2], array_count_values(array_column($billRows($finance, $largeBill), 'status')),
    'partially processed import has durable progress');
same(2, $app->processChannelBills(), 'next worker run resumes the remaining rows');

$preRelease = $app->submitSku($north, 0, "PRE-$tag", '未来批次', 700, 0, $home, '预售回归', '', '', true, true);
$preSku = $app->reviewSku($admin, $preRelease, true);
$cutoff = gmdate('Y-m-d\TH:i', time() + 7200);
$expected = gmdate('Y-m-d', time() + 172800);
denied(fn() => $app->createPreorder($north, $preSku, $foreignWarehouse, 'CROSS', 5, $cutoff, $expected),
    'preorder batch requires a warehouse of the SKU shop');
denied(fn() => $app->createPreorder($fulfillmentStaff, $preSku, $east, 'ROLE', 5, $cutoff, $expected),
    'batch creation requires catalog permission');
denied(fn() => $app->createPreorder($north, $preSku, $east, 'DATE', 5, $cutoff, '2000-01-01'),
    'arrival date cannot precede the cutoff');
$batch = $app->createPreorder($catalogStaff, $preSku, $east, 'LOT-A', 5, $cutoff, $expected);
$preorder = [$preSku => $batch];
$preStock = static fn(): array => array_values($app->rows('SELECT stock,reserved FROM products WHERE id=?', [$preSku])[0]);
$quota = static fn(int $id): array => array_values($app->rows('SELECT held,sold FROM preorder_batches WHERE id=?', [$id])[0]);
$childOrder = static fn(int $parent): int => (int) $app->rows('SELECT id FROM merchant_orders WHERE order_id=?', [$parent])[0]['id'];
$childStatus = static fn(int $child): string => $app->rows('SELECT status FROM merchant_orders WHERE id=?', [$child])[0]['status'];
denied(fn() => $app->createPreorder($north, $preSku, $east, 'lot-a', 5, $cutoff, $expected), 'same SKU lot cannot be published twice');
denied(fn() => $app->quote($buyer, [$preSku => 1], 'web', '预售测试地址'), 'preorder quota does not appear as physical stock');
denied(fn() => $app->setCartLine($buyer, $a, 1, true, preorderBatch: $batch), 'batch selection belongs to the given SKU');
denied(fn() => $app->quote($buyer, [$preSku => 6], 'web', '预售测试地址', preorders: $preorder), 'quota is checked at quote');
denied(fn() => $app->quote($buyer, [$preSku => 1], 'store', '', pickupLocations: $northLocations, preorders: $preorder),
    'preorder cannot use a different store inventory');
$app->savePickupLocation($north, $eastPickup, 0, 'EAST', '东区自提门店', '东区 16 号', true);
same('东区自提门店', $app->catalogPreorders([$preSku], 'store')[0]['pickup_name'], 'displays batch availability in selected channel');
$app->setCartLine($buyer, $preSku, 1, true, preorderBatch: $batch);
$line = array_column($app->cartLines($buyer), null, 'product_id')[$preSku];
same('LOT-A', $line['preorder_code'], 'cart persists the exact batch');
$app->setCartLine($buyer, $preSku, 2, true, $line['revision']);
$cartPre = $app->quoteCart($buyer, 'web', '预售测试地址');
same([$preSku => $batch], $cartPre['preorders'], 'quantity edit preserves preorder choice');
$app->setCartLine($buyer, $preSku, 0, false);
denied(fn() => $app->place($buyer, $cartPre['token'], true), 'cart mode changes invalidate earlier quote');
$mixedQuote = $app->quote($buyer, [$a => 1, $preSku => 2], 'web', '预售测试地址', preorders: $preorder);
same(1600, $mixedQuote['shipping'], 'preorder and current stock ship from their assigned warehouses');
$mixedOrder = $app->place($buyer, $mixedQuote['token']);
$mixedChild = $childOrder($mixedOrder);
$pickupQuote = $app->quote($buyer, [$preSku => 1], 'store', '', pickupLocations: $eastLocations, preorders: $preorder);
$prePickup = $app->place($buyer, $pickupQuote['token']);
$prePickupChild = $childOrder($prePickup);
$waiting = $app->quote($otherBuyer, [$preSku => 1], 'web', '预售测试地址', preorders: $preorder);
$payAfterArrival = $app->place($otherBuyer, $waiting['token']);
$otherWaiting = $app->quote($otherBuyer, [$preSku => 1], 'web', '预售测试地址', preorders: $preorder);
$cancelAfterArrival = $app->place($otherBuyer, $otherWaiting['token']);
same([5, 0], $quota($batch), 'pending orders hold their exact batch capacity');
same([0, 0], $preStock(), 'batch holds do not change physical inventory');
$app->demoPay($buyer, $mixedOrder, "mixed-$tag", $mixedQuote['total']);
$app->demoPay($buyer, $prePickup, "pre-pickup-$tag", $pickupQuote['total']);
same([2, 3], $quota($batch), 'payment consumes quota while warehouse is not received');
same([0, 0], $preStock(), 'early payment does not deduct unavailable physical units');
same([], $app->catalogPreorders([$preSku], 'web'), 'sold or held-out batch is hidden on the shelf');
denied(fn() => $app->preparePickup($eastStaff, $prePickupChild, $eastPickup), 'paid preorder cannot be prepared before arrival');
$normalLine = $app->unshippedLines($mixedChild)[0];
same($main, $normalLine['warehouse_id'], 'only current stock is dispatchable before receipt');
$trackingStock = "PR-STOCK-$tag";
$app->dispatchShipment($north, $mixedChild, $main, 'demo', $trackingStock, [$normalLine['id'] => 1], bin2hex(random_bytes(16)));
$app->recordDelivery('demo', $trackingStock, "pre-stock-$tag");
same('partial', $childStatus($mixedChild), 'ship and deliver current stock while preorder remains open');
same([], $app->rows('SELECT id FROM ledger WHERE merchant_order_id=?', [$mixedChild]), 'waiting preorder blocks sales completion');
denied(fn() => $app->receivePreorder($pond, $batch, 6), 'other merchant cannot receive stock');
denied(fn() => $app->receivePreorder($north, $batch, 4), 'arrival cannot short the paid and reserved obligations');
$app->closePreorder($catalogStaff, $batch);
$app->receivePreorder($catalogStaff, $batch, 6);
$app->receivePreorder($catalogStaff, $batch, 6);
denied(fn() => $app->receivePreorder($catalogStaff, $batch, 7), 'receipt replay cannot alter the received quantity');
same([3, 2], $preStock(), 'arrival reserves pending units and recognizes physical surplus');
same([3, 2], array_values($app->rows('SELECT stock,reserved FROM warehouse_stock WHERE product_id=? AND warehouse_id=?',
    [$preSku, $east])[0]), 'new warehouse balance mirrors converted holds');
$app->demoPay($otherBuyer, $payAfterArrival, "pre-pay-after-$tag", 1500);
same(true, $app->cancel($cancelAfterArrival, $otherBuyer), 'cancellation releases a converted physical hold');
same([2, 0], $preStock(), 'payment after arrival deducts exactly one and cancellation releases one');
same([0, 4], $quota($batch), 'converted orders also close their quota ledger');
$preLine = $app->unshippedLines($mixedChild)[0];
same([$east, 2], [$preLine['warehouse_id'], (int) $preLine['remaining']], 'received paid lines become dispatchable in final warehouse');
$trackingPre = "PR-ARR-$tag";
$app->dispatchShipment($north, $mixedChild, $east, 'demo', $trackingPre, [$preLine['id'] => 2], bin2hex(random_bytes(16)));
$app->recordDelivery('demo', $trackingPre, "pre-arrived-$tag");
same('fulfilled', $childStatus($mixedChild), 'final preorder parcel finishes the mixed child order');
same(1, count($app->rows('SELECT id FROM ledger WHERE merchant_order_id=?', [$mixedChild])), 'final mixed delivery records one sale');
$app->preparePickup($eastStaff, $prePickupChild, $eastPickup);
same(true, $app->redeemPickup($eastStaff, $prePickupChild, $eastPickup, $app->issuePickupCode($buyer, $prePickupChild)),
    'received preorder can be collected at the original store');

$lastBatch = $app->createPreorder($north, $preSku, $east, 'LAST', 1, $cutoff, $expected);
$lastPreorder = [$preSku => $lastBatch];
$left = $app->quote($buyer, [$preSku => 1], 'web', '预约地址', preorders: $lastPreorder);
$right = $app->quote($otherBuyer, [$preSku => 1], 'web', '预约地址', preorders: $lastPreorder);
same(['placed', 'unavailable'], compete('race-worker', [[$buyer, $left['token']], [$otherBuyer, $right['token']]]),
    'two buyers cannot reserve the last preorder unit');
$winner = $app->rows('SELECT order_id AS id,buyer_id FROM quotes WHERE token IN (?,?) AND order_id IS NOT NULL',
    [$left['token'], $right['token']])[0];
$db->prepare('UPDATE orders SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?')->execute([$winner['id']]);
same(true, $app->expireOrders() >= 1, 'expired preorder order is closed');
same([0, 0], $quota($lastBatch), 'timeout releases preorder capacity for resale');
[$nextBuyer, $nextQuote] = $winner['buyer_id'] === $buyer ? [$otherBuyer, $right] : [$buyer, $left];
$nextOrder = $app->place($nextBuyer, $nextQuote['token']);
same('refund_required', $receipt($intent($winner['id']), "late-pre-$tag", 1500)['status'],
    'late capture cannot reclaim a resold preorder unit');
same([1, 0], $quota($lastBatch), 'late webhook leaves the new hold intact');
same(['paid', 'received'], compete('preorder-worker', [
    ['receive', $north, $lastBatch, 1],
    ['pay', $nextBuyer, $nextOrder, "arrival-race-$tag", 1500],
]), 'arrival and payment serialize on the batch');
same([0, 1], $quota($lastBatch), 'arrival race leaves one paid unit');
same([2, 0], $preStock(), 'arrival race never leaks or double deducts physical stock');
same(1, count($app->unshippedLines($childOrder($nextOrder))), 'arrival race produces one dispatchable allocation');

$endingBatch = $app->createPreorder($north, $preSku, $east, 'ENDING', 2, $cutoff, $expected);
$endingQuote = $app->quote($buyer, [$preSku => 1], 'web', '预约地址', preorders: [$preSku => $endingBatch]);
$db->prepare('UPDATE preorder_batches SET closes_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?')->execute([$endingBatch]);
denied(fn() => $app->place($buyer, $endingQuote['token']), 'batch expiry prevents consuming an old quote');

$nextPreview = $app->previewSettlement($finance, $northShop, $currentPeriod, 'CNY');
same(null, $nextPreview['blocked'], 'later sales offset the carried refunds');
$nextBatch = $freeze($finance, $northShop, $currentPeriod, $nextPreview);
same(2, count($app->rows("SELECT id FROM ledger WHERE merchant_order_id=? AND kind='refund' AND settlement_id=?",
    [$deliveryOrder, $nextBatch])), 'next statement freezes both late refunds');
$nextCsv = iterator_to_array($app->exportSettlement($finance, $nextBatch), false);
same('platform_freight_minor', $nextCsv[0][12], 'settlement export identifies platform-funded freight');
same('platform_points_minor', $nextCsv[0][13], 'settlement export preserves existing columns and appends points funding');
$freightRows = array_column(array_filter($nextCsv, static fn(array $entry): bool =>
    (int) $entry[5] === $deliveryOrder && $entry[6] === 'refund'), null, 4);
$csvAmounts = static fn(array $entry): array => array_map('intval', [$entry[8], $entry[9], $entry[10], $entry[12]]);
same([-3214, -242, -2172, 800], $csvAmounts($freightRows["refund-$secondReturn"]), 'platform funding offsets the merchant debit');
same([-2007, -120, -1887, 0], $csvAmounts($freightRows["refund-$firstReturn"]), 'merchant-funded freight stays in its debit');
same($settledBeforeReturn, $batchSnapshot(), 'opening the next statement never modifies the previous report');
$app->reviewSettlement($finance, $nextBatch, $nextPreview['net']);
denied(fn() => $app->recordSettlementTransfer($payout, $nextBatch, "bank-$tag", $nextPreview['net']),
    'bank receipt cannot be reused on another settlement');

$freightProducts = [];
foreach (['A', 'B'] as $variant) {
    $release = $app->submitSku($north, 0, "FR-$variant-$tag", "运费并发验收 $variant", 1000, 1, $bags,
        '运费并发验收', '', $variant, true, false);
    $freightProducts[$app->reviewSku($admin, $release, true)] = 1;
}
$freightQuote = $app->quote($buyer, $freightProducts, 'web', '运费并发验收地址');
same(800, $freightQuote['shipping'], 'two lines in one warehouse share one freight charge');
$freightParent = $app->place($buyer, $freightQuote['token']);
$app->demoPay($buyer, $freightParent, "freight-pay-$tag", $freightQuote['total']);
$freightOrder = $childOrder($freightParent);
$freightLines = $app->unshippedLines($freightOrder, $main);
$app->dispatchShipment($fulfillmentStaff, $freightOrder, $main, 'demo', "freight-out-$tag",
    array_map('intval', array_column($freightLines, 'remaining', 'id')), bin2hex(random_bytes(16)));
$app->recordDelivery('demo', "freight-out-$tag", "freight-delivered-$tag");
$freightClaims = [];
foreach ($app->rows('SELECT id FROM order_items WHERE merchant_order_id=? ORDER BY id', [$freightOrder]) as $item) {
    $claim = $app->requestRefund($buyer, (int) $item['id'], 1, '并发运费申请', bin2hex(random_bytes(16)));
    $app->reviewReturn($supportStaff, $claim, $east, '', '');
    $freightClaims[] = $claim;
}
same(['saved', 'unavailable'], compete('freight-worker', [[$supportStaff, $freightClaims[0], 800],
    [$finance, $freightClaims[1], 800]]), 'competing decisions on different lines share one order-wide ceiling');
same(800, (int) $returnDetails($freightClaims[0])['freight_amount'] + (int) $returnDetails($freightClaims[1])['freight_amount'],
    'the combined hold equals the original freight after the race');

$prepareCouponReturns = static function (int $orderId, int $customer) use ($app): array {
    $payment = $app->rows('SELECT reference FROM payments WHERE order_id=?', [$orderId])[0]['reference'];
    $parts = $app->rows("SELECT m.id,i.id AS item,i.quantity,p.pickup_location_id,s.user_id AS owner_id,
        l.warehouse_id FROM merchant_orders m JOIN shop_members s ON s.shop_id=m.shop_id AND s.position='owner'
        JOIN order_items i ON i.merchant_order_id=m.id
        JOIN order_pickups p ON p.merchant_order_id=m.id JOIN pickup_locations l ON l.id=p.pickup_location_id
        WHERE m.order_id=? ORDER BY m.id", [$orderId]);
    $receipts = [];
    foreach ($parts as $part) {
        $owner = (int) $part['owner_id'];
        $child = (int) $part['id'];
        $location = (int) $part['pickup_location_id'];
        $units = (int) $part['quantity'];
        $app->preparePickup($owner, $child, $location);
        $app->redeemPickup($owner, $child, $location, $app->issuePickupCode($customer, $child));
        $claim = $app->requestRefund($customer, (int) $part['item'], $units, '验收原券返还', bin2hex(random_bytes(16)));
        $app->reviewReturn($owner, $claim, (int) $part['warehouse_id'], '示例退货地址', '');
        $app->dispatchReturn($customer, $claim, 'demo', "coupon-return-$claim");
        $app->inspectReturn($owner, $claim, $units, $units, '');
        $case = $app->rows('SELECT refund_token,amount FROM refunds WHERE id=?', [$claim])[0];
        $receipts[] = [$case['refund_token'], "coupon-receipt-$claim", $payment, (int) $case['amount']];
    }
    return $receipts;
};
$confirmCouponReturn = static fn(array $receipt): array =>
    $app->recordReturnReceipt($receipt[0], $receipt[1], $receipt[2], 'CNY', $receipt[3]);
$couponState = static fn(int $customer, int $promotion): string => array_column($app->buyerCoupons($customer), 'status', 'id')[$promotion];
$restorations = static fn(int $orderId): array => array_map('intval', array_column(
    $app->rows('SELECT revision_id FROM coupon_refund_restorations WHERE order_id=?', [$orderId]), 'revision_id'));

[$couponFirst, $couponLast] = $prepareCouponReturns($paidCouponOrder, $memberBuyer);
$confirmCouponReturn($couponFirst);
same('used', $couponState($memberBuyer, $campaign), 'confirming the refund for one merchant does not release the coupon');
same([], $restorations($paidCouponOrder), 'waiting for another merchant receipt leaves no restoration');
$app->setPromotionEnabled($admin, $campaign, false);
$confirmCouponReturn($couponLast);
same('unavailable', $couponState($memberBuyer, $campaign), 'original coupon is restored while campaign is suspended');
same([$couponVersion], $restorations($paidCouponOrder), 'full refund restores the original version despite the replacement policy');
$app->setPromotionEnabled($admin, $campaign, true);
$repeatQuote = $app->quote($memberBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
same([$couponVersion, 2000], [$repeatQuote['promotion']['revision_id'], $repeatQuote['discount']],
    'restored coupon uses its original terms at checkout');
$repeatOrder = $app->place($memberBuyer, $repeatQuote['token']);
$app->demoPay($memberBuyer, $repeatOrder, "coupon-repeat-$tag", $repeatQuote['total']);
$confirmCouponReturn($couponLast);
same('used', $couponState($memberBuyer, $campaign), 'replayed old refund leaves the new redemption intact');
same([$couponVersion], $restorations($paidCouponOrder), 'old redemption keeps one immutable restoration');

$raceReturns = $prepareCouponReturns($zeroOrder, $buyer);
same([0, 0], array_column($raceReturns, 3), 'zero-cash order still requires both goods to be returned');
same(['refunded', 'refunded'], compete('refund-worker', $raceReturns), 'simultaneous receipts across two shops both post');
same([$freeVersion], $restorations($zeroOrder), 'concurrent final refunds restore the coupon once');
same('available', $couponState($buyer, $freeCampaign), 'zero-cash full return can restore the coupon');

$expired = $db->prepare('UPDATE promotion_revisions SET ends_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?');
$expired->execute([$couponVersion]);
foreach ($prepareCouponReturns($repeatOrder, $memberBuyer) as $couponReceipt) {
    $confirmCouponReturn($couponReceipt);
}
same([], $restorations($repeatOrder), 'expired issued version is not extended by refund');
same('used', $couponState($memberBuyer, $campaign), 'expired coupon retains its redeemed status');

$keepQuote = $app->quote($otherBuyer, [$a => 1, $b => 1], 'store', '', $campaign, $bothLocations);
$keepOrder = $app->place($otherBuyer, $keepQuote['token']);
$app->demoPay($otherBuyer, $keepOrder, "coupon-keep-$tag", $keepQuote['total']);
foreach ($prepareCouponReturns($keepOrder, $otherBuyer) as $couponReceipt) {
    $confirmCouponReturn($couponReceipt);
}
same([], $restorations($keepOrder), 'a version that opts out keeps its redemption after a full refund');
same('used', $couponState($otherBuyer, $campaign), 'newer campaign may retain redemptions while the original version restores');

$loyalCustomer = $app->register("loyal-$tag@example.test", $pw);
$loyalRelease = $app->submitSku($north, 0, "LOYAL-$tag", '积分验收商品', 3333, 20, $bags,
    '积分验收商品', '', '', true, true);
$loyalSku = $app->reviewSku($admin, $loyalRelease, true);
$loyalQuote = static fn(int $points = 0, int $units = 3): array =>
    $app->quote($loyalCustomer, [$loyalSku => $units], 'store', '', pickupLocations: $northLocations, points: $points);
$accountPoints = static fn(): array => array_map('intval', $app->loyaltyAccount($loyalCustomer));
denied(fn() => $app->publishLoyalty($finance, 2, 20, true), 'only platform operations can publish an integral points policy');
denied(fn() => $app->publishLoyalty($admin, 11, 20, true), 'earn rate cannot exceed the published range');
$app->publishLoyalty($admin, 2, 20, true);
$earning = $loyalQuote();
same([9999, 199], [$earning['total'], $earning['loyalty']['earned']], 'earning is rounded by paid item line');
$earnOrder = $app->place($loyalCustomer, $earning['token']);
same(['balance' => 0, 'held' => 0, 'available' => 0], $accountPoints(), 'unpaid order awards no usable points');
$app->demoPay($loyalCustomer, $earnOrder, "loyal-earn-$tag", $earning['total']);
$app->demoPay($loyalCustomer, $earnOrder, "loyal-earn-$tag", $earning['total']);
same(['balance' => 199, 'held' => 0, 'available' => 199], $accountPoints(), 'replayed payment awards points once');
same([], $app->loyaltyHistory($otherBuyer), 'points activity is scoped to the customer');
$baseMarketQuote = $app->quote($loyalCustomer, [$a => 1, $b => 1], 'web', '跨店积分报价测试');
$loyalMarketQuote = $app->quote($loyalCustomer, [$a => 1, $b => 1], 'web', '跨店积分报价测试', points: 101);
same([101, $baseMarketQuote['discount'], $baseMarketQuote['shipping'], $baseMarketQuote['total'] - 101],
    [array_sum(array_column($loyalMarketQuote['items'], 'points_spent')), $loyalMarketQuote['discount'],
        $loyalMarketQuote['shipping'], array_sum(array_column($loyalMarketQuote['shops'], 'total'))],
    'points allocate across shops after promotion without changing the shipping charge');
denied(fn() => $loyalQuote(200), 'cannot quote more than wallet balance');
denied(fn() => $loyalQuote(2000), 'cannot quote more than configured merchandise cap');
denied(fn() => $loyalQuote(-1), 'negative redemption is rejected');

$quoteA = $loyalQuote(150);
$quoteB = $loyalQuote(150);
same(['placed', 'unavailable'], compete('race-worker', [[$loyalCustomer, $quoteA['token']], [$loyalCustomer, $quoteB['token']]]),
    'concurrent checkouts cannot reserve more points than the account owns');
$holdOrder = (int) $app->rows('SELECT order_id FROM quotes WHERE token IN (?,?) AND order_id IS NOT NULL',
    [$quoteA['token'], $quoteB['token']])[0]['order_id'];
same(['balance' => 199, 'held' => 150, 'available' => 49], $accountPoints(), 'spendable balance excludes reservations');
same(true, $app->cancel($holdOrder, $loyalCustomer), 'cancelling a waiting order releases its points');
same(false, $app->cancel($holdOrder, $loyalCustomer), 'replayed cancellation is idempotent');
same(['balance' => 199, 'held' => 0, 'available' => 199], $accountPoints(), 'cancel releases the original hold exactly once');
$timeoutQuote = $loyalQuote(100);
$timeoutOrder = $app->place($loyalCustomer, $timeoutQuote['token']);
$db->prepare('UPDATE orders SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?')->execute([$timeoutOrder]);
$app->expireOrders();
same('cancelled', $app->rows('SELECT status FROM orders WHERE id=?', [$timeoutOrder])[0]['status'], 'expiry worker closes waiting points order');
same('refund_required', $receipt($intent($timeoutOrder), "loyal-late-$tag", $timeoutQuote['total'])['status'],
    'late capture cannot take the released points');
same(['balance' => 199, 'held' => 0, 'available' => 199], $accountPoints(), 'expiry and late capture preserve the released balance');

$oldPointsQuote = $loyalQuote(101);
$loyalRule = $app->publishLoyalty($admin, 2, 20, true);
denied(fn() => $app->place($loyalCustomer, $oldPointsQuote['token']), 'stale rule version requires a fresh quote');
$combined = $loyalQuote(101);
same([9898, ['revision_id' => $loyalRule, 'spent' => 101, 'earned' => 197]],
    [$combined['total'], $combined['loyalty']], 'cash and earn preview reflect the points price');
$combinedId = $app->place($loyalCustomer, $combined['token']);
$noReturnRule = $app->publishLoyalty($admin, 0, 100, false);
$app->demoPay($loyalCustomer, $combinedId, "loyal-mixed-$tag", 9898);
same(['balance' => 295, 'held' => 0, 'available' => 295], $accountPoints(), 'points are consumed and earned under placed policy');
$combinedChild = $childOrder($combinedId);
$app->preparePickup($north, $combinedChild, $northPickup);
$app->redeemPickup($north, $combinedChild, $northPickup, $app->issuePickupCode($loyalCustomer, $combinedChild));
$pointItem = (int) $app->rows('SELECT id FROM order_items WHERE merchant_order_id=?', [$combinedChild])[0]['id'];
$pointsRefunds = [];
foreach ([1, 2] as $units) {
    $claim = $app->requestRefund($loyalCustomer, $pointItem, $units, '积分部分退款验收', bin2hex(random_bytes(16)));
    $app->reviewReturn($north, $claim, $main, '示例退货地址', '');
    $app->dispatchReturn($loyalCustomer, $claim, 'demo', "loyal-return-$claim");
    $app->inspectReturn($north, $claim, $units, $units, '');
    $pointsRefunds[] = $returnDetails($claim);
}
$pointsSplit = static fn(array $case): array => array_map('intval', [$case['amount'], $case['commission'],
    $case['points_restored'], $case['points_reversed']]);
same([[3299, 333, 33, 65], [6599, 666, 68, 132]], array_map($pointsSplit, $pointsRefunds),
    'partial requests reserve remaining cash, point restoration, earned-point reversal and commission');
same(['balance' => 295, 'held' => 0, 'available' => 295], $accountPoints(), 'inspection alone does not post point adjustments');
foreach (array_reverse($pointsRefunds) as $case) {
    $app->recordReturnReceipt($case['refund_token'], "loyal-refund-" . $case['id'], "loyal-mixed-$tag", 'CNY', (int) $case['amount']);
    $app->recordReturnReceipt($case['refund_token'], "loyal-refund-" . $case['id'], "loyal-mixed-$tag", 'CNY', (int) $case['amount']);
}
same(['balance' => 199, 'held' => 0, 'available' => 199], $accountPoints(), 'out-of-order receipts restore the original points once');
$ledgerAmounts = static fn(array $entry): array => array_map('intval', [$entry['gross'], $entry['commission'], $entry['platform_points'], $entry['net']]);
same([[9898, 999, 101, 9000], [-6599, -666, -68, -6001], [-3299, -333, -33, -2999]],
    array_map($ledgerAmounts, $app->rows('SELECT gross,commission,platform_points,' . Commerce::LEDGER_NET . ' AS net
        FROM ledger WHERE merchant_order_id=? ORDER BY id', [$combinedChild])), 'platform funds the points without reducing merchant sale value');

$fullyCovered = $app->submitSku($north, 0, "ZEROPOINT-$tag", '积分全额抵扣', 199, 3, $bags, '积分全额抵扣', '', '', false, true);
$coveredSku = $app->reviewSku($admin, $fullyCovered, true);
$coveredQuote = $app->quote($loyalCustomer, [$coveredSku => 1], 'store', '', pickupLocations: $northLocations, points: 199);
same([0, $noReturnRule], [$coveredQuote['total'], $coveredQuote['loyalty']['revision_id']], 'full points redemption can produce a zero cash payment');
$coveredOrder = $app->place($loyalCustomer, $coveredQuote['token']);
same(['balance' => 0, 'held' => 0, 'available' => 0], $accountPoints(), 'auto-paid order consumes reserved points atomically');
[$zeroPaidReturn] = $prepareCouponReturns($coveredOrder, $loyalCustomer);
same(0, $zeroPaidReturn[3], 'fully points-funded item has zero cash refund');
$zeroReturnId = (int) $app->rows('SELECT id FROM refunds WHERE refund_token=?', [$zeroPaidReturn[0]])[0]['id'];
denied(fn() => $app->confirmZeroCashReturn($loyalCustomer, $zeroReturnId), 'zero return confirmation requires finance');
$newZeroReceipt = $app->confirmZeroCashReturn($finance, $zeroReturnId);
same(['refund_id' => $zeroReturnId, 'status' => 'refunded'], $newZeroReceipt, 'finance confirms a zero-cash return internally');
same($newZeroReceipt, $app->confirmZeroCashReturn($finance, $zeroReturnId), 'internal zero return confirmation replays once');
same('internal', $app->rows('SELECT refund_provider FROM refunds WHERE id=?', [$zeroReturnId])[0]['refund_provider'],
    'zero return is never attributed to an external refund');
same(['balance' => 0, 'held' => 0, 'available' => 0], $accountPoints(), 'policy in use may retain points redemption');
foreach ($prepareCouponReturns($earnOrder, $loyalCustomer) as $earnReturn) {
    $confirmCouponReturn($earnReturn);
}
same(['balance' => -199, 'held' => 0, 'available' => 0], $accountPoints(), 'already spent award becomes a points deficit on return');
$app->publishLoyalty($admin, 2, 20, true);
$recovery = $loyalQuote(units: 1);
$app->demoPay($loyalCustomer, $app->place($loyalCustomer, $recovery['token']), "loyal-offset-$tag", $recovery['total']);
same(['balance' => -133, 'held' => 0, 'available' => 0], $accountPoints(), 'future earnings first clear deficit');
$pointLog = $app->loyaltyHistory($loyalCustomer);
same(-133, array_sum(array_column($pointLog, 'balance_delta')), 'account balance matches append-only entries');
same(0, array_sum(array_column($pointLog, 'held_delta')), 'entries also reconcile open holds');
$app->publishLoyalty($admin, 0, 0, false);

$freightSku = $app->reviewSku($admin, $app->submitSku($north, 0, "FEE-$tag", '运费规则样例', 10000, 1, $bags,
    '运费规则样例', '', '', true, true), true);
$freightStockVersion = (int) $app->rows('SELECT version FROM products WHERE id=?', [$freightSku])[0]['version'];
$app->adjustStock($north, $freightSku, 4, $freightStockVersion, $east);
$splitFreight = static fn(Commerce $market, int $quantity = 2): array =>
    $market->quote($buyer, [$freightSku => $quantity], 'web', '两仓运费验收');
$legacyFreight = $splitFreight($app);
same(1600, $legacyFreight['shipping'], 'unconfigured shop still charges eight yuan per warehouse');
denied(fn() => $app->publishFreightPolicy($catalogStaff, 0, 'per_order_v1', 600, null), 'only the owner can publish freight rules');
denied(fn() => $app->publishFreightPolicy($north, 0, 'missing_v1', 600, null), 'an unregistered strategy cannot be published');
denied(fn() => $app->publishFreightPolicy($north, 0, 'per_order_v1', 600, 0), 'a configured free threshold is positive');
$freightOne = $app->publishFreightPolicy($north, 0, 'per_order_v1', 600, 20000);
denied(fn() => $app->publishFreightPolicy($north, 0, 'per_order_v1', 700, null), 'stale freight revisions cannot overwrite changes');
denied(fn() => $app->publishFreightPolicy($pond, $freightOne, 'per_order_v1', 700, null), 'another shop cannot use this revision');
denied(fn() => $app->place($buyer, $legacyFreight['token']), 'a quote with the old freight version requires confirmation');
same([], $app->merchantFreightHistory($pond), 'another shop has separate freight history');
same(600, $splitFreight($app, 1)['shipping'], 'charge the shop flat rate below its threshold');
$qualifying = $splitFreight($app);
same([0, $freightOne], [$qualifying['shipping'], $qualifying['shops'][$northShop]['freight_policy_revision_id']],
    'free shipping applies at the exact threshold and records its rule');
$freightOrder = $app->place($buyer, $qualifying['token']);
$freightTwo = $app->publishFreightPolicy($north, $freightOne, 'per_order_v1', 700, null);
$frozenFreight = $app->rows('SELECT shipping,freight_policy_revision_id FROM merchant_orders WHERE order_id=?', [$freightOrder])[0];
same([0, $freightOne], array_map('intval', array_values($frozenFreight)), 'a placed order keeps its original freight and revision');
same($freightOrder, $app->place($buyer, $qualifying['token']), 'replaying a placed quote still returns its original order');
same(true, $app->cancel($freightOrder, $buyer), 'cancelling the order returns warehouse allocations');
same(700, $splitFreight($app)['shipping'], 'per-order rate is only charged once for two allocated warehouses');
$pickupFreight = $app->quote($buyer, [$freightSku => 1], 'store', '', pickupLocations: $northLocations);
same([0, null], [$pickupFreight['shipping'], $pickupFreight['shops'][$northShop]['freight_policy_revision_id'] ?? null],
    'pickup is not priced by delivery policies');
$externalFreight = new Commerce($db, new FreightStrategies(require __DIR__ . '/../examples/freight-consolidation.php'));
$freightThree = $externalFreight->publishFreightPolicy($north, $freightTwo, 'consolidation_v1', 701, null);
same(1051, $splitFreight($externalFreight)['shipping'], 'the extension prices the second warehouse at half of an odd fee');
denied(fn() => $splitFreight(new Commerce($db, new FreightStrategies())), 'a missing active extension never falls back to a different fee');
$freightFour = $externalFreight->publishFreightPolicy($north, $freightThree, FreightStrategies::DEFAULT, 800, null);
same(1600, $splitFreight($app)['shipping'], 'publishing a built-in strategy safely replaces an optional extension');
$oldSameFreight = $splitFreight($app);
$freightFive = $app->publishFreightPolicy($north, $freightFour, FreightStrategies::DEFAULT, 800, null);
same(1600, $splitFreight($app)['shipping'], 'the same configuration can yield the same fee under a new revision');
denied(fn() => $app->place($buyer, $oldSameFreight['token']), 'equal-priced freight revisions still require a new quote');
$freightSix = $app->publishFreightPolicy($north, $freightFive, 'per_order_v1', 700, 10000);
$couponCode = "freight-preview-$tag";
$app->publishPromotion($admin, $couponCode, '包邮券后门槛验收', 10000, 1000, true, true);
$freightCoupon = (int) $app->rows('SELECT id FROM promotions WHERE code=?', [$couponCode])[0]['id'];
$app->claimCoupon($buyer, $freightCoupon);
same(0, $splitFreight($app, 1)['shipping'], 'full goods value qualifies without a coupon');
$couponFreight = $app->quote($buyer, [$freightSku => 1, $b => 1], 'web', '券后包邮门槛验收', $freightCoupon);
same([700, 800], [$couponFreight['shops'][$northShop]['shipping'], $couponFreight['shops'][$pondShop]['shipping']],
    'the coupon reduces eligibility and freight is calculated independently for each shop');
same([$freightSix, $freightFive, $freightFour, $freightThree, $freightTwo, $freightOne],
    array_map('intval', array_column($app->merchantFreightHistory($north), 'id')), 'history maintains every published revision');

$marketingNorth = $app->reviewSku($admin, $app->submitSku($north, 0, "MKT-N-$tag", '活动分摊样例甲', 10001, 4,
    $bags, '活动分摊样例甲', '', '', true, true), true);
$marketingPond = $app->reviewSku($admin, $app->submitSku($pond, 0, "MKT-P-$tag", '活动分摊样例乙', 10003, 4,
    $bags, '活动分摊样例乙', '', '', true, true), true);
$promoQuote = static fn(Commerce $market, int $customer, ?int $coupon = null): array =>
    $market->quote($customer, [$marketingPond => 1, $marketingNorth => 1], 'store', '', $coupon, $bothLocations);
$percentCode = "percent-$tag";
denied(fn() => $app->publishPromotion($north, $percentCode, '越权发布', 20000, 3000, true, true,
    strategy: PromotionStrategies::PERCENTAGE, rateBps: 1250), 'only admin can publish percentage promotions');
denied(fn() => $app->publishPromotion($admin, $percentCode, '越界比例', 20000, 3000, true, true,
    strategy: PromotionStrategies::PERCENTAGE, rateBps: 10001), 'percentage reductions are at most one hundred percent');
denied(fn() => $app->publishPromotion($admin, $percentCode, '固定金额带比例', 20000, 3000, true, true, rateBps: 1250),
    'fixed reduction does not accept rate parameters');
$percentRevision = $app->publishPromotion($admin, $percentCode, '跨店比例券', 20000, 3000, true, true,
    $bags, refundCoupon: true, strategy: PromotionStrategies::PERCENTAGE, rateBps: 1250);
$percentCoupon = (int) $app->rows('SELECT id FROM promotions WHERE code=?', [$percentCode])[0]['id'];
same(true, $app->claimCoupon($buyer, $percentCoupon), 'a buyer claims the published percentage revision');
denied(fn() => $app->quote($buyer, [$marketingNorth => 2], 'store', '', $percentCoupon, $northLocations),
    'reaching the percentage threshold in one shop is insufficient');
$percentQuote = $promoQuote($app, $buyer, $percentCoupon);
same([2500, 17504, 1249, 1251], [$percentQuote['discount'], $percentQuote['total'],
    $percentQuote['items'][$marketingNorth]['discount'], $percentQuote['items'][$marketingPond]['discount']],
    'round percentage to whole cents then distribute the final cent by SKU order');
$currentPercent = $app->publishPromotion($admin, $percentCode, '比例券新版', 10000, 1800, true, true,
    $bags, strategy: PromotionStrategies::PERCENTAGE, rateBps: 1000);
$app->claimCoupon($otherBuyer, $percentCoupon);
$newPercentQuote = $promoQuote($app, $otherBuyer, $percentCoupon);
same([$currentPercent, 1800], [$newPercentQuote['promotion']['revision_id'], $newPercentQuote['discount']],
    'a later claim receives the new rate and cap');
$percentOrder = $app->place($buyer, $percentQuote['token']);
$percentOrderRow = $app->rows('SELECT promotion_revision_id,discount,total FROM orders WHERE id=?', [$percentOrder])[0];
same([$percentRevision, 2500, 17504], array_map('intval', array_values($percentOrderRow)),
    'placing a claimed quote preserves the original version and amount after republishing');
$app->demoPay($buyer, $percentOrder, "percent-payment-$tag", 17504);
$percentRefunds = $prepareCouponReturns($percentOrder, $buyer);
same([8752, 8752], array_column($percentRefunds, 3), 'returns use settled line amounts for the old policy');
foreach ($percentRefunds as $percentRefund) {
    $confirmCouponReturn($percentRefund);
}
same('available', $couponState($buyer, $percentCoupon), 'full refund restores the original percentage coupon');
$restoredPercent = $promoQuote($app, $buyer, $percentCoupon);
same([$percentRevision, 2500], [$restoredPercent['promotion']['revision_id'], $restoredPercent['discount']],
    'restored coupon retains the old percentage rule');

$strategyApp = new Commerce($db, promotionStrategies: new PromotionStrategies(require __DIR__ . '/../examples/promotion-steps.php'));
$stepsCode = "steps-$tag";
$stepsRevision = $strategyApp->publishPromotion($admin, $stepsCode, '阶梯自动优惠', 10000, 2300, false, true,
    $bags, strategy: 'threshold_steps_v1', rateBps: 1250);
$stepsId = (int) $app->rows('SELECT id FROM promotions WHERE code=?', [$stepsCode])[0]['id'];
$stepsQuote = $promoQuote($strategyApp, $buyer);
same([$stepsRevision, 2300, 17704], [$stepsQuote['promotion']['revision_id'], $stepsQuote['discount'], $stepsQuote['total']],
    'two complete steps reach the configured cap and win the automatic offer');
denied(fn() => $promoQuote($app, $buyer), 'eligible automatic offers cannot fall back when an installed strategy is removed');
$strategyApp->publishPromotion($admin, $stepsCode, '同价新版本', 10000, 2300, false, true,
    $bags, strategy: 'threshold_steps_v1', rateBps: 1250);
denied(fn() => $strategyApp->place($buyer, $stepsQuote['token']), 'an automatic revision change invalidates an equal-price old quote');
$strategyApp->setPromotionEnabled($admin, $stepsId, false);
$overLimit = new Commerce($db, promotionStrategies: new PromotionStrategies([
    'over_limit_v1' => ['label' => '超过上限的测试计算器', 'uses_rate' => false,
        'discount' => static fn(int $goods, int $threshold, int $limit): int => $limit + 1],
]));
$overLimitCode = "overlimit-$tag";
$overLimit->publishPromotion($admin, $overLimitCode, '扩展拒绝样例', 10000, 2500, true, true,
    $bags, strategy: 'over_limit_v1');
$overLimitId = (int) $app->rows('SELECT id FROM promotions WHERE code=?', [$overLimitCode])[0]['id'];
$overLimit->claimCoupon($buyer, $overLimitId);
denied(fn() => $promoQuote($overLimit, $buyer, $overLimitId), 'the allocator rejects discount output larger than the declared cap');

$testChannel = ['checkout' => static fn(array $details): string => 'https://checkout.example.test/pay?' . http_build_query($details),
    'verify' => static fn(string $body, array $headers): array => json_decode($body, true, 4, JSON_THROW_ON_ERROR)];
$definitions = ['channel-a' => $testChannel, 'channel-b' => $testChannel];
$channelA = new Commerce($db, paymentChannels: new PaymentChannels($definitions, 'channel-a'));
$channelB = new Commerce($db, paymentChannels: new PaymentChannels($definitions, 'channel-b'));
$routeQuote = $splitFreight($app, 1);
$channelOrder = $channelA->place($buyer, $routeQuote['token']);
same($channelOrder, $channelB->place($buyer, $routeQuote['token']), 'replayed placement keeps the original channel binding');
denied(fn() => $channelA->demoPay($buyer, $channelOrder, "external-$tag", $routeQuote['total']),
    'demo payment cannot accept an externally bound order');
$event = ['type' => 'capture', 'token' => $intent($channelOrder), 'reference' => "external-$tag",
    'currency' => 'CNY', 'amount' => $routeQuote['total']];
$captureChannel = static fn(string $provider, array $payload): array =>
    $channelB->receivePaymentChannelEvent($provider, json_encode($payload, JSON_THROW_ON_ERROR), []);
same('needs_review', $captureChannel('channel-b', $event)['status'], 'a different registered channel cannot confirm the order');
same('needs_review', $receipt($event['token'], $event['reference'], $event['amount'])['status'],
    'sandbox captures for an externally bound order only create a review receipt');
$paidChannel = $captureChannel('channel-a', $event);
same('accepted', $paidChannel['status'], 'the original channel accepts its own capture after checkout configuration changes');
same($paidChannel, $captureChannel('channel-a', array_reverse($event, true)), 'canonical replay depends on normalized fields');
same(['channel-a', 'channel-b', 'sandbox'], array_column($app->rows('SELECT provider FROM payment_receipts
    WHERE reference=? ORDER BY provider', [$event['reference']]), 'provider'), 'each channel can use the same receipt reference');

$verifyCarrier = static fn(string $body, array $headers): array => json_decode($body, true, 4, JSON_THROW_ON_ERROR);
$carrierApp = new Commerce($db, carrierAdapters: new CarrierAdapters(['parcel_unit' => $verifyCarrier]));
$withoutCarrier = new Commerce($db, carrierAdapters: new CarrierAdapters());
$carrierSuborder = (int) $app->rows('SELECT id FROM merchant_orders WHERE order_id=?', [$channelOrder])[0]['id'];
$carrierLine = $app->unshippedLines($carrierSuborder)[0];
$carrierTracking = "carrier-$tag";
$carrierShipment = $carrierApp->dispatchShipment($north, $carrierSuborder, (int) $carrierLine['warehouse_id'],
    'parcel_unit', $carrierTracking, [(int) $carrierLine['id'] => 1], bin2hex(random_bytes(16)));
denied(fn() => $withoutCarrier->recordDelivery('parcel_unit', $carrierTracking, "forged-demo-$tag"),
    'removing the carrier configuration cannot give the sandbox access to its parcel');
$trackingEvent = ['type' => 'in_transit', 'tracking' => $carrierTracking, 'reference' => "tracking-$tag", 'occurred_at' => time()];
$receiveTracking = static fn(array $payload): array =>
    $carrierApp->receiveCarrierEvent('parcel_unit', json_encode($payload, JSON_THROW_ON_ERROR), []);
$recorded = $receiveTracking($trackingEvent);
same($recorded, $receiveTracking(array_reverse($trackingEvent, true)), 'canonical carrier replay returns the original event');
denied(fn() => $receiveTracking(array_replace($trackingEvent, ['type' => 'delivered'])),
    'carrier reference cannot change status on replay');
same(['carrier', 'in_transit', 64], array_values($app->rows('SELECT source,event_type,LENGTH(payload_sha256)
    FROM shipment_delivery_events WHERE id=?', [$recorded['event_id']])[0]), 'shipment event preserves its source, type and canonical digest');
same(null, $app->rows('SELECT delivered_at FROM shipments WHERE id=?', [$carrierShipment])[0]['delivered_at'],
    'a transit receipt cannot complete a parcel');
same([], $app->rows('SELECT id FROM ledger WHERE merchant_order_id=?', [$carrierSuborder]), 'transit receipt does not post a sale');

$legacyPending = $splitFreight($app, 1);
$legacyPendingOrder = $app->place($buyer, $legacyPending['token']);
$app->demoPay($buyer, $legacyPendingOrder, "before-carrier-$tag", $legacyPending['total']);
$legacySuborder = (int) $app->rows('SELECT id FROM merchant_orders WHERE order_id=?', [$legacyPendingOrder])[0]['id'];
$legacyLine = $withoutCarrier->unshippedLines($legacySuborder)[0];
$legacyTracking = "old-waybill-$tag";
$legacyShipment = $withoutCarrier->dispatchShipment($north, $legacySuborder, (int) $legacyLine['warehouse_id'],
    'parcel_unit', $legacyTracking, [(int) $legacyLine['id'] => 1], bin2hex(random_bytes(16)));
denied(fn() => $carrierApp->recordDelivery('parcel_unit', $legacyTracking, "sandbox-switch-$tag"),
    'a registered carrier claims new tracking even for an existing unbound parcel');
$legacyAccepted = $receiveTracking(array_replace($trackingEvent, [
    'type' => 'delivered', 'tracking' => $legacyTracking, 'reference' => "old-delivered-$tag",
]));
same($legacyShipment, $legacyAccepted['shipment_id'], 'a newly configured carrier can finish a previously unbound shipment');
same('carrier', $app->rows('SELECT tracking_source FROM shipments WHERE id=?', [$legacyShipment])[0]['tracking_source'],
    'first external receipt permanently binds the pending parcel to the carrier');

putenv('APP_DEMO=0');
denied(fn() => $app->place($buyer, $splitFreight($app, 1)['token']), 'external checkout requires a channel before reserving stock');
denied(fn() => $app->demoPay($buyer, $order, "pay-$tag", 18000), 'demo payment requires opt-in');
denied(fn() => $receipt($intent($resaleOrder), "disabled-$tag", 200), 'webhook processing requires sandbox opt-in');
denied(fn() => $returnReceipt("return-paid-$tag", 10800), 'return webhook processing also requires sandbox opt-in');

echo "$checks integration checks passed.\n";
