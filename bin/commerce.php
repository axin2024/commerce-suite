<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Migrator.php';

try {
    $db = database();
    $app = new Commerce($db);
    switch ($argv[1] ?? '') {
        case 'migrate':
            $applied = (new Migrator($db))->run(__DIR__ . '/../database');
            echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : "Schema is current.\n";
            break;
        case 'operator':
            $password = getenv('COMMERCE_OPERATOR_PASSWORD') ?: '';
            $id = $app->provisionOperator($argv[3] ?? '', $password, $argv[2] ?? '');
            echo "Operator #$id created.\n";
            break;
        case 'seed':
            if (!demoEnabled()) {
                throw new RuleViolation('APP_DEMO=1 is required to seed a disposable demo database.');
            }
            if ($db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuleViolation('Demo seeding requires an empty users table.');
            }
            $password = 'CommerceDemo-2026';
            $admin = $app->provisionOperator('admin@example.test', $password, 'admin');
            $app->provisionOperator('finance@example.test', $password, 'finance');
            $app->register('buyer@example.test', $password);
            $north = $app->register('north@example.test', $password, '北仓选物', '北仓选物演示商行');
            $pond = $app->register('pond@example.test', $password, '池畔工房', '池畔工房演示商行');
            foreach ([$north, $pond] as $merchant) {
                $app->reviewShop($admin, (int) $app->profile($merchant)['shop_id'], true);
            }
            foreach ([$north => '北仓河畔店', $pond => '池畔工房集市店'] as $merchant => $name) {
                $warehouse = (int) $app->merchantWarehouses($merchant)[0]['id'];
                $app->savePickupLocation($merchant, 0, $warehouse, 'STORE', $name, '演示园区 · ' . $name, true);
            }
            $bags = $app->createCategory($admin, '箱包');
            $home = $app->createCategory($admin, '家居');
            foreach ([
                [$north, 'NORTH-01', '山形帆布托特包', 12000, 36, $bags, '山形帆布包', '砖红', 'M'],
                [$pond, 'POND-01', '湖蓝陶瓷早餐杯', 8000, 24, $home, '池畔瓷器', '湖蓝', '300ml'],
                [$north, 'NORTH-02', '烟灰折叠收纳盒', 6800, 12, $home, '折叠收纳盒', '烟灰', 'S'],
                [$pond, 'POND-02', '手工亚麻餐垫', 5200, 18, $home, '亚麻餐垫', '原麻', 'M'],
            ] as [$merchant, $sku, $name, $price, $stock, $category, $family, $color, $size]) {
                $release = $app->submitSku($merchant, 0, $sku, $name, $price, $stock, $category, $family, $color, $size, true, true);
                $app->reviewSku($admin, $release, true);
            }
            $app->publishPromotion($admin, 'marketplace-opening', '跨店满 200 减 20', 20000, 2000, true, true);
            echo "Demo accounts and catalog created. See README.\n";
            break;
        case 'expire':
            echo 'Expired orders released: ', $app->expireOrders(), "\n";
            break;
        default:
            fwrite(STDERR, "Usage: php bin/commerce.php migrate|seed|expire|operator <admin|finance> <email>\n");
            exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
