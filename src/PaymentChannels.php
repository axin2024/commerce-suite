<?php

declare(strict_types=1);

final class InvalidPaymentSignature extends RuntimeException {}

final class PaymentChannels
{
    public function __construct(private readonly array $channels = [], public readonly ?string $checkoutProvider = null)
    {
        foreach ($channels as $provider => $channel) {
            if (!is_string($provider) || !self::validProvider($provider)
                || in_array($provider, ['demo', 'sandbox', 'promotion', 'internal'], true)
                || !is_array($channel) || !(($channel['checkout'] ?? null) instanceof Closure)
                || !(($channel['verify'] ?? null) instanceof Closure)) {
                throw new RuntimeException('Invalid payment channel registration.');
            }
        }
        if ($checkoutProvider !== null && !$this->supports($checkoutProvider)) {
            throw new RuntimeException('The configured checkout channel is not registered.');
        }
    }

    public static function fromEnvironment(): self
    {
        $file = getenv('COMMERCE_PAYMENT_EXTENSION');
        $channels = [];
        if ($file !== false && $file !== '') {
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Cannot read the configured payment extension.');
            }
            $channels = require $file;
            if (!is_array($channels)) {
                throw new RuntimeException('Payment extension must return channel definitions.');
            }
        }
        $provider = getenv('COMMERCE_CHECKOUT_CHANNEL');
        return new self($channels, $provider === false || $provider === '' ? null : $provider);
    }

    public static function validProvider(string $provider): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $provider) === 1;
    }

    public function supports(string $provider): bool
    {
        return isset($this->channels[$provider]);
    }

    public function checkout(string $provider, array $intent): string
    {
        if (!$this->supports($provider)) {
            throw new RuleViolation('原支付渠道暂不可用，请稍后重试。');
        }
        $url = $this->channels[$provider]['checkout']($intent);
        if (!is_string($url) || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null) {
            throw new RuntimeException('Payment channel must return a valid HTTPS checkout URL.');
        }
        return $url;
    }

    public function verifiedEvent(string $provider, string $body, array $headers): ?array
    {
        if (!$this->supports($provider)) {
            throw new RuleViolation('支付渠道未注册。');
        }
        return $this->channels[$provider]['verify']($body, $headers);
    }
}
