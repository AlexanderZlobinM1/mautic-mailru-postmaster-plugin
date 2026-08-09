<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class GuardResult
{
    private function __construct(
        public readonly bool $stopped,
        public readonly string $reason,
    ) {
    }

    public static function pass(string $reason = ''): self
    {
        return new self(false, $reason);
    }

    public static function stopped(string $reason): self
    {
        return new self(true, $reason);
    }
}
