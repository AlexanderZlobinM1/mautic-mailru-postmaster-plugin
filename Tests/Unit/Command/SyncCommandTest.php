<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Command;

use MauticPlugin\MauticMailRuPostmasterBundle\Command\SyncCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

final class SyncCommandTest extends TestCase
{
    public function testCurrentMonthUsesCalendarBoundary(): void
    {
        $input = $this->input([
            'full' => false,
            'scheduled-full' => false,
            'current-month' => true,
            'date-to' => '2026-08-10',
            'date-from' => null,
        ]);
        $command = (new \ReflectionClass(SyncCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SyncCommand::class, 'resolveDates');

        [$from, $to] = $method->invoke($command, $input);

        $today = new \DateTimeImmutable('today');
        self::assertSame($today->modify('first day of this month')->format('Y-m-d'), $from->format('Y-m-d'));
        self::assertSame($today->format('Y-m-d'), $to->format('Y-m-d'));
    }

    public function testMccScheduleOverrideMatchesSundayMinute(): void
    {
        $input = $this->input([
            'schedule-weekday' => '0',
            'schedule-time' => '03:00',
        ]);
        $command = (new \ReflectionClass(SyncCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SyncCommand::class, 'isScheduledFullDue');

        self::assertTrue($method->invoke($command, $input, new \DateTimeImmutable('2026-08-09 03:00:30')));
        self::assertFalse($method->invoke($command, $input, new \DateTimeImmutable('2026-08-10 03:00:30')));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function input(array $options): InputInterface
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn (string $name): mixed => $options[$name] ?? null,
        );

        return $input;
    }
}
