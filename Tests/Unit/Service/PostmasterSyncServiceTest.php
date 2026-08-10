<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\PostmasterSyncService;
use PHPUnit\Framework\TestCase;

final class PostmasterSyncServiceTest extends TestCase
{
    public function testYearBackfillIsSplitIntoApiSafeThirtyDayWindows(): void
    {
        $service = (new \ReflectionClass(PostmasterSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PostmasterSyncService::class, 'splitPeriods');
        $periods = $method->invoke(
            $service,
            new \DateTimeImmutable('2025-08-11'),
            new \DateTimeImmutable('2026-08-10'),
        );

        self::assertCount(13, $periods);
        self::assertSame('2025-08-11', $periods[0][0]->format('Y-m-d'));
        self::assertSame('2026-08-10', $periods[12][1]->format('Y-m-d'));
        foreach ($periods as [$from, $to]) {
            self::assertLessThanOrEqual(29, (int) $from->diff($to)->format('%a'));
        }
    }

    public function testBackfillIsDividedIntoCalendarMonthsBeforeApiWindows(): void
    {
        $service = (new \ReflectionClass(PostmasterSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PostmasterSyncService::class, 'splitMonths');
        $months = $method->invoke(
            $service,
            new \DateTimeImmutable('2026-06-14'),
            new \DateTimeImmutable('2026-08-10'),
        );

        self::assertSame([
            ['2026-06-14', '2026-06-30'],
            ['2026-07-01', '2026-07-31'],
            ['2026-08-01', '2026-08-10'],
        ], array_map(
            static fn (array $period): array => [$period[0]->format('Y-m-d'), $period[1]->format('Y-m-d')],
            $months,
        ));
    }
}
