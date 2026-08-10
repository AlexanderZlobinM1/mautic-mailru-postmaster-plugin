<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\PostmasterApiClient;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainSyncPeriodRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;

final class PostmasterSyncService
{
    private const MAX_DETAILED_PERIOD_DAYS = 30;
    private const API_REQUEST_SPACING_SECONDS = 7;

    public function __construct(
        private readonly PostmasterApiClient $apiClient,
        private readonly EmailDomainProvider $emailDomainProvider,
        private readonly DomainStatRepository $statRepository,
        private readonly DomainSyncPeriodRepository $syncPeriodRepository,
        private readonly GuardService $guardService,
        private readonly PostmasterConfiguration $configuration,
    ) {
    }

    public function requiresInitialBackfill(): bool
    {
        if ($this->syncPeriodRepository->isInitialBackfillCompleted()) {
            return false;
        }

        if ($this->syncPeriodRepository->adoptStoredMonths(new \DateTimeImmutable()) > 0) {
            $this->syncPeriodRepository->flush();
        }

        return true;
    }

    public function markInitialBackfillCompleted(): void
    {
        $this->syncPeriodRepository->markInitialBackfillCompleted(new \DateTimeImmutable());
    }

    public function sync(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        bool $onlyMissingMonths = false,
        bool $recordCompletedMonths = false,
    ): SyncResult
    {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date-from must not be later than date-to.');
        }

        if ((int) $dateFrom->diff($dateTo)->format('%a') + 1 > 365) {
            throw new \InvalidArgumentException('Mail.ru Postmaster accepts at most 365 days of statistics.');
        }

        $mauticDomains     = $this->emailDomainProvider->getDomains();
        $registeredDomains = $this->apiClient->getRegisteredDomains();
        $trackedDomains    = array_values(array_intersect($mauticDomains, $registeredDomains));
        sort($trackedDomains, SORT_STRING);
        $this->statRepository->setTrackedDomains($trackedDomains);

        $trackedLookup = array_fill_keys($trackedDomains, true);
        $storedRows    = 0;
        $syncedAt      = new \DateTimeImmutable();

        if ([] !== $trackedDomains) {
            /** @var array<string, float> $lastRequestAt */
            $lastRequestAt = [];
            foreach ($this->splitMonths($dateFrom, $dateTo) as [$monthFrom, $monthTo]) {
                // Keep API reads explicitly scoped to this Mautic instance's
                // verified sender domains instead of requesting every domain
                // registered under the shared Mail.ru account.
                foreach ($trackedDomains as $requestedDomain) {
                    if ($onlyMissingMonths && $this->syncPeriodRepository->isCompleted($requestedDomain, $monthFrom)) {
                        continue;
                    }

                    foreach ($this->splitPeriods($monthFrom, $monthTo) as [$periodFrom, $periodTo]) {
                        $this->throttleDomain($requestedDomain, $lastRequestAt);
                        foreach ($this->apiClient->getDetailedStatistics($periodFrom, $periodTo, $requestedDomain) as $domainBlock) {
                            $domain = DomainNormalizer::normalize((string) ($domainBlock['domain'] ?? ''));
                            if ($domain !== $requestedDomain || !isset($trackedLookup[$domain])) {
                                continue;
                            }

                            foreach (($domainBlock['data'] ?? []) as $row) {
                                if (!is_array($row) || empty($row['date'])) {
                                    continue;
                                }

                                try {
                                    $statDate = new \DateTimeImmutable((string) $row['date']);
                                } catch (\Exception) {
                                    continue;
                                }

                                if ($statDate < $periodFrom || $statDate > $periodTo) {
                                    continue;
                                }

                                $this->statRepository->stage($domain, $statDate, $row, $syncedAt);
                                ++$storedRows;
                            }
                        }
                    }

                    if ($recordCompletedMonths) {
                        // A successful API response, including an empty one, is
                        // proof that this exact domain/month was checked. The
                        // live API currently returns only a rolling recent
                        // window even for older documented date ranges, so row
                        // count cannot be used as a completeness signal.
                        $this->syncPeriodRepository->markCompleted($requestedDomain, $monthFrom, $syncedAt);
                    }
                }

                // Persist proof month by month. If a later API request fails,
                // the next backfill resumes from the first unfinished month
                // instead of repeating every earlier successful request.
                if ($recordCompletedMonths) {
                    $this->statRepository->flush();
                }
            }

            if (!$recordCompletedMonths) {
                $this->statRepository->flush();
            }
        }

