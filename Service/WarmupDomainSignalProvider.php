<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

/**
 * Public read-only contract consumed optionally by WarmupMailBundle.
 *
 * The provider reads only already-persisted Postmaster data. It never starts
 * an OAuth flow or a Mail.ru API request.
 */
final class WarmupDomainSignalProvider
{
    public function __construct(private readonly DomainSignals $domainSignals)
    {
    }

    /**
     * Return the latest persisted snapshot for the exact DKIM domain.
     *
     * A stale snapshot is intentionally still returned so a sibling plugin
     * can display the last known values. Consumers must gate decisions on
     * `fresh === true`; stale data must never authorize or block sending.
     *
     * @return array{
     *     domain: string,
     *     statDate: string,
     *     syncedAt: string,
     *     ageSeconds: int,
     *     fresh: bool,
     *     status: 'fresh'|'stale',
     *     freshnessMaxAgeSeconds: int,
     *     messagesSent: int,
     *     delivered: int,
     *     complaints: int,
     *     spamPercent: float,
     *     probablySpamPercent: float
     * }|null
     */
    public function snapshot(string $dkimDomain): ?array
    {
        $snapshot = $this->domainSignals->snapshot($dkimDomain);
        if (!$snapshot['available']) {
            return null;
        }

        $fresh = (bool) $snapshot['fresh'];

        return [
            'domain' => $snapshot['identity']['matched_domain'],
            'statDate' => $snapshot['observed']['stat_date'],
            'syncedAt' => $snapshot['observed']['synced_at'],
            'ageSeconds' => $snapshot['observed']['age_seconds'],
            'fresh' => $fresh,
            'status' => $fresh ? 'fresh' : 'stale',
            'freshnessMaxAgeSeconds' => $snapshot['freshness_max_age_seconds'],
            'messagesSent' => $snapshot['counts']['messages_sent'],
            'delivered' => $snapshot['counts']['delivered_inbox'],
            'complaints' => $snapshot['counts']['complaints'],
            'spamPercent' => $snapshot['rates']['spam_percent'],
            'probablySpamPercent' => $snapshot['rates']['probably_spam_percent'],
        ];
    }
}
