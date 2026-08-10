<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\PostmasterApiClient;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;
use Psr\Log\LoggerInterface;

final class GuardRuntimePoller
{
    public function __construct(
        private readonly PostmasterApiClient $apiClient,
        private readonly GuardDomainResolver $domainResolver,
        private readonly DomainStatRepository $statRepository,
        private readonly RuntimeApiThrottle $throttle,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pollGuard(Event $guard, string $source): RuntimePollResult
    {
        $domain = $this->domainResolver->resolve($guard);

        return null === $domain ? RuntimePollResult::noDomain() : $this->pollDomain($domain, $source);
    }

    public function pollDomain(string $domain, string $source): RuntimePollResult
    {
        $domain = DomainNormalizer::normalize($domain);
        if (null === $domain) {
            return RuntimePollResult::noDomain();
        }

        try {
            $attempt = $this->throttle->runIfDue($domain, function () use ($domain): int {
                $statDate = new \DateTimeImmutable('today');
                $storedRows = 0;
                foreach ($this->apiClient->getDetailedStatistics($statDate, $statDate, $domain) as $domainBlock) {
                    $responseDomain = DomainNormalizer::normalize((string) ($domainBlock['domain'] ?? ''));
                    if ($responseDomain !== $domain) {
                        continue;
                    }

                    foreach (($domainBlock['data'] ?? []) as $row) {
                        if (!is_array($row) || ($row['date'] ?? null) !== $statDate->format('Y-m-d')) {
                            continue;
                        }
                        $this->statRepository->stage(
                            $domain,
                            $statDate,
                            $row,
                            new \DateTimeImmutable(),
                        );
                        ++$storedRows;
                    }
                }

                if ($storedRows > 0) {
                    $this->statRepository->flush();
                }

                return $storedRows;
            });
        } catch (\Throwable $exception) {
            $this->logger->warning('mailru_postmaster.runtime_poll_failed', [
                'source'    => $source,
                'domain'    => $domain,
                'exception' => $exception,
            ]);

            return new RuntimePollResult($domain, true, false, sprintf('error-%.6f', microtime(true)));
        }

        if ($attempt->error instanceof \Throwable) {
            $this->logger->warning('mailru_postmaster.runtime_poll_failed', [
                'source'    => $source,
                'domain'    => $domain,
                'exception' => $attempt->error,
            ]);
        } elseif ($attempt->attempted) {
            $this->logger->info('mailru_postmaster.runtime_poll', [
                'source'      => $source,
                'domain'      => $domain,
                'generation'  => $attempt->generation,
                'stored_rows' => (int) $attempt->value,
            ]);
        }

        return new RuntimePollResult(
            $domain,
            $attempt->attempted,
            $attempt->successful,
            $attempt->generation,
            $attempt->error instanceof \Throwable ? 0 : (int) $attempt->value,
        );
    }
}
