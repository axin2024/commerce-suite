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
            $app->saveProduct($north, 0, 'NORTH-01', '山形帆布托特包', 12000, 36, 'active');
            $app->saveProduct($pond, 0, 'POND-01', '湖蓝陶瓷早餐杯', 8000, 24, 'active');
            $app->saveProduct($north, 0, 'NORTH-02', '烟灰折叠收纳盒', 6800, 12, 'active');
            $app->saveProduct($pond, 0, 'POND-02', '手工亚麻餐垫', 5200, 18, 'active');
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
