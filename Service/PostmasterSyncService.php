<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\PostmasterApiClient;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;

final class PostmasterSyncService
{
    public function __construct(
        private readonly PostmasterApiClient $apiClient,
        private readonly EmailDomainProvider $emailDomainProvider,
        private readonly DomainStatRepository $statRepository,
        private readonly GuardService $guardService,
    ) {
    }

    public function sync(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): SyncResult
    {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date-from must not be later than date-to.');
        }

        if ($dateFrom < $dateTo->modify('-1 year')) {
            throw new \InvalidArgumentException('Mail.ru Postmaster accepts at most one year of statistics.');
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
            foreach ($this->apiClient->getDetailedStatistics($dateFrom, $dateTo) as $domainBlock) {
                $domain = DomainNormalizer::normalize((string) ($domainBlock['domain'] ?? ''));
                if (null === $domain || !isset($trackedLookup[$domain])) {
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

                    if ($statDate < $dateFrom || $statDate > $dateTo) {
                        continue;
                    }

                    $this->statRepository->stage($domain, $statDate, $row, $syncedAt);
                    ++$storedRows;
                }
            }

            $this->statRepository->flush();
        }

        return new SyncResult(
            $mauticDomains,
            $registeredDomains,
            $trackedDomains,
            $storedRows,
            $this->guardService->evaluateAll($syncedAt),
        );
    }
}
