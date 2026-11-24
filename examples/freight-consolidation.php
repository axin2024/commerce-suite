<?php

declare(strict_types=1);

return [
    'consolidation_v1' => [
        'label' => '首仓原价，后续仓半价',
        'charge' => static function (int $baseFee, array $warehouseUnits): int {
            $extraWarehouses = count($warehouseUnits) - 1;
            return $baseFee + $extraWarehouses * intdiv($baseFee, 2);
        },
    ],
];
