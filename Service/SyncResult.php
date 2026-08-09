<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class SyncResult
{
    /**
     * @param list<string> $mauticDomains
     * @param list<string> $registeredDomains
     * @param list<string> $trackedDomains
     */
    public function __construct(
        public readonly array $mauticDomains,
        public readonly array $registeredDomains,
        public readonly array $trackedDomains,
        public readonly int $storedRows,
        public readonly int $stoppedCampaigns,
    ) {
    }
}
