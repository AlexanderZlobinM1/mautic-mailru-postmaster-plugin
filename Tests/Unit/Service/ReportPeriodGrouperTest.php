<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\ReportPeriodGrouper;
use PHPUnit\Framework\TestCase;

final class ReportPeriodGrouperTest extends TestCase
{
    public function testGroupsNewestFirstAndOpensCurrentMonthOnly(): void
    {
        $years = (new ReportPeriodGrouper())->group([
            ['stat_date' => '2025-12-31', 'messages_sent' => 1],
            ['stat_date' => '2026-07-31', 'messages_sent' => 2],
            ['stat_date' => '2026-08-01', 'messages_sent' => 3],
        ], new \DateTimeImmutable('2026-08-10'));

        self::assertSame([2026, 2025], array_column($years, 'year'));
        self::assertSame(['2026-08', '2026-07'], array_column($years[0]['months'], 'key'));
        self::assertTrue($years[0]['months'][0]['open']);
        self::assertFalse($years[0]['months'][1]['open']);
    }

    public function testAddsEmptyCurrentMonthWhenHistoryHasNoCurrentRows(): void
    {
        $years = (new ReportPeriodGrouper())->group([
            ['stat_date' => '2026-06-01'],
        ], new \DateTimeImmutable('2026-08-10'));

        self::assertSame('2026-08', $years[0]['months'][0]['key']);
        self::assertSame([], $years[0]['months'][0]['rows']);
        self::assertTrue($years[0]['months'][0]['open']);
    }
}
