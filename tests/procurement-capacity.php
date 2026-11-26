<?php

declare(strict_types=1);

// Included by capacity.php after checking APP_DEMO and the disposable database name.
if ($mode==='procurement-worker') {
    [$buyer,$store,$product,$rfq,$bid] = array_map('intval',array_slice($argv,2));
    $day = gmdate('Y-m-d');
    $startWarm = hrtime(true);
    for ($i=0;$i<4;$i++) {
        $app->procurementQuote($buyer,$store,$product,100,$day);
    }
    $warmMs = (hrtime(true)-$startWarm)/1e6;
    echo "ready\n";
    fflush(STDOUT);
    $signal = fgets(STDIN);
    if (!$signal || !is_numeric(trim($signal))) {
        exit(2);
    }
    $release = (float)$signal;
    while (($left=$release-microtime(true))>0) {
        usleep((int)min(50000,ceil($left*1e6)));
    }
    $start = microtime(true);
    $quote = $app->procurementQuote($buyer,$store,$product,100,$day);
    $quoteMs = (microtime(true)-$start)*1000;
    try {
        $award = $app->proposeProcurementAward($buyer,$rfq,$bid,$store,[['line'=>1,'qty'=>100]],'capacity-'.$rfq);
        $status = $award['order_id'] ? 'ordered' : 'unexpected';
    } catch (RuleViolation $e) {
        $status = str_starts_with($e->getMessage(),'法人授信不足') ? 'credit_rejected' : $e->getMessage();
    }
    echo json_encode(['buyer'=>$buyer,'rfq'=>$rfq,'outcome'=>$status,'quote_minor'=>$quote['total_minor'],
        'warm_ms'=>round($warmMs,3),'offset_ms'=>round(($start-$release)*1000,3),'quote_ms'=>round($quoteMs,3),
        'elapsed_ms'=>round((microtime(true)-$start)*1000,3)],JSON_THROW_ON_ERROR);
    exit;
}

$tag = 'proc-'.bin2hex(random_bytes(4));
$password = 'Capacity-Demo-2026';
$admin = $app->provisionOperator("$tag-admin@example.test",$password,'admin');
$finance = $app->provisionOperator("$tag-finance@example.test",$password,'finance');
[$owner,$shop] = createCapacityShop($app,$admin,$tag,"$tag-owner@example.test",$password);
$manager = $app->register("$tag-hq@example.test",$password);
$company = $app->enrollProcurementCompany($admin,$tag,'容量演练法人',"$tag-hq@example.test");
$store = $app->addProcurementStore($manager,$company,'HQ','总部仓','采购部','测试收货地址');
$app->authorizeProcurementSupplier($manager,$company,$shop,true);
$app->setProcurementCredit($finance,$company,900000);
$app->setProcurementBudget($manager,$store,gmdate('Y-m'),9000000,9000000);
$db->exec('CREATE TEMPORARY TABLE procurement_sequence AS WITH d AS (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2
    UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)
    SELECT a.n+10*b.n+100*c.n+1000*e.n AS n FROM d a CROSS JOIN d b CROSS JOIN d c CROSS JOIN d e');
$db->prepare("INSERT INTO products (shop_id,sku,name,price,stock,status)
    SELECT ?,CONCAT('SKU-',n),CONCAT('采购物料-',n),1000,1000,'active' FROM procurement_sequence ORDER BY n")->execute([$shop]);
$db->prepare("INSERT INTO procurement_agreements (company_id,product_id,revision,valid_from,valid_until,author_id)
    SELECT ?,id,1,'2020-01-01','2099-12-31',? FROM products WHERE shop_id=?")->execute([$company,$owner,$shop]);
$db->prepare('INSERT INTO procurement_price_tiers (agreement_id,quantity_min,quantity_max,priority,price_minor)
    SELECT id,1,1000000,0,900 FROM procurement_agreements WHERE company_id=?')->execute([$company]);
$products = array_column($app->rows('SELECT id FROM products WHERE shop_id=? ORDER BY id',[$shop]),'id');
$jobs = [];
for ($i=0;$i<100;$i++) {
    $email = "$tag-$i@example.test";
    $buyer = $app->register($email,$password);
    $app->changeProcurementMember($manager,$store,$email,true);
    $product = (int)$products[$i*97];
    $rfq = $app->openProcurementTender($buyer,$store,'集中采购','2099-12-31 00:00:00',[['specification'=>'物料','quantity'=>100]],[$shop]);
    $bid = $app->submitProcurementBid($owner,$rfq,0,[1=>$product],0);
    $jobs[] = [$buyer,$store,$product,$rfq,$bid];
}
$db->prepare('UPDATE procurement_rfqs SET deadline=UTC_TIMESTAMP() WHERE store_id=?')->execute([$store]);
[$release,$results] = checkoutTogether($jobs,'procurement-worker');
$latencies = array_column($results,'quote_ms');
sort($latencies,SORT_NUMERIC);
$counts = array_count_values(array_column($results,'outcome'));
$credit = $app->rows('SELECT COALESCE(SUM(amount_minor),0) AS used_minor,COUNT(*) AS entries FROM procurement_credit_entries WHERE company_id=?',[$company])[0];
$budget = $app->rows('SELECT used_minor FROM procurement_budgets WHERE company_id=?',[$company])[0]['used_minor'];
$nPrices = (int)$app->rows('SELECT COUNT(*) AS n FROM procurement_price_tiers t JOIN procurement_agreements a ON a.id=t.agreement_id WHERE a.company_id=?',[$company])[0]['n'];
$valid = array_filter($results,static fn($r)=>isset($r['quote_ms'],$r['elapsed_ms'],$r['offset_ms'],$r['warm_ms']));
$p95 = count($latencies)===100 ? $latencies[94] : null;
$passed = count($valid)===100 && $nPrices===10000 && $p95!==null && $p95<=800 && ($counts['ordered'] ?? 0)===10
    && ($counts['credit_rejected'] ?? 0)===90 && (int)$credit['used_minor']===900000 && (int)$credit['entries']===10
    && (int)$budget===900000 && count(array_filter($results,static fn($r)=>($r['quote_minor'] ?? 0)===90000))===100;
printReport(['status'=>$passed?'passed':'failed','barrier_utc'=>gmdate('c',(int)$release),'environment'=>[
    'os'=>PHP_OS_FAMILY,'cpu'=>getenv('PROCESSOR_IDENTIFIER') ?: php_uname('m'),'logical_processors'=>getenv('NUMBER_OF_PROCESSORS') ?: null,
    'php'=>PHP_VERSION,'mysql'=>$db->query('SELECT VERSION()')->fetchColumn(),'buffer_pool_bytes'=>$db->query('SELECT @@innodb_buffer_pool_size')->fetchColumn()],
    'agreement_prices'=>$nPrices,'buyers'=>count($jobs),'warmups_per_buyer'=>4,'warm_ms_max'=>$valid?max(array_column($valid,'warm_ms')):null,
    'quote_sample_count'=>count($latencies),'quote_p95_ms'=>$p95,'sample_window_ms'=>$valid?max(array_map(static fn($r)=>$r['offset_ms']+$r['elapsed_ms'],$valid)):null,
    'outcomes'=>$counts,'missing_or_failed_workers'=>100-count($valid),'credit_limit_minor'=>900000,'persisted_credit'=>$credit,
    'budget_used_minor'=>$budget,'requests'=>$results]);
exit($passed?0:1);
