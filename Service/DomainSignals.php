<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatReader;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;

/**
 * Stable, read-only integration contract for optional sibling plugins.
 *
 * This service never calls Mail.ru. It only exposes the latest row already
 * persisted by the Postmaster synchronizer for an exact DKIM d= domain.
 */
final class DomainSignals
{
    public const CONTRACT_VERSION = 1;
    public const FRESHNESS_MAX_AGE_SECONDS = 1200;
    public const LOW_SAMPLE_THRESHOLD_MESSAGES = 100;

    public function __construct(
        private readonly DomainStatReader $statReader,
        private readonly PostmasterConfiguration $configuration,
    ) {
    }

    /**
     * @return array{
     *     contract_version: int,
     *     source: string,
     *     scope: string,
     *     available: bool,
     *     reason: ?string,
     *     fresh: bool,
     *     freshness_max_age_seconds: int,
     *     low_sample: bool,
     *     low_sample_threshold_messages: int,
     *     identity: array{kind: string, requested_domain: ?string, matched_domain: ?string, exact_match: bool},
     *     observed: array{stat_date: ?string, synced_at: ?string, age_seconds: ?int},
     *     counts: array{messages_sent: ?int, delivered_inbox: ?int, complaints: ?int, spam_rejected: ?int, probably_spam: ?int},
     *     rates: array{spam_percent: ?float, probably_spam_percent: ?float, reputation_percent_30d: ?float, trend_percent: ?float},
     *     units: array{counts: string, rates: string}
     * }
     */
    public function snapshot(string $dkimDomain, ?\DateTimeImmutable $now = null): array
    {
        $domain = DomainNormalizer::normalize($dkimDomain);
        if (null === $domain) {
            return $this->unavailable(null, 'invalid_dkim_domain');
        }

        if (!$this->configuration->isEnabled()) {
            return $this->unavailable($domain, 'integration_disabled');
        }

        $stat = $this->statReader->getLatestForDomain($domain);
        if (!$stat instanceof DomainStat || DomainNormalizer::normalize($stat->getDomain()) !== $domain) {
            return $this->unavailable($domain, 'no_exact_domain_row');
        }

        $now ??= new \DateTimeImmutable();
        $ageSeconds = max(0, $now->getTimestamp() - $stat->getSyncedAt()->getTimestamp());
        $fresh = $stat->getStatDate()->format('Y-m-d') === $now->format('Y-m-d')
            && $ageSeconds <= self::FRESHNESS_MAX_AGE_SECONDS;
        $messagesSent = $stat->getMessagesSent();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => 'mailru_postmaster',
            'scope' => 'aggregate_dkim_domain',
            'available' => true,
            'reason' => null,
            'fresh' => $fresh,
            'freshness_max_age_seconds' => self::FRESHNESS_MAX_AGE_SECONDS,
            'low_sample' => $messagesSent < self::LOW_SAMPLE_THRESHOLD_MESSAGES,
            'low_sample_threshold_messages' => self::LOW_SAMPLE_THRESHOLD_MESSAGES,
            'identity' => [
                'kind' => 'dkim_d_domain',
                'requested_domain' => $domain,
                'matched_domain' => $stat->getDomain(),
                'exact_match' => true,
            ],
            'observed' => [
                'stat_date' => $stat->getStatDate()->format('Y-m-d'),
                'synced_at' => $stat->getSyncedAt()->format(DATE_ATOM),
                'age_seconds' => $ageSeconds,
            ],
            'counts' => [
                'messages_sent' => $messagesSent,
                'delivered_inbox' => $stat->getDelivered(),
                'complaints' => $stat->getComplaints(),
                'spam_rejected' => $stat->getSpam(),
                'probably_spam' => $stat->getProbablySpam(),
            ],
            'rates' => [
                'spam_percent' => $stat->getSpamPercent(),
                'probably_spam_percent' => $stat->getProbablySpamPercent(),
                'reputation_percent_30d' => $stat->getReputation(),
                'trend_percent' => $stat->getTrend(),
            ],
            'units' => [
                'counts' => 'messages',
                'rates' => 'percentage_points',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(?string $requestedDomain, string $reason): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => 'mailru_postmaster',
            'scope' => 'aggregate_dkim_domain',
            'available' => false,
            'reason' => $reason,
            'fresh' => false,
            'freshness_max_age_seconds' => self::FRESHNESS_MAX_AGE_SECONDS,
            'low_sample' => false,
            'low_sample_threshold_messages' => self::LOW_SAMPLE_THRESHOLD_MESSAGES,
            'identity' => [
                'kind' => 'dkim_d_domain',
                'requested_domain' => $requestedDomain,
                'matched_domain' => null,
                'exact_match' => false,
            ],
            'observed' => [
                'stat_date' => null,
                'synced_at' => null,
                'age_seconds' => null,
            ],
            'counts' => [
                'messages_sent' => null,
                'delivered_inbox' => null,
                'complaints' => null,
                'spam_rejected' => null,
                'probably_spam' => null,
            ],
            'rates' => [
                'spam_percent' => null,
                'probably_spam_percent' => null,
                'reputation_percent_30d' => null,
                'trend_percent' => null,
            ],
            'units' => [
                'counts' => 'messages',
                'rates' => 'percentage_points',
            ],
        ];
    }
}
