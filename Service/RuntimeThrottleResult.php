<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class RuntimeThrottleResult
{
    public function __construct(
        public readonly bool $attempted,
        public readonly bool $successful,
        public readonly string $generation,
        public readonly mixed $value = null,
        public readonly ?\Throwable $error = null,
    ) {
    }

    public static function busy(): self
    {
        return new self(false, false, '');
    }

    public static function reused(string $generation, bool $successful): self
    {
        return new self(false, $successful, $generation);
    }

    public static function attempted(string $generation, mixed $value, ?\Throwable $error): self
    {
        return new self(true, null === $error, $generation, $value, $error);
    }
}
