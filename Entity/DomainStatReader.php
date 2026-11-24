<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

/**
 * Read-only boundary for consumers of already synchronized Postmaster data.
 */
interface DomainStatReader
{
    public function getLatestForDomain(string $domain): ?DomainStat;

    /**
     * @return list<DomainStat>
     */
    public function getForDomainBetween(
        string $domain,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array;
}
