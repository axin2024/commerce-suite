<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['inventory', 'inventory-worker', 'orders'], true)) {
    fwrite(STDERR, "Usage: php tests/capacity.php inventory|orders\n");
    exit(2);
}
if (!demoEnabled()) {
    throw new RuntimeException('Set APP_DEMO=1 and use an isolated MySQL database ending in _test.');
}
$db = database();
$schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($schema, '_test')) {
    throw new RuntimeException('Capacity fixtures require an isolated MySQL database ending in _test.');
}
$app = new Commerce($db);

function printReport(array $report): void
{
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
}

if ($mode === 'inventory-worker') {
    $buyer = (int) $argv[2];
    echo "ready\n";
    fflush(STDOUT);
    $releaseLine = fgets(STDIN);
    if ($releaseLine === false || !is_numeric(trim($releaseLine)) || (float) $releaseLine <= 0) {
        exit(2);
    }
    $release = (float) $releaseLine;
    while (($remaining = $release - microtime(true)) > 0) {
        usleep((int) min(50_000, ceil($remaining * 1_000_000)));
    }
    $started = microtime(true);
    try {
        $order = $app->place($buyer, $argv[3]);
        $result = ['outcome' => 'reserved', 'order_id' => $order];
    } catch (RuleViolation $error) {
        $result = ['outcome' => $error->getMessage() === '商品可用库存不足，请调整购买数量。' ? 'sold_out' : 'unexpected_rule',
            'message' => $error->getMessage()];
    }
    echo json_encode($result + ['buyer_id' => $buyer, 'start_offset_ms' => round(($started - $release) * 1000, 3),
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 3)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

function checkoutTogether(array $jobs): array
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        array_push($command, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql');
    }
    array_push($command, __FILE__, 'inventory-worker');
    $workers = [];
    $results = [];
    try {
        foreach ($jobs as [$buyer, $token]) {
            $process = proc_open([...$command, (string) $buyer, $token],
                [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start a PHP worker.');
            }
            $workers[] = [$process, $pipes];
            if (trim((string) fgets($pipes[1])) !== 'ready') {
                throw new RuntimeException('A checkout worker failed before the barrier.');
            }
        }
        $release = sprintf('%.6F', microtime(true) + 2);
        foreach ($workers as [, $pipes]) {
            fwrite($pipes[0], "$release\n");
        }
        foreach ($workers as [, $pipes]) {
            $stdout = trim((string) stream_get_contents($pipes[1]));
            $result = json_decode($stdout, true);
            if (!is_array($result)) {
                $result = ['outcome' => 'worker_error', 'message' => $stdout];
            }
            $results[] = $result;
        }
    } finally {
        foreach ($workers as [$process, $pipes]) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($process);
        }
    }
    return [$release, $results];
}

function createCapacityShop(Commerce $app, int $admin, string $name, string $email, string $password): array
{
    $owner = $app->register($email, $password, $name);
    $app->submitShopAgreement($owner, $name);
    $shop = (int) $app->profile($owner)['shop_id'];
    $app->reviewShop($admin, $shop, true);
    return [$owner, $shop];
}

$run = 'capacity-' . bin2hex(random_bytes(5));
$password = 'CommerceCapacity-2026';
$admin = $app->provisionOperator("$run-admin@example.test", $password, 'admin');
$startedAt = microtime(true);
$environment = $app->rows('SELECT VERSION() AS mysql,@@max_connections AS max_connections,
    @@innodb_buffer_pool_size AS innodb_buffer_pool_bytes')[0] + ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY];

if ($mode === 'inventory') {
    [$owner, $shop] = createCapacityShop($app, $admin, "库存容量 $run", "$run-stock@example.test", $password);
    $category = $app->createCategory($admin, "容量样例 $run");
    $release = $app->submitSku($owner, 0, $run, '并发预占样例', 1200, 10, $category, '并发预占样例', '', '', true, false);
    $sku = $app->reviewSku($admin, $release, true);
    $stock = static fn(): array => $app->rows('SELECT p.stock,p.reserved,w.stock AS warehouse_stock,w.reserved AS warehouse_reserved
        FROM products p JOIN warehouse_stock w ON w.product_id=p.id WHERE p.id=?', [$sku])[0];
    $before = $stock();
    $jobs = [];
    for ($i = 0; $i < 100; $i++) {
        $buyer = $app->register("$run-$i@example.test", $password);
        $jobs[] = [$buyer, $app->quote($buyer, [$sku => 1], 'web', '本机压测合成地址')['token']];
    }
    [$barrier, $results] = checkoutTogether($jobs);
    $counts = array_count_values(array_column($results, 'outcome'));
    $after = $stock();
    $tokenList = array_column($jobs, 1);
    $placeholders = implode(',', array_fill(0, count($tokenList), '?'));
    $persisted = $app->rows("SELECT COUNT(DISTINCT o.id) AS linked_orders,
        COUNT(DISTINCT CASE WHEN o.status='unpaid' THEN o.id END) AS unpaid_orders,
        COALESCE(SUM(CASE WHEN a.state='held' THEN a.quantity ELSE 0 END),0) AS held_units
        FROM quotes q JOIN orders o ON o.id=q.order_id JOIN merchant_orders m ON m.order_id=o.id
        JOIN order_items i ON i.merchant_order_id=m.id JOIN order_inventory_allocations a ON a.order_item_id=i.id
        WHERE q.token IN ($placeholders)", $tokenList)[0];
    $filledQuotes = (int) $app->rows("SELECT COUNT(*) AS count FROM quotes WHERE token IN ($placeholders)
        AND order_id IS NOT NULL", $tokenList)[0]['count'];
    $passed = count($results) === 100 && ($counts['reserved'] ?? 0) === 10 && ($counts['sold_out'] ?? 0) === 90
        && $filledQuotes === 10 && array_map('intval', array_values($before)) === [10, 0, 10, 0]
        && array_map('intval', array_values($after)) === [10, 10, 10, 10]
        && array_map('intval', array_values($persisted)) === [10, 10, 10];
    printReport(['run' => $run, 'status' => $passed ? 'passed' : 'failed', 'environment' => $environment,
        'shop_id' => $shop, 'product_id' => $sku, 'before' => $before, 'after' => $after, 'persisted' => $persisted,
        'filled_quotes' => $filledQuotes, 'ready_workers' => count($jobs), 'outcomes' => $counts,
        'barrier_utc' => gmdate('c', (int) $barrier), 'setup_seconds' => round((float) $barrier - $startedAt, 3),
        'measure_ms' => round(max(array_map(static fn(array $result): float =>
            ($result['start_offset_ms'] ?? 0) + ($result['elapsed_ms'] ?? 0), $results)), 3),
        'requests' => $results]);
    exit($passed ? 0 : 1);
}

// Materialize the sequence once so MySQL can reuse it for each tenant.
$db->exec('CREATE TEMPORARY TABLE capacity_sequence AS
    WITH digits AS (SELECT n FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) v)
    SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 AS n
    FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e');
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$date = static fn(string $offset): string => $today->modify($offset)->format('Y-m-d');
$anchor = $today->format('Y-m-d');
$tenants = [];
foreach (['main' => 100_000, 'other' => 25_000] as $label => $count) {
    $email = "$run-$label@example.test";
    [, $shop] = createCapacityShop($app, $admin, "订单容量 $label $run", $email, $password);
    $buyer = $app->register("$run-$label-buyer@example.test", $password);
    $insertOrders = $db->prepare("INSERT INTO orders
        (buyer_id,channel,status,subtotal,discount,shipping,total,destination,payment_token,created_at,expires_at)
        SELECT ?,IF(MOD(n,2)=0,'web','store'),
            CASE MOD(n,6) WHEN 0 THEN 'unpaid' WHEN 1 THEN 'cancelled' ELSE 'paid' END,
            12900+MOD(n,80),0,IF(MOD(n,2)=0,800,0),12900+MOD(n,80)+IF(MOD(n,2)=0,800,0),'合成地址',
            MD5(CONCAT(?,':',n)), TIMESTAMPADD(DAY,-MOD(n,180),CAST(? AS DATETIME)),
            TIMESTAMPADD(MINUTE,15,TIMESTAMPADD(DAY,-MOD(n,180),CAST(? AS DATETIME)))
        FROM capacity_sequence WHERE n<? ORDER BY n");
    $insertOrders->execute([$buyer, $email, "$anchor 12:00:00", "$anchor 12:00:00", $count]);
    $children = $db->prepare("INSERT INTO merchant_orders (order_id,shop_id,status,total,shipping,placed_at)
        SELECT id,?,CASE status WHEN 'unpaid' THEN 'unpaid' WHEN 'cancelled' THEN 'cancelled'
            ELSE IF(MOD(id,3)=0,'ready','fulfilled') END,total,shipping,created_at
        FROM orders WHERE buyer_id=? ORDER BY id");
    $children->execute([$shop, $buyer]);
    if ($insertOrders->rowCount() !== $count || $children->rowCount() !== $count) {
        throw new RuntimeException('The generated order fixture has the wrong size.');
    }
    $tenants[$label] = ['shop_id' => $shop, 'email' => $email, 'count' => $count, 'buyer_id' => $buyer];
}
$firstDate = $date('-179 days');
$expectations = [];
foreach (['history' => [$firstDate, $anchor], 'one_day' => [$anchor, $anchor],
    'empty' => [$date('-200 days'), $date('-181 days')]] as $label => [$from, $to]) {
    $rows = $app->rows('SELECT m.id FROM orders o JOIN merchant_orders m ON m.order_id=o.id
        WHERE o.buyer_id=? AND m.shop_id=? AND DATE(o.created_at) BETWEEN ? AND ?
        ORDER BY o.created_at DESC,m.id DESC LIMIT 100', [$tenants['main']['buyer_id'], $tenants['main']['shop_id'], $from, $to]);
    $expectations[$label] = ['from' => $from, 'through' => $to, 'expected_ids' => array_map('intval', array_column($rows, 'id'))];
}
$plan = $app->rows('EXPLAIN SELECT m.id,m.order_id,m.placed_at,m.status,m.total,m.shipping,o.channel
    FROM merchant_orders m JOIN orders o ON o.id=m.order_id WHERE m.shop_id=? AND m.placed_at>=? AND m.placed_at<?
    ORDER BY m.placed_at DESC,m.id DESC LIMIT 51',
    [$tenants['main']['shop_id'], "$firstDate 00:00:00", $date('+1 day') . ' 00:00:00']);
printReport(['run' => $run, 'environment' => $environment, 'fixture_utc' => gmdate('c'), 'tenants' => $tenants,
    'scenarios' => $expectations, 'plan' => $plan, 'seed_seconds' => round(microtime(true) - $startedAt, 3)]);
