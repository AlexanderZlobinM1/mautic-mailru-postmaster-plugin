<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\ReportRowFiller;
use PHPUnit\Framework\TestCase;

final class ReportRowFillerTest extends TestCase
{
    public function testIncludesEveryHistoricalDomainAndZeroFillsMissingStatistics(): void
    {
        $rows = (new ReportRowFiller())->fillLatestRows(
            ['used-without-stats.example', 'used-with-stats.example'],
            [[
                'domain'        => 'used-with-stats.example',
                'stat_date'     => '2026-08-09',
                'messages_sent' => 17,
            ]],
            new \DateTimeImmutable('2026-08-10'),
        );

        self::assertSame(['used-with-stats.example', 'used-without-stats.example'], array_column($rows, 'domain'));
        self::assertSame(17, $rows[0]['messages_sent']);
        self::assertTrue($rows[0]['has_statistics']);
        self::assertSame('2026-08-10', $rows[1]['stat_date']);
        self::assertSame(0, $rows[1]['messages_sent']);
        self::assertSame(0, $rows[1]['complaints']);
        self::assertSame(0.0, $rows[1]['reputation']);
        self::assertSame(0.0, $rows[1]['trend']);
        self::assertSame(0.0, $rows[1]['spam_percent']);
        self::assertSame(0.0, $rows[1]['probably_spam_percent']);
        self::assertFalse($rows[1]['has_statistics']);
        self::assertArrayNotHasKey('delivery_status', $rows[1]);
    }

    public function testReportRowsExposeMetricsWithoutASeparateSendingStatus(): void
    {
        $rows = (new ReportRowFiller())->fillLatestRows(
            ['allowed.example', 'blocked.example'],
            [
                [
                    'domain'                => 'allowed.example',
                    'spam_percent'          => 2.99,
                    'probably_spam_percent' => 40.0,
                ],
                [
                    'domain'                => 'blocked.example',
                    'spam_percent'          => 3.0,
                    'probably_spam_percent' => 0.0,
                ],
            ],
        );

        self::assertArrayNotHasKey('delivery_status', $rows[0]);
        self::assertArrayNotHasKey('delivery_status', $rows[1]);
    }

    public function testDomainWithoutStatisticsGetsOneZeroRowForToday(): void
    {
        $rows = (new ReportRowFiller())->fillDomainRows(
            'used.example',
            [],
            new \DateTimeImmutable('2026-08-10'),
        );

        self::assertCount(1, $rows);
        self::assertSame('used.example', $rows[0]['domain']);
        self::assertSame('2026-08-10', $rows[0]['stat_date']);
        self::assertSame(0, $rows[0]['messages_sent']);
        self::assertFalse($rows[0]['has_statistics']);
    }
}
