<?php

declare(strict_types=1);

final class InvalidCarrierSignature extends RuntimeException {}

final class CarrierAdapters
{
    public function __construct(private readonly array $adapters = [])
    {
        foreach ($adapters as $carrier => $verify) {
            if (!is_string($carrier) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $carrier)
                || in_array($carrier, ['demo', 'sandbox'], true) || !($verify instanceof Closure)) {
                throw new RuntimeException('Invalid carrier adapter registration.');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $file = getenv('COMMERCE_CARRIER_EXTENSION');
        if ($file === false || $file === '') {
            return new self();
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Cannot read the configured carrier extension.');
        }
        $adapters = require $file;
        if (!is_array($adapters)) {
            throw new RuntimeException('Carrier extension must return adapter definitions.');
        }
        return new self($adapters);
    }

    public function supports(string $carrier): bool
    {
        return isset($this->adapters[$carrier]);
    }

    public function verifiedEvent(string $carrier, string $body, array $headers): ?array
    {
        if (!$this->supports($carrier)) {
            throw new RuleViolation('承运商未注册。');
        }
        return ($this->adapters[$carrier])($body, $headers);
    }
}
