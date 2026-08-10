<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<DomainSyncPeriod>
 */
final class DomainSyncPeriodRepository extends CommonRepository
{
    private const INITIAL_BACKFILL_MARKER = '__plugin_initial_backfill__';

    public function isCompleted(string $domain, \DateTimeImmutable $monthStart): bool
    {
        return $this->findOneBy([
            'domain'     => $domain,
            'monthStart' => $monthStart->modify('first day of this month')->setTime(0, 0),
        ]) instanceof DomainSyncPeriod;
    }

    public function isInitialBackfillCompleted(): bool
    {
        return $this->findOneBy(['domain' => self::INITIAL_BACKFILL_MARKER]) instanceof DomainSyncPeriod;
    }

    public function markCompleted(string $domain, \DateTimeImmutable $monthStart, \DateTimeImmutable $completedAt): void
    {
        $monthStart = $monthStart->modify('first day of this month')->setTime(0, 0);
        $period = $this->findOneBy(['domain' => $domain, 'monthStart' => $monthStart]);
        if (!$period instanceof DomainSyncPeriod) {
            $period = new DomainSyncPeriod();
        }
        $period->complete($domain, $monthStart, $completedAt);
        $this->getEntityManager()->persist($period);
    }

    public function markInitialBackfillCompleted(\DateTimeImmutable $completedAt): void
    {
        $this->markCompleted(self::INITIAL_BACKFILL_MARKER, new \DateTimeImmutable('2000-01-01'), $completedAt);
        $this->flush();
    }

    public function adoptStoredMonths(\DateTimeImmutable $syncedAt): int
    {
        $table = MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats';
        $rows = $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT domain, DATE_FORMAT(stat_date, '%Y-%m-01') AS month_start
            FROM {$table}
            GROUP BY domain, DATE_FORMAT(stat_date, '%Y-%m-01')
            SQL)->fetchAllAssociative();

        $currentMonth = $syncedAt->modify('first day of this month')->setTime(0, 0);
        $adopted = 0;
        foreach ($rows as $row) {
            $monthStart = new \DateTimeImmutable((string) $row['month_start']);
            if ($monthStart >= $currentMonth) {
                continue;
            }
            $this->markCompleted((string) $row['domain'], $monthStart, $syncedAt);
            ++$adopted;
        }

        return $adopted;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function pruneBeforeMonth(\DateTimeImmutable $monthStart): int
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete(MAUTIC_TABLE_PREFIX.'mailru_postmaster_sync_periods')
            ->where('month_start < :month_start')
            ->andWhere('domain <> :initial_marker')
            ->setParameter('month_start', $monthStart->modify('first day of this month')->format('Y-m-d'))
            ->setParameter('initial_marker', self::INITIAL_BACKFILL_MARKER)
            ->executeStatement();
    }
}
