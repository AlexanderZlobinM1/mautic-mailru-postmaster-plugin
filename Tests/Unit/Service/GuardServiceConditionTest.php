<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatReader;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\GuardStopRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardDomainResolver;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
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

    public function testConditionUsesConfiguredPreviousDayForEachMetric(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-history', 1),
        );
        $reader = new ConditionStatReaderStub($order, null, [
            $this->stat($now, $now, [
                'messages_sent' => 100,
                'spam_percent' => 0.0,
                'probably_spam_percent' => 1.5,
            ]),
            $this->stat($now->modify('-1 day'), $now->modify('-1 day'), [
                'messages_sent' => 100,
                'spam_percent' => 0.0,
                'probably_spam_percent' => 2.0,
            ]),
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'mailru_postmaster.condition_threshold_triggered',
            self::callback(static fn (array $context): bool => '2026-11-10' === $context['stat_date']
                && ['probably_spam_percent'] === $context['triggered_metrics']),
        );

        self::assertFalse($this->service(true, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'mail.example.org',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
            'spam_history_days' => 0,
            'probably_spam_history_days' => 1,
        ], $now));
    }

    public function testMissingHistoryDayAndIndependentMetricDepthAreIgnored(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-history', 1),
        );
        $reader = new ConditionStatReaderStub($order, null, [
            $this->stat($now, $now, [
                'messages_sent' => 100,
                'spam_percent' => 0.0,
                'probably_spam_percent' => 0.0,
            ]),
            $this->stat($now->modify('-1 day'), $now->modify('-1 day'), [
                'messages_sent' => 100,
                'spam_percent' => 2.0,
                'probably_spam_percent' => 0.0,
            ]),
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertTrue($this->service(true, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'mail.example.org',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
            'spam_history_days' => 0,
            'probably_spam_history_days' => 1,
        ], $now));
    }

    public function testAnAbsentHistoricalDayIsNotSynthesizedAsAZeroOrBreach(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $poller = new ConditionPollerStub(
            $order,
            new RuntimePollResult('mail.example.org', true, true, 'generation-missing-day', 1),
        );
        $reader = new ConditionStatReaderStub($order, $this->stat($now, $now, [
            'messages_sent' => 100,
            'spam_percent' => 0.0,
            'probably_spam_percent' => 0.0,
        ]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertTrue($this->service(true, $poller, $reader, $logger)->isThresholdSafe([
            'domain' => 'mail.example.org',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
            'spam_history_days' => 3,
            'probably_spam_history_days' => 3,
        ], $now));
    }

    public function testStopperUsesTheSameHistoricalRowsAsTheCondition(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00+01:00');
        $order = new ConditionCallOrder();
        $reader = new ConditionStatReaderStub($order, null, [
            $this->stat($now, $now, [
                'messages_sent' => 100,
                'spam_percent' => 0.0,
                'probably_spam_percent' => 0.0,
            ]),
            $this->stat($now->modify('-1 day'), $now->modify('-1 day'), [
                'messages_sent' => 100,
                'spam_percent' => 0.0,
                'probably_spam_percent' => 2.0,
            ]),
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $stopRepository = (new \ReflectionClass(GuardStopRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(CommonRepository::class, '_em'))->setValue($stopRepository, $entityManager);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(42);
        $campaign->method('getName')->willReturn('History campaign');
        $campaignModel = $this->createMock(CampaignModel::class);
        $campaignModel->expects(self::once())->method('transactionalCampaignUnPublish')->with($campaign);
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(7);
        $event->method('getCampaign')->willReturn($campaign);
        $event->method('getProperties')->willReturn([
            'domain' => 'mail.example.org',
            'spam_threshold' => 1.0,
            'probably_spam_threshold' => 1.0,
            'spam_history_days' => 0,
            'probably_spam_history_days' => 1,
        ]);

        $result = $this->stopperService($reader, $logger, $entityManager, $stopRepository, $campaignModel)
            ->evaluate($event, $now, 'test');

        self::assertTrue($result->stopped);
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

    private function stopperService(
        DomainStatReader $reader,
        LoggerInterface $logger,
        EntityManagerInterface $entityManager,
        GuardStopRepository $stopRepository,
        CampaignModel $campaignModel,
    ): GuardService {
        $service = (new \ReflectionClass(GuardService::class))->newInstanceWithoutConstructor();
        $configuration = $this->createMock(PostmasterConfiguration::class);
        $configuration->method('isEnabled')->willReturn(true);
        $parameters = $this->createMock(CoreParametersHelper::class);
        $domainProvider = new EmailDomainProvider($this->createMock(Connection::class), $parameters);
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        $runtimePoller = $this->createMock(ConditionRuntimePoller::class);
        $eventRepository = $this->createMock(EventRepository::class);

        foreach ([
            'eventRepository' => $eventRepository,
            'entityManager' => $entityManager,
            'domainResolver' => new GuardDomainResolver($domainProvider),
            'thresholdEvaluator' => new ThresholdEvaluator(),
            'statRepository' => $reader,
            'runtimePoller' => $runtimePoller,
            'stopRepository' => $stopRepository,
            'campaignModel' => $campaignModel,
            'thresholdEventLogger' => new ThresholdEventLogger($logger, $this->stateDir()),
            'translator' => $translator,
            'configuration' => $configuration,
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
    /** @param list<DomainStat> $stats */
    public function __construct(
        private readonly ConditionCallOrder $order,
        private readonly ?DomainStat $stat,
        private readonly array $stats = [],
    ) {
    }

    public function getLatestForDomain(string $domain): ?DomainStat
    {
        $this->order->calls[] = 'read';

        return $this->stat;
    }

    public function getForDomainBetween(
        string $domain,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $this->order->calls[] = 'read';

        $stats = [] !== $this->stats
            ? $this->stats
            : ($this->stat instanceof DomainStat ? [$this->stat] : []);

        return array_values(array_filter(
            $stats,
            static fn (DomainStat $stat): bool => $stat->getStatDate()->format('Y-m-d') >= $dateFrom->format('Y-m-d')
                && $stat->getStatDate()->format('Y-m-d') <= $dateTo->format('Y-m-d'),
        ));
    }
}
