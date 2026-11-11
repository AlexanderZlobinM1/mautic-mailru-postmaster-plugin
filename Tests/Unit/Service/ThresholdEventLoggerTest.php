<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\ThresholdEventLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ThresholdEventLoggerTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mailru-postmaster-threshold-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->stateDir)) {
            return;
        }
        foreach (scandir($this->stateDir) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                unlink($this->stateDir.DIRECTORY_SEPARATOR.$name);
            }
        }
        rmdir($this->stateDir);
    }

    public function testOnlyTransitionsIntoBreachedStateAreLogged(): void
    {
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string $message, array $context) use (&$records): void {
                $records[] = [$message, $context];
            },
        );
        $audit = new ThresholdEventLogger($logger, $this->stateDir);
        $breach = [[
            'metric'    => 'probably_spam_percent',
            'actual'    => 0.73,
            'threshold' => 0.5,
        ]];

        $audit->observe('condition', 'campaign-10-event-20', [], []);
        $audit->observe('condition', 'campaign-10-event-20', $breach, ['action' => 'route_no']);
        $audit->observe('condition', 'campaign-10-event-20', [[
            'metric'    => 'probably_spam_percent',
            'actual'    => 0.91,
            'threshold' => 0.5,
        ]], ['action' => 'route_no']);
        $audit->observe('condition', 'campaign-10-event-20', [], []);
        $audit->observe('condition', 'campaign-10-event-20', $breach, ['action' => 'route_no']);

        self::assertCount(2, $records);
        self::assertSame('mailru_postmaster.condition_threshold_triggered', $records[0][0]);
        self::assertSame(['probably_spam_percent'], $records[0][1]['triggered_metrics']);
        self::assertSame('route_no', $records[0][1]['action']);
    }

    public function testChangedBreachedMetricIsLoggedAsANewThresholdChange(): void
    {
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string $message, array $context) use (&$records): void {
                $records[] = [$message, $context];
            },
        );
        $audit = new ThresholdEventLogger($logger, $this->stateDir);

        $audit->observe('guard', 'campaign-10-event-20', [[
            'metric'    => 'probably_spam_percent',
            'actual'    => 0.73,
            'threshold' => 0.5,
        ]], []);
        $audit->observe('guard', 'campaign-10-event-20', [[
            'metric'    => 'spam_percent',
            'actual'    => 0.81,
            'threshold' => 0.5,
        ]], []);

        self::assertCount(2, $records);
        self::assertSame('mailru_postmaster.guard_threshold_triggered', $records[1][0]);
        self::assertSame(['spam_percent'], $records[1][1]['triggered_metrics']);
    }
}
