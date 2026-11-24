<?php

declare(strict_types=1);

final class PromotionStrategies
{
    public const FIXED = 'fixed_v1';
    public const PERCENTAGE = 'percentage_v1';
    private const BASIS = 10_000;
    private array $strategies;

    public function __construct(array $extensions = [])
    {
        $this->strategies = [
            self::FIXED => ['label' => '固定满减', 'uses_rate' => false,
                'discount' => static fn(int $goods, int $threshold, int $limit, ?int $rate): int => $limit],
            self::PERCENTAGE => ['label' => '按商品金额比例优惠', 'uses_rate' => true,
                'discount' => static fn(int $goods, int $threshold, int $limit, ?int $rate): int
                    => min($limit, intdiv($goods * $rate, self::BASIS))],
        ];
        foreach ($extensions as $id => $strategy) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,24}_v[1-9][0-9]{0,3}$/D', $id)
                || isset($this->strategies[$id]) || !is_array($strategy)
                || !is_string($strategy['label'] ?? null) || trim($strategy['label']) === ''
                || strlen($strategy['label']) > 120 || !preg_match('//u', $strategy['label'])
                || !is_bool($strategy['uses_rate'] ?? null)
                || !(($strategy['discount'] ?? null) instanceof Closure)) {
                throw new RuntimeException('Invalid promotion strategy registration.');
            }
            $this->strategies[$id] = $strategy;
        }
    }

    public static function fromEnvironment(): self
    {
        $file = getenv('COMMERCE_PROMOTION_EXTENSION');
        if ($file === false || $file === '') {
            return new self();
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Cannot read the configured promotion extension.');
        }
        $definitions = require $file;
        if (!is_array($definitions)) {
            throw new RuntimeException('Promotion extension must return strategy definitions.');
        }
        return new self($definitions);
    }

    public function labels(): array
    {
        return array_map(static fn(array $strategy): string => $strategy['label'], $this->strategies);
    }

    public function accepts(string $id, ?int $rate): bool
    {
        return isset($this->strategies[$id]) && $this->strategies[$id]['uses_rate'] === ($rate !== null)
            && ($rate === null || ($rate >= 1 && $rate <= self::BASIS));
    }

    public function discount(string $id, int $goods, int $threshold, int $limit, ?int $rate): int
    {
        if (!$this->accepts($id, $rate)) {
            throw new RuleViolation('活动计费暂不可用，请联系平台。');
        }
        try {
            $discount = $this->strategies[$id]['discount']($goods, $threshold, $limit, $rate);
            if (!is_int($discount) || $discount < 0 || $discount > min($limit, $goods)) {
                throw new UnexpectedValueException('Promotion must return a bounded integer discount in cents.');
            }
            return $discount;
        } catch (Throwable $error) {
            error_log("Promotion strategy $id failed (" . $error::class . ').');
            throw new RuleViolation('活动计费暂不可用，请联系平台。');
        }
    }
}
