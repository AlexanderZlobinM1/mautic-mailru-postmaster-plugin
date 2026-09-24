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
     * @return array{
     *     domain: string,
     *     statDate: string,
     *     syncedAt: string,
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
        if (!$snapshot['available'] || !$snapshot['fresh']) {
            return null;
        }

        return [
            'domain' => $snapshot['identity']['matched_domain'],
            'statDate' => $snapshot['observed']['stat_date'],
            'syncedAt' => $snapshot['observed']['synced_at'],
            'messagesSent' => $snapshot['counts']['messages_sent'],
            'delivered' => $snapshot['counts']['delivered_inbox'],
            'complaints' => $snapshot['counts']['complaints'],
            'spamPercent' => $snapshot['rates']['spam_percent'],
            'probablySpamPercent' => $snapshot['rates']['probably_spam_percent'],
        ];
    }
}
