<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;

final class EmailDomainProvider
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Returns domains from explicit From addresses stored on real Mautic email entities.
     *
     * @return list<string>
     */
    public function getDomains(): array
    {
        $addresses = $this->connection->createQueryBuilder()
            ->select('DISTINCT e.from_address')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->where('e.from_address IS NOT NULL')
            ->andWhere("e.from_address <> ''")
            ->executeQuery()
            ->fetchFirstColumn();

        $domains = [];
        foreach ($addresses as $address) {
            $domain = DomainNormalizer::fromEmailAddress(is_string($address) ? $address : null);
            if (null !== $domain) {
                $domains[$domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains, SORT_STRING);

        return $domains;
    }

    public function getDomainForEmail(int $emailId): ?string
    {
        $address = $this->connection->createQueryBuilder()
            ->select('e.from_address')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->where('e.id = :id')
            ->setParameter('id', $emailId)
            ->executeQuery()
            ->fetchOne();

        return is_string($address) ? DomainNormalizer::fromEmailAddress($address) : null;
    }
}
