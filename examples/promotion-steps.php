<?php

declare(strict_types=1);

return ['threshold_steps_v1' => [
    'label' => '按满额档位累计优惠',
    'uses_rate' => true,
    'discount' => static function (int $goods, int $threshold, int $limit, ?int $rate): int {
        $stepDiscount = intdiv($threshold * $rate, 10_000);
        return min($limit, intdiv($goods, $threshold) * $stepDiscount);
    },
]];
