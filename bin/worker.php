<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$once = ($argv[1] ?? '') === '--once' && count($argv) === 2;
if (count($argv) > 1 && !$once) {
    fwrite(STDERR, "Usage: php bin/worker.php [--once]\n");
    exit(2);
}
$configured = getenv('COMMERCE_WORK_INTERVAL_SECONDS');
$interval = $configured === false ? 60 : filter_var($configured, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 3600],
]);
if ($interval === false) {
    fwrite(STDERR, "COMMERCE_WORK_INTERVAL_SECONDS must be between 1 and 3600.\n");
    exit(2);
}

function runMaintenanceCycle(): bool
{
    try {
        $db = database();
        assertSchemaReady($db);
        $app = new Commerce($db);
    } catch (Throwable $error) {
        fwrite(STDERR, gmdate('c') . ' database or schema unavailable (' . $error->getCode() . ")\n");
        return false;
    }
    $completed = true;
    foreach (['expire' => $app->expireOrders(...), 'reconcile' => $app->processChannelBills(...)] as $job => $run) {
        try {
            fwrite(STDOUT, gmdate('c') . " $job processed=" . $run() . "\n");
        } catch (Throwable $error) {
            fwrite(STDERR, gmdate('c') . " $job failed (" . $error->getCode() . ")\n");
            $completed = false;
        }
    }
    return $completed;
}

do {
    $completed = runMaintenanceCycle();
    if ($once) {
        exit($completed ? 0 : 1);
    }
    sleep($interval);
} while (true);