        $retentionDays = $this->configuration->getRetentionDays();
        $retentionCutoff = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $retentionDays - 1));
        $this->statRepository->pruneBefore($retentionCutoff);
        $this->syncPeriodRepository->pruneBeforeMonth($retentionCutoff);

        return new SyncResult(
            $mauticDomains,
            $registeredDomains,
            $trackedDomains,
            $storedRows,
            $this->guardService->evaluateAll($syncedAt),
        );
    }

    /**
     * @param array<string, float> $lastRequestAt
     */
    private function throttleDomain(string $domain, array &$lastRequestAt): void
    {
        $now = microtime(true);
        if (isset($lastRequestAt[$domain])) {
            $remaining = self::API_REQUEST_SPACING_SECONDS - ($now - $lastRequestAt[$domain]);
            if ($remaining > 0) {
                usleep((int) ceil($remaining * 1_000_000));
            }
        }
        $lastRequestAt[$domain] = microtime(true);
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function splitMonths(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $months = [];
        $cursor = $dateFrom;
        while ($cursor <= $dateTo) {
            $monthTo = $cursor->modify('last day of this month');
            if ($monthTo > $dateTo) {
                $monthTo = $dateTo;
            }
            $months[] = [$cursor, $monthTo];
            $cursor = $monthTo->modify('+1 day');
        }

        return $months;
    }

    public function syncActiveGuardDomains(?\DateTimeImmutable $now = null): SyncResult
    {
        $now ??= new \DateTimeImmutable();
        $statDate = $now->setTime(0, 0);
        $activeDomains = $this->guardService->getActiveDomains($now);
        $trackedLookup = array_fill_keys($this->statRepository->getTrackedDomains(), true);
        $domains = array_values(array_filter(
            $activeDomains,
            static fn (string $domain): bool => isset($trackedLookup[$domain]),
        ));
        sort($domains, SORT_STRING);

        $storedRows = 0;
        foreach ($domains as $domain) {
            foreach ($this->apiClient->getDetailedStatistics($statDate, $statDate, $domain) as $domainBlock) {
                $responseDomain = DomainNormalizer::normalize((string) ($domainBlock['domain'] ?? ''));
                if ($responseDomain !== $domain) {
                    continue;
                }
                foreach (($domainBlock['data'] ?? []) as $row) {
                    if (!is_array($row) || ($row['date'] ?? null) !== $statDate->format('Y-m-d')) {
                        continue;
                    }
                    $this->statRepository->stage($domain, $statDate, $row, $now);
                    ++$storedRows;
                }
            }
        }

        if ($storedRows > 0) {
            $this->statRepository->flush();
        }

        return new SyncResult(
            $activeDomains,
            [],
            $domains,
            $storedRows,
            $this->guardService->evaluateAll($now),
        );
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function splitPeriods(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $periods = [];
        $cursor = $dateFrom;
        while ($cursor <= $dateTo) {
            $periodTo = $cursor->modify(sprintf('+%d days', self::MAX_DETAILED_PERIOD_DAYS - 1));
            if ($periodTo > $dateTo) {
                $periodTo = $dateTo;
            }
            $periods[] = [$cursor, $periodTo];
            $cursor = $periodTo->modify('+1 day');
        }

        return $periods;
    }
}
