<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class RuntimePollResult
{
    public function __construct(
        public readonly ?string $domain,
        public readonly bool $attempted,
        public readonly bool $successful,
        public readonly string $generation,
        public readonly int $storedRows = 0,
    ) {
    }

    public static function noDomain(): self
    {
        return new self(null, false, false, '');
    }
}
