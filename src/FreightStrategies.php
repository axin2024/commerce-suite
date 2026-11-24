<?php

declare(strict_types=1);

final class FreightStrategies
{
    public const DEFAULT = 'per_warehouse_v1';
    public const DEFAULT_FEE = 800;
    public const MAX_BASE_FEE = 1_000_000;
    public const MAX_FREE_THRESHOLD = 99_999_999_900;

    private array $strategies;

    public function __construct(array $extensions = [])
    {
        $this->strategies = [
            self::DEFAULT => ['label' => '按发货仓计费',
                'charge' => static fn(int $baseFee, array $warehouses): int => $baseFee * count($warehouses)],
            'per_order_v1' => ['label' => '本店每单固定运费',
                'charge' => static fn(int $baseFee, array $warehouses): int => $baseFee],
        ];
        foreach ($extensions as $id => $strategy) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,24}_v[1-9][0-9]{0,3}$/D', $id)
                || isset($this->strategies[$id]) || !is_array($strategy)
                || !is_string($strategy['label'] ?? null) || trim($strategy['label']) === ''
                || strlen($strategy['label']) > 120 || !preg_match('//u', $strategy['label'])
                || !(($strategy['charge'] ?? null) instanceof Closure)) {
                throw new RuntimeException('Invalid freight strategy registration.');
            }
            $this->strategies[$id] = $strategy;
        }
    }

    public static function fromEnvironment(): self
    {
        $file = getenv('COMMERCE_FREIGHT_EXTENSION');
        if ($file === false || $file === '') {
            return new self();
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Cannot read the configured freight extension.');
        }
        $definitions = require $file;
        if (!is_array($definitions)) {
            throw new RuntimeException('Freight extension must return strategy definitions.');
        }
        return new self($definitions);
    }

    public function labels(): array
    {
        return array_map(static fn(array $strategy): string => $strategy['label'], $this->strategies);
    }

    public function supports(string $id): bool
    {
        return isset($this->strategies[$id]);
    }

    public function charge(string $id, int $baseFee, array $warehouses): int
    {
        if (!$this->supports($id)) {
            throw new RuleViolation('店铺运费规则暂不可用，请联系商家。');
        }
        try {
            ksort($warehouses, SORT_NUMERIC);
            $charge = $this->strategies[$id]['charge']($baseFee, $warehouses);
            if (!is_int($charge) || $charge < 0 || $charge > 3_000_000_000) {
                throw new UnexpectedValueException('Freight must be a bounded integer in cents.');
            }
            return $charge;
        } catch (Throwable $error) {
            error_log("Freight strategy $id failed (" . $error::class . ').');
            throw new RuleViolation('店铺运费规则暂不可用，请联系商家。');
        }
    }
}
