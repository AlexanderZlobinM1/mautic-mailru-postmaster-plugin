<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<DomainSyncPeriod>
 */
final class DomainSyncPeriodRepository extends CommonRepository
{
    public function isCompleted(string $domain, \DateTimeImmutable $monthStart): bool
    {
        return $this->findOneBy([
            'domain'     => $domain,
            'monthStart' => $monthStart->modify('first day of this month')->setTime(0, 0),
        ]) instanceof DomainSyncPeriod;
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

    public function pruneBeforeMonth(\DateTimeImmutable $monthStart): int
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete(MAUTIC_TABLE_PREFIX.'mailru_postmaster_sync_periods')
            ->where('month_start < :month_start')
            ->setParameter('month_start', $monthStart->modify('first day of this month')->format('Y-m-d'))
            ->executeStatement();
    }
}
