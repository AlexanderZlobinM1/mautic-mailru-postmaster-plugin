<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\PostmasterApiClient;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;

final class PostmasterSyncService
{
    private const MAX_DETAILED_PERIOD_DAYS = 30;
    private const API_REQUEST_SPACING_SECONDS = 7;
    private const ROLLING_WINDOW_DAYS = 30;

    public function __construct(
        private readonly PostmasterApiClient $apiClient,
        private readonly EmailDomainProvider $emailDomainProvider,
        private readonly DomainStatRepository $statRepository,
        private readonly GuardService $guardService,
    ) {
    }

    public function sync(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): SyncResult
    {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date-from must not be later than date-to.');
        }

        $today = new \DateTimeImmutable('today');
        if ((int) $dateFrom->diff($dateTo)->format('%a') + 1 > self::ROLLING_WINDOW_DAYS
            || $dateFrom < $today->modify('-29 days')
            || $dateTo > $today) {
            throw new \InvalidArgumentException('Mail.ru Postmaster exposes only the rolling last 30 days.');
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
            // Keep API reads explicitly scoped to this Mautic instance's
            // verified sender domains instead of requesting every domain
            // registered under the shared Mail.ru account.
            foreach ($trackedDomains as $requestedDomain) {
                foreach ($this->splitPeriods($dateFrom, $dateTo) as [$periodFrom, $periodTo]) {
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
            }
            $this->statRepository->flush();
        }

        $retentionCutoff = $today->modify('-29 days');
        $this->statRepository->pruneBefore($retentionCutoff);

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
