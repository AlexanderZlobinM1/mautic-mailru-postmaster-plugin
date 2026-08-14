<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Command;

use MauticPlugin\MauticMailRuPostmasterBundle\Command\SyncCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

final class SyncCommandTest extends TestCase
{
    public function testCurrentMonthUsesRollingThirtyDayBoundary(): void
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
        self::assertSame($today->modify('-29 days')->format('Y-m-d'), $from->format('Y-m-d'));
        self::assertSame($today->format('Y-m-d'), $to->format('Y-m-d'));
    }

    public function testDeprecatedFullAliasCannotReadPastRollingWindow(): void
    {
        $input = $this->input([
            'full' => true,
            'scheduled-full' => false,
            'force-rescan' => false,
            'current-month' => false,
        ]);
        $command = (new \ReflectionClass(SyncCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SyncCommand::class, 'resolveDates');

        [$from, $to] = $method->invoke($command, $input);

        self::assertSame(29, (int) $from->diff($to)->format('%a'));
    }

    public function testExplicitOldRangeIsRejected(): void
    {
        $input = $this->input([
            'full' => false,
            'scheduled-full' => false,
            'force-rescan' => false,
            'current-month' => false,
            'date-from' => (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
            'date-to' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        $command = (new \ReflectionClass(SyncCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SyncCommand::class, 'resolveDates');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rolling last 30 days');

        $method->invoke($command, $input);
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
