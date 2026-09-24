<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

/**
 * Read-only boundary for consumers of already synchronized Postmaster data.
 */
interface DomainStatReader
{
    public function getLatestForDomain(string $domain): ?DomainStat;
}
