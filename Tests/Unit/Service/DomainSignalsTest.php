<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatReader;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\DomainSignals;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\WarmupDomainSignalProvider;
use PHPUnit\Framework\TestCase;

final class DomainSignalsTest extends TestCase
{
    public function testRejectsInvalidDomainWithoutRepositoryLookup(): void
    {
        $reader = new InMemoryDomainStatReader();
        $snapshot = $this->service($reader)->snapshot('not a domain');

        self::assertFalse($snapshot['available']);
        self::assertSame('invalid_dkim_domain', $snapshot['reason']);
        self::assertSame(0, $reader->lookups);
    }

    public function testDisabledIntegrationDoesNotReadStoredStats(): void
    {
        $reader = new InMemoryDomainStatReader();
        $snapshot = $this->service($reader, false)->snapshot('mail.example.org');

        self::assertFalse($snapshot['available']);
        self::assertSame('integration_disabled', $snapshot['reason']);
        self::assertSame('mail.example.org', $snapshot['identity']['requested_domain']);
        self::assertSame(0, $reader->lookups);
    }

    public function testMissingExactDomainRowIsUnavailable(): void
    {
        $reader = new InMemoryDomainStatReader();
        $snapshot = $this->service($reader)->snapshot('mail.example.org');

        self::assertFalse($snapshot['available']);
        self::assertSame('no_exact_domain_row', $snapshot['reason']);
        self::assertFalse($snapshot['identity']['exact_match']);
    }

    public function testExposesFreshExactDomainMetricsAndUnits(): void
    {
        $now = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');
        $stat = $this->stat(
            'mail.example.org',
            $now,
            $now->modify('-7 seconds'),
            [
                'messages_sent' => 321,
                'delivered' => 290,
                'complaints' => 3,
                'spam' => 4,
                'probably_spam' => 24,
                'spam_percent' => 1.25,
                'probably_spam_percent' => 7.5,
                'reputation' => 0.93,
                'trend' => -0.14,
            ],
        );

        $snapshot = $this->service(new InMemoryDomainStatReader($stat))
            ->snapshot('MAIL.EXAMPLE.ORG.', $now);

        self::assertTrue($snapshot['available']);
        self::assertNull($snapshot['reason']);
        self::assertTrue($snapshot['fresh']);
        self::assertFalse($snapshot['low_sample']);
        self::assertSame('aggregate_dkim_domain', $snapshot['scope']);
        self::assertSame('mail.example.org', $snapshot['identity']['requested_domain']);
        self::assertSame('mail.example.org', $snapshot['identity']['matched_domain']);
        self::assertTrue($snapshot['identity']['exact_match']);
        self::assertSame('2026-09-24', $snapshot['observed']['stat_date']);
        self::assertSame(7, $snapshot['observed']['age_seconds']);
        self::assertSame([
            'messages_sent' => 321,
            'delivered_inbox' => 290,
            'complaints' => 3,
            'spam_rejected' => 4,
            'probably_spam' => 24,
        ], $snapshot['counts']);
        self::assertSame([
            'spam_percent' => 1.25,
            'probably_spam_percent' => 7.5,
            'reputation_percent_30d' => 0.93,
            'trend_percent' => -0.14,
        ], $snapshot['rates']);
        self::assertSame(['counts' => 'messages', 'rates' => 'percentage_points'], $snapshot['units']);
    }

    public function testOldObservationIsAvailableButNotFresh(): void
    {
        $now = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');
        $stat = $this->stat('mail.example.org', $now->modify('-1 day'), $now->modify('-10 seconds'), [
            'messages_sent' => 10,
        ]);

        $snapshot = $this->service(new InMemoryDomainStatReader($stat))->snapshot('mail.example.org', $now);

        self::assertTrue($snapshot['available']);
        self::assertFalse($snapshot['fresh']);
        self::assertTrue($snapshot['low_sample']);
    }

    public function testZeroMessagesRemainAvailableAndLowSample(): void
    {
        $now = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');
        $stat = $this->stat('mail.example.org', $now, $now, ['messages_sent' => 0]);

        $snapshot = $this->service(new InMemoryDomainStatReader($stat))->snapshot('mail.example.org', $now);

        self::assertTrue($snapshot['available']);
        self::assertTrue($snapshot['fresh']);
        self::assertTrue($snapshot['low_sample']);
    }

    public function testWarmupProviderReturnsOnlyFreshExactDomainContract(): void
    {
        $now = new \DateTimeImmutable();
        $stat = $this->stat('mail.example.org', $now, $now->modify('-7 seconds'), [
            'messages_sent' => 321,
            'delivered' => 290,
            'complaints' => 3,
            'spam_percent' => 1.25,
            'probably_spam_percent' => 7.5,
        ]);
        $provider = new WarmupDomainSignalProvider(
            $this->service(new InMemoryDomainStatReader($stat)),
        );

        self::assertSame([
            'domain' => 'mail.example.org',
            'statDate' => $now->format('Y-m-d'),
            'syncedAt' => $now->modify('-7 seconds')->format(DATE_ATOM),
            'messagesSent' => 321,
            'delivered' => 290,
            'complaints' => 3,
            'spamPercent' => 1.25,
            'probablySpamPercent' => 7.5,
        ], $provider->snapshot('MAIL.EXAMPLE.ORG.'));
    }

    public function testWarmupProviderReturnsNullWhenSnapshotIsStale(): void
    {
        $now = new \DateTimeImmutable();
        $stat = $this->stat('mail.example.org', $now->modify('-1 day'), $now, [
            'messages_sent' => 321,
        ]);
        $provider = new WarmupDomainSignalProvider(
            $this->service(new InMemoryDomainStatReader($stat)),
        );

        self::assertNull($provider->snapshot('mail.example.org'));
    }

    public function testWarmupProviderReturnsNullWhenIntegrationIsDisabled(): void
    {
        $provider = new WarmupDomainSignalProvider(
            $this->service(new InMemoryDomainStatReader(), false),
        );

        self::assertNull($provider->snapshot('mail.example.org'));
    }

    private function service(InMemoryDomainStatReader $reader, bool $enabled = true): DomainSignals
    {
        $configuration = $this->createMock(PostmasterConfiguration::class);
        $configuration->method('isEnabled')->willReturn($enabled);

        return new DomainSignals($reader, $configuration);
    }

    /**
     * @param array<string, int|float> $data
     */
    private function stat(
        string $domain,
        \DateTimeImmutable $statDate,
        \DateTimeImmutable $syncedAt,
        array $data,
    ): DomainStat {
        $stat = new DomainStat();
        $stat->update($domain, $statDate, $data, $syncedAt);

        return $stat;
    }
}

final class InMemoryDomainStatReader implements DomainStatReader
{
    public int $lookups = 0;

    public function __construct(private readonly ?DomainStat $stat = null)
    {
    }

    public function getLatestForDomain(string $domain): ?DomainStat
    {
        ++$this->lookups;

        return $this->stat;
    }
}
