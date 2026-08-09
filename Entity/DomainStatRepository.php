<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<DomainStat>
 */
final class DomainStatRepository extends CommonRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function stage(string $domain, \DateTimeImmutable $statDate, array $data, \DateTimeImmutable $syncedAt): DomainStat
    {
        $stat = $this->findOneBy(['domain' => $domain, 'statDate' => $statDate]);
        if (!$stat instanceof DomainStat) {
            $stat = new DomainStat();
        }

        $stat->update($domain, $statDate, $data, $syncedAt);
        $this->getEntityManager()->persist($stat);

        return $stat;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function getLatestForDomain(string $domain): ?DomainStat
    {
        $stat = $this->findOneBy(['domain' => $domain, 'isTracked' => true], ['statDate' => 'DESC']);

        return $stat instanceof DomainStat ? $stat : null;
    }

    /**
     * @return list<string>
     */
    public function getTrackedDomains(): array
    {
        $domains = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('DISTINCT domain')
            ->from(MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats')
            ->where('is_tracked = 1')
            ->orderBy('domain', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($domains, 'is_string'));
    }

    public function pruneBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete(MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats')
            ->where('stat_date < :cutoff')
            ->setParameter('cutoff', $cutoff->format('Y-m-d'))
            ->executeStatement();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLatestRows(): array
    {
        $table = MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats';
        $sql   = <<<SQL
            SELECT s.*
            FROM {$table} s
            INNER JOIN (
                SELECT domain, MAX(stat_date) AS latest_date
                FROM {$table}
                WHERE is_tracked = 1
                GROUP BY domain
            ) latest ON latest.domain = s.domain AND latest.latest_date = s.stat_date
            WHERE s.is_tracked = 1
            ORDER BY s.domain ASC
            SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRowsForDomain(string $domain, int $limit = 366): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('*')
            ->from(MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats')
            ->where('domain = :domain')
            ->andWhere('is_tracked = 1')
            ->setParameter('domain', $domain)
            ->orderBy('stat_date', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<string> $domains
     */
    public function setTrackedDomains(array $domains): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats';
        $connection->executeStatement("UPDATE {$table} SET is_tracked = 0 WHERE is_tracked = 1");

        foreach ($domains as $domain) {
            $connection->update($table, ['is_tracked' => 1], ['domain' => $domain]);
        }
    }
}
