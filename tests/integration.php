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

if (($argv[1] ?? '') === 'race-worker') {
    echo "ready\n";
    fgets(STDIN);
    try {
        $app->place((int) $argv[2], $argv[3]);
        echo "placed\n";
    } catch (RuleViolation) {
        echo "unavailable\n";
    }
    exit;
}

if (!demoEnabled() || !str_ends_with((string) $db->query('SELECT DATABASE()')->fetchColumn(), '_test')) {
    throw new RuntimeException('Use APP_DEMO=1 and a disposable database ending in _test.');
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
denied(fn() => $app->reviewShop($finance, $northShop, true), 'finance cannot approve a merchant');
denied(fn() => $app->saveProduct($north, 0, 'N-1', 'Demo', 12000, 1, 'active'), 'shop approval required');
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
same($catalogStaff, $app->authenticate($catalogEmail, $pw), 'active staff can sign in');
denied(fn() => $app->addShopMember($catalogStaff, "escalate-$tag@example.test", $pw, 'finance'), 'staff cannot create accounts');
denied(fn() => $app->setShopMemberEnabled($pond, $catalogStaff, false), 'cross-shop staff revocation refused');
denied(fn() => $app->setShopMemberEnabled($north, $north, false), 'owner cannot revoke their own membership');
$app->setShopMemberEnabled($north, $catalogStaff, false);
same(null, $app->authenticate($catalogEmail, $pw), 'revoked staff cannot sign in');
same(false, $app->profile($catalogStaff)['enabled'], 'revoked staff session can be invalidated');
denied(fn() => $app->saveProduct($catalogStaff, 0, "N-$tag", '库存', 12000, 10, 'active'),
    'revoked staff has no catalog write access');
$app->setShopMemberEnabled($north, $catalogStaff, true);
$a = $app->saveProduct($catalogStaff, 0, "N-$tag", '北仓帆布包', 12000, 10, 'active');
$b = $app->saveProduct($pond, 0, "P-$tag", '池畔早餐杯', 8000, 10, 'active');
$hidden = $app->saveProduct($north, 0, "D-$tag", '未公开商品', 100, 4, 'draft');
same([], $app->catalog("D-$tag"), 'draft catalog isolation');
same($a, (int) $app->catalog("N-$tag")[0]['id'], 'SKU search');
denied(fn() => $app->saveProduct($pond, $a, "N-$tag", '跨店改名', 1, 1, 'active'), 'product ownership');
denied(fn() => $app->quote($buyer, [$hidden => 1], 'store', 'Store A'), 'draft not purchasable');
same([1 => 1, 2 => 0, 3 => 0], Commerce::allocate(1, [1 => 1, 2 => 1, 3 => 1]), 'stable remainder tie-break');
same(19600, $app->quote($buyer, [$a => 1, $b => 1], 'web', '测试配送地址')['total'], 'per-shop shipping');
$quote = $app->quote($buyer, [$b => 1, $a => 1], 'store', '一号自提点');
same(18000, $quote['total'], 'combined shopper payment');
same(10800, $quote['shops'][$northShop]['total'], 'merchant A split');
same(7200, $quote['shops'][$pondShop]['total'], 'merchant B split');
denied(fn() => $app->place($otherBuyer, $quote['token']), 'quote ownership');
$order = $app->place($buyer, $quote['token']);
same($order, $app->place($buyer, $quote['token']), 'checkout replay returns same order');
same(1, (int) $app->rows('SELECT reserved FROM products WHERE id=?', [$a])[0]['reserved'], 'stock held once');
denied(fn() => $app->saveProduct($north, $a, "N-$tag", '北仓帆布包', 12000, 0, 'active'), 'stock cannot go below holds');
denied(fn() => $app->demoPay($otherBuyer, $order, "pay-$tag", 18000), 'payment ownership');
denied(fn() => $app->demoPay($buyer, $order, "pay-$tag", 18001), 'payment amount validation');
$payment = $app->demoPay($buyer, $order, "pay-$tag", 18000);
same($payment, $app->demoPay($buyer, $order, "pay-$tag", 18000), 'payment replay');
denied(fn() => $app->demoPay($buyer, $order, "alt-$tag", 18000), 'one receipt per order');
same(false, $app->cancel($order, $buyer), 'paid order cannot be cancelled');
$inventory = $app->rows('SELECT stock,reserved FROM products WHERE id=?', [$a])[0];
same([9, 0], array_map('intval', array_values($inventory)), 'stock consumed exactly once');
denied(fn() => $app->saveProduct($north, $a, "N-$tag", '北仓帆布包', 12000, 10, 'active', 0),
    'outdated stock edit cannot restore paid inventory');
$merchantOrders = $app->rows('SELECT id,shop_id FROM merchant_orders WHERE order_id=? ORDER BY shop_id', [$order]);
[$northOrder, $pondOrder] = array_map('intval', array_column($merchantOrders, 'id'));
denied(fn() => $app->fulfill($pond, $northOrder, 'PICK-01'), 'fulfillment ownership');
denied(fn() => $app->fulfill($catalogStaff, $northOrder, 'PICK-01'), 'catalog staff cannot fulfill');
$aItem = (int) $app->rows('SELECT id FROM order_items WHERE merchant_order_id=?', [$northOrder])[0]['id'];
denied(fn() => $app->requestRefund($buyer, $aItem, 1, '测试', bin2hex(random_bytes(16))), 'refund requires fulfillment');
$beforeSuspension = $app->quote($buyer, [$a => 1], 'store', '一号自提点');
denied(fn() => $app->setShopSuspended($finance, $northShop, true, '暂停营业'), 'finance cannot suspend a shop');
$app->setShopSuspended($admin, $northShop, true, '停业盘点');
same([], $app->catalog("N-$tag"), 'suspended shop removed from catalog');
denied(fn() => $app->place($buyer, $beforeSuspension['token']), 'stale quote cannot order from suspended shop');
denied(fn() => $app->quote($buyer, [$a => 1], 'store', '一号自提点'), 'suspended shop cannot receive a new quote');
same($order, $app->place($buyer, $quote['token']), 'existing order replay remains available after closing');
$app->fulfill($fulfillmentStaff, $northOrder, 'PICK-01');
$app->fulfill($pond, $pondOrder, 'PICK-02');
denied(fn() => $app->fulfill($north, $northOrder, 'PICK-01'), 'fulfillment cannot duplicate sale entry');
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

$race = $app->saveProduct($north, 0, "R-$tag", '最后一件', 200, 1, 'active');
$q1 = $app->quote($buyer, [$race => 1], 'store', 'Store A');
$q2 = $app->quote($otherBuyer, [$race => 1], 'store', 'Store A');
$workers = [];
foreach ([[$buyer, $q1['token']], [$otherBuyer, $q2['token']]] as [$actor, $token]) {
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        array_push($command, '-d', 'extension=pdo_mysql');
    }
    array_push($command, __FILE__, 'race-worker', (string) $actor, $token);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
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
same(['placed', 'unavailable'], $outcomes, 'simultaneous checkout never oversells');
$holdingOrder = $app->rows('SELECT o.id,o.buyer_id FROM orders o JOIN merchant_orders m ON m.order_id=o.id
    JOIN order_items i ON i.merchant_order_id=m.id WHERE i.product_id=?', [$race])[0];
$expired = (int) $holdingOrder['id'];
$db->prepare('UPDATE orders SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=?')->execute([$expired]);
denied(fn() => $app->demoPay((int) $holdingOrder['buyer_id'], $expired, "expired-$tag", 200), 'late receipt cannot resurrect expired order');
same(true, $app->expireOrders() >= 1, 'expiry worker processed order');
same(0, (int) $app->rows('SELECT reserved FROM products WHERE id=?', [$race])[0]['reserved'], 'expired stock released');
same(false, $app->cancel($expired), 'expiry replay is a no-op');

$stale = $app->quote($buyer, [$b => 1], 'store', 'Store A');
$app->saveProduct($pond, $b, "P-$tag", '池畔早餐杯', 8100, 9, 'active');
denied(fn() => $app->place($buyer, $stale['token']), 'price changes invalidate quote');
$newQuote = $app->quote($buyer, [$b => 1], 'store', 'Store A');
$newOrder = $app->place($buyer, $newQuote['token']);
denied(fn() => $app->demoPay($buyer, $newOrder, "pay-$tag", 8100), 'gateway reference unique across orders');
same(true, $app->cancel($newOrder, $buyer), 'buyer can release an unpaid order');
putenv('APP_DEMO=0');
denied(fn() => $app->demoPay($buyer, $order, "pay-$tag", 18000), 'demo payment requires opt-in');

echo "$checks integration checks passed.\n";
