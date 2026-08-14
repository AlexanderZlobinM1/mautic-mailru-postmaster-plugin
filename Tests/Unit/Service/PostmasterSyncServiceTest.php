<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\PostmasterSyncService;
use PHPUnit\Framework\TestCase;

final class PostmasterSyncServiceTest extends TestCase
{
    public function testRollingWindowUsesSingleApiRequest(): void
    {
        $service = (new \ReflectionClass(PostmasterSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PostmasterSyncService::class, 'splitPeriods');
        $periods = $method->invoke(
            $service,
            new \DateTimeImmutable('2026-07-12'),
            new \DateTimeImmutable('2026-08-10'),
        );

        self::assertCount(1, $periods);
        self::assertSame('2026-07-12', $periods[0][0]->format('Y-m-d'));
        self::assertSame('2026-08-10', $periods[0][1]->format('Y-m-d'));
        foreach ($periods as [$from, $to]) {
            self::assertLessThanOrEqual(29, (int) $from->diff($to)->format('%a'));
        }
    }

    public function testServiceRejectsAnyDeepRead(): void
    {
        $service = (new \ReflectionClass(PostmasterSyncService::class))->newInstanceWithoutConstructor();
        $today = new \DateTimeImmutable('today');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rolling last 30 days');

        $service->sync($today->modify('-30 days'), $today);
    }
}
