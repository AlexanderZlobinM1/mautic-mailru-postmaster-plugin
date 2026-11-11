<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatReader;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ConditionRuntimePoller;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardService;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\RuntimePollResult;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ThresholdEventLogger;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ThresholdEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GuardServiceConditionTest extends TestCase
{
    /** @var list<string> */
    private array $stateDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->stateDirs as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $name) {
                if ('.' !== $name && '..' !== $name) {
                    unlink($directory.DIRECTORY_SEPARATOR.$name);
                }
            }
            rmdir($directory);
        }
    }

    public function testConditionPollsBeforeChoosingExceededRoute(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-1', 1),
        );
        $reader = new ConditionStatReaderStub($order, $this->stat($now, $now, [
            'messages_sent' => 100,
            'spam_percent' => 1.25,
            'probably_spam_percent' => 0.5,
        ]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'mailru_postmaster.condition_threshold_triggered',
                self::callback(static fn (array $context): bool => 'route_no' === $context['action']
                    && 'generation-1' === $context['poll_generation']
                    && ['spam_percent'] === $context['triggered_metrics']
                    && 1.25 === $context['spam_percent']),
            );

        $safe = $this->service(true, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'MAIL.EXAMPLE.ORG.',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
        ], $now);

        self::assertFalse($safe);
        self::assertSame(['poll', 'read'], $order->calls);
        self::assertSame('mail.example.org', $poller->domain);
        self::assertSame('campaign_condition', $poller->source);
    }

    public function testConditionPollsBeforeFailOpenWhenNoCurrentStatsExist(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-empty', 0),
        );
        $reader = new ConditionStatReaderStub($order, null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertTrue($this->service(true, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'mail.example.org',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
        ], $now));
        self::assertSame(['poll', 'read'], $order->calls);
    }

    public function testDisabledConditionDoesNotPoll(): void
    {
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-1', 1),
        );
        $reader = new ConditionStatReaderStub($order, null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertTrue($this->service(false, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'mail.example.org',
        ]));
        self::assertSame([], $order->calls);
    }

    private function service(
        bool $enabled,
        ConditionRuntimePoller $poller,
        DomainStatReader $reader,
        LoggerInterface $logger,
    ): GuardService {
        $service = (new \ReflectionClass(GuardService::class))->newInstanceWithoutConstructor();
        $configuration = $this->createMock(PostmasterConfiguration::class);
        $configuration->method('isEnabled')->willReturn($enabled);

        foreach ([
            'configuration' => $configuration,
            'runtimePoller' => $poller,
            'statRepository' => $reader,
            'thresholdEvaluator' => new ThresholdEvaluator(),
            'thresholdEventLogger' => new ThresholdEventLogger($logger, $this->stateDir()),
        ] as $property => $value) {
            (new \ReflectionProperty(GuardService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function stateDir(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mailru-postmaster-condition-'.bin2hex(random_bytes(8));
        $this->stateDirs[] = $directory;

        return $directory;
    }

    /** @param array<string, int|float> $data */
    private function stat(\DateTimeImmutable $statDate, \DateTimeImmutable $syncedAt, array $data): DomainStat
    {
        $stat = new DomainStat();
        $stat->update('mail.example.org', $statDate, $data, $syncedAt);

        return $stat;
    }
}

final class ConditionCallOrder
{
    /** @var list<string> */
    public array $calls = [];
}

final class ConditionPollerStub implements ConditionRuntimePoller
{
    public ?string $domain = null;
    public ?string $source = null;

    public function __construct(
        private readonly ConditionCallOrder $order,
        private readonly RuntimePollResult $result,
    ) {
    }

    public function pollDomainForDecision(string $domain, string $source): RuntimePollResult
    {
        $this->order->calls[] = 'poll';
        $this->domain = $domain;
        $this->source = $source;

        return $this->result;
    }
}

final class ConditionStatReaderStub implements DomainStatReader
{
    public function __construct(
        private readonly ConditionCallOrder $order,
        private readonly ?DomainStat $stat,
    ) {
    }

    public function getLatestForDomain(string $domain): ?DomainStat
    {
        $this->order->calls[] = 'read';

        return $this->stat;
    }
}
