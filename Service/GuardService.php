<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\Exceptions\CampaignAlreadyUnpublishedException;
use Mautic\CampaignBundle\Model\Exceptions\CampaignVersionMismatchedException;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatReader;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\GuardStop;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\GuardStopRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\CampaignSubscriber;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuardService
{
    private const MAX_HISTORY_DAYS = 30;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GuardDomainResolver $domainResolver,
        private readonly ThresholdEvaluator $thresholdEvaluator,
        private readonly DomainStatReader $statRepository,
        private readonly ConditionRuntimePoller $runtimePoller,
        private readonly GuardStopRepository $stopRepository,
        private readonly CampaignModel $campaignModel,
        private readonly ThresholdEventLogger $thresholdEventLogger,
        private readonly TranslatorInterface $translator,
        private readonly PostmasterConfiguration $configuration,
    ) {
    }

    public function evaluateAll(?\DateTimeImmutable $now = null): int
    {
        if (!$this->configuration->isEnabled()) {
            return 0;
        }

        $stopped = 0;
        $now ??= new \DateTimeImmutable();

        foreach ($this->eventRepository->findBy(['type' => CampaignSubscriber::EVENT_TYPE]) as $event) {
            if (!$this->isActiveMonitor($event, $now)) {
                continue;
            }

            if ($this->evaluate($event, $now, 'scheduled_sync')->stopped) {
                ++$stopped;
            }
        }

        return $stopped;
    }

    /**
     * @return list<string>
     */
    public function getActiveDomains(?\DateTimeImmutable $now = null): array
    {
        if (!$this->configuration->isEnabled()) {
            return [];
        }

        $now ??= new \DateTimeImmutable();
        $domains = [];
        foreach ([CampaignSubscriber::EVENT_TYPE, CampaignSubscriber::CONDITION_TYPE] as $type) {
            foreach ($this->eventRepository->findBy(['type' => $type]) as $event) {
                if (!$this->isActiveMonitor($event, $now)) {
                    continue;
                }
                $domain = $this->domainResolver->resolve($event);
                if (null !== $domain) {
                    $domains[$domain] = true;
                }
            }
        }

        $domains = array_keys($domains);
        sort($domains, SORT_STRING);

        return $domains;
    }

    /**
     * @return list<Event>
     */
    public function getActiveGuardsForCampaign(int $campaignId, ?\DateTimeImmutable $now = null): array
    {
        return array_values(array_filter(
            $this->getActiveMonitorsForCampaign($campaignId, $now),
            static fn (Event $event): bool => CampaignSubscriber::EVENT_TYPE === $event->getType(),
        ));
    }

    /**
     * @return list<Event>
     */
    public function getActiveMonitorsForCampaign(int $campaignId, ?\DateTimeImmutable $now = null): array
    {
        if (!$this->configuration->isEnabled()) {
            return [];
        }

        $now ??= new \DateTimeImmutable();
        $monitors = [];
        foreach ($this->eventRepository->findBy(['campaign' => $campaignId]) as $event) {
            if (!$event instanceof Event) {
                continue;
            }
            if (!in_array($event->getType(), [
                CampaignSubscriber::EVENT_TYPE,
                CampaignSubscriber::CONDITION_TYPE,
            ], true)) {
                continue;
            }

            // A detached watcher is long-lived relative to a normal request;
            // explicitly reload campaign publication state on every pass.
            $this->entityManager->refresh($event);
            $campaign = $event->getCampaign();
            $this->entityManager->refresh($campaign);
            if ($this->isActiveMonitor($event, $now)) {
                $monitors[] = $event;
            }
        }

        return $monitors;
    }

    public function hasActiveGuardForCampaign(int $campaignId): bool
    {
        return [] !== $this->getActiveGuardsForCampaign($campaignId);
    }

    public function hasActiveMonitorForCampaign(int $campaignId): bool
    {
        return [] !== $this->getActiveMonitorsForCampaign($campaignId);
    }

    /**
     * The condition is intentionally fail-open, matching the campaign stopper:
     * missing, old or stale Mail.ru data keeps the normal route open.
     *
     * @param array<string, mixed> $properties
     */
    public function isThresholdSafe(
        array $properties,
        ?\DateTimeImmutable $now = null,
        ?string $monitorIdentity = null,
    ): bool
    {
        if (!$this->configuration->isEnabled()) {
            return true;
        }

        $now ??= new \DateTimeImmutable();
        $domain = DomainNormalizer::normalize((string) ($properties['domain'] ?? ''));
        if (null === $domain) {
            return true;
        }

        $poll = $this->runtimePoller->pollDomainForDecision($domain, 'campaign_condition');
        [$breaches, $breachStats] = $this->findConfiguredBreaches($domain, $properties, $now);
        if ([] === $breaches) {
            $latest = array_values($breachStats)[0] ?? null;
            if ($latest instanceof DomainStat) {
                $this->thresholdEventLogger->observe(
                    'condition',
                    $this->conditionThresholdIdentity($domain, $properties, $monitorIdentity),
                    [],
                    [
                        'action' => 'route_yes',
                        'domain' => $domain,
                        'poll_generation' => $poll->generation,
                        'stat_date' => $latest->getStatDate()->format('Y-m-d'),
                        'stat_synced_at' => $latest->getSyncedAt()->format(DATE_ATOM),
                    ],
                );
            }

            return true;
        }

        $breach = $breaches[0];
        $stat = $breachStats[$breach['stat_date']] ?? null;
        if (!$stat instanceof DomainStat) {
            return true;
        }

        $this->thresholdEventLogger->observe(
            'condition',
            $this->conditionThresholdIdentity($domain, $properties, $monitorIdentity),
            $breaches,
            [
                'action'                  => 'route_no',
                'domain'                  => $domain,
                'poll_generation'         => $poll->generation,
                'stat_date'               => $breach['stat_date'],
                'stat_synced_at'          => $stat->getSyncedAt()->format(DATE_ATOM),
                'messages_sent'           => $stat->getMessagesSent(),
                'probably_spam_percent'   => $stat->getProbablySpamPercent(),
                'probably_spam_threshold' => (float) ($properties['probably_spam_threshold'] ?? 100),
                'spam_percent'            => $stat->getSpamPercent(),
                'spam_threshold'          => (float) ($properties['spam_threshold'] ?? 100),
            ],
        );

        return false;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array{0: list<array{metric: string, actual: float, threshold: float, stat_date: string}>, 1: array<string, DomainStat>}
     */
    private function findConfiguredBreaches(
        string $domain,
        array $properties,
        \DateTimeImmutable $now,
    ): array {
        $probablyHistory = $this->historyDays($properties, 'probably_spam_history_days');
        $spamHistory = $this->historyDays($properties, 'spam_history_days');
        $maxHistory = max($probablyHistory, $spamHistory);
        $today = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $now->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
        $stats = $this->statRepository->getForDomainBetween(
            $domain,
            $today->modify(sprintf('-%d days', $maxHistory)),
            $today,
        );
        $breaches = [];
        $breachStats = [];

        foreach ($stats as $stat) {
            $statDate = $stat->getStatDate()->format('Y-m-d');
            $breachStats[$statDate] = $stat;
            // DomainStat is a DATE in the database.  Normalize test and
            // driver values to calendar midnights as well; a timestamp such
            // as yesterday at 12:00 must still count as one previous day.
            $statDay = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $statDate,
                new \DateTimeZone('UTC'),
            );
            $offset = (int) $statDay->diff($today)->format('%r%a');
            if ($offset < 0 || ($offset === 0 && $stat->getSyncedAt() < $now->modify('-20 minutes'))) {
                continue;
            }

            foreach ($this->thresholdEvaluator->findBreaches($stat, $properties) as $breach) {
                $historyDays = 'probably_spam_percent' === $breach['metric']
                    ? $probablyHistory
                    : $spamHistory;
                if ($offset > $historyDays) {
                    continue;
                }

                $breach['stat_date'] = $statDate;
                $breaches[] = $breach;
            }
        }

        return [$breaches, $breachStats];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function hasHistory(array $properties): bool
    {
        return $this->historyDays($properties, 'probably_spam_history_days') > 0
            || $this->historyDays($properties, 'spam_history_days') > 0;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyDays(array $properties, string $key): int
    {
        return min(self::MAX_HISTORY_DAYS, max(0, (int) ($properties[$key] ?? 0)));
    }

    public function evaluate(
        Event $event,
        ?\DateTimeImmutable $now = null,
        string $source = 'manual',
    ): GuardResult
    {
        if (!$this->configuration->isEnabled()) {
            return GuardResult::pass();
        }

        $now ??= new \DateTimeImmutable();
        $properties = $event->getProperties();
        $emailId    = $this->domainResolver->getLegacyEmailId($event);
        $domain     = $this->domainResolver->resolve($event);

        if (null === $domain) {
            $reason = $this->translator->trans('mailru.postmaster.guard.reason.no_domain');

            return GuardResult::pass($reason);
        }

        $breachStats = [];
        if ($this->hasHistory($properties)) {
            [$breaches, $breachStats] = $this->findConfiguredBreaches($domain, $properties, $now);
            if ([] === $breaches) {
                if ([] === $breachStats) {
                    $reason = $this->translator->trans('mailru.postmaster.guard.reason.no_stats');

                    return GuardResult::pass($reason);
                }

                $this->thresholdEventLogger->observe(
                    'guard',
                    $this->guardThresholdIdentity($event, $domain, $properties),
                    [],
                    [],
                );

                return GuardResult::pass();
            }
        } else {
            $stat = $this->statRepository->getLatestForDomain($domain);
            if (!$stat instanceof DomainStat) {
                $reason = $this->translator->trans('mailru.postmaster.guard.reason.no_stats');

                return GuardResult::pass($reason);
            }

            if ($stat->getStatDate()->format('Y-m-d') !== $now->format('Y-m-d')) {
                $reason = $this->translator->trans('mailru.postmaster.guard.reason.not_today');

                return GuardResult::pass($reason);
            }

            if ($stat->getSyncedAt() < $now->modify('-20 minutes')) {
                $reason = $this->translator->trans('mailru.postmaster.guard.reason.stale');

                return GuardResult::pass($reason);
            }

            $breaches = array_map(
                fn (array $breach): array => $breach + ['stat_date' => $stat->getStatDate()->format('Y-m-d')],
                $this->thresholdEvaluator->findBreaches($stat, $properties),
            );
            $breachStats[$stat->getStatDate()->format('Y-m-d')] = $stat;
        }

        $breach = $breaches[0] ?? null;
        if (null === $breach) {
            $this->thresholdEventLogger->observe(
                'guard',
                $this->guardThresholdIdentity($event, $domain, $properties),
                [],
                [],
            );

            return GuardResult::pass();
        }

        $stat = $breachStats[$breach['stat_date']] ?? null;
        if (!$stat instanceof DomainStat) {
            return GuardResult::pass();
        }

        $campaign = $event->getCampaign();
        $metric = $this->translator->trans('mailru.postmaster.guard.metric.'.$breach['metric']);
        $reason = $this->translator->trans('mailru.postmaster.guard.reason.stopped', [
            '%campaign%'  => $campaign->getName(),
            '%metric%'    => $metric,
            '%domain%'    => $domain,
            '%actual%'    => number_format($breach['actual'], 4, '.', ''),
            '%threshold%' => number_format($breach['threshold'], 4, '.', ''),
        ]);

        try {
            $this->campaignModel->transactionalCampaignUnPublish($campaign);
        } catch (CampaignAlreadyUnpublishedException) {
            $alreadyUnpublished = $this->translator->trans('mailru.postmaster.guard.reason.already_unpublished');

            return GuardResult::pass($alreadyUnpublished);
        } catch (CampaignVersionMismatchedException) {
            $versionChanged = $this->translator->trans('mailru.postmaster.guard.reason.version_changed');

            return GuardResult::pass($versionChanged);
        }

        $this->stopRepository->save(GuardStop::create(
            (int) $campaign->getId(),
            $emailId,
            $domain,
            $breach['metric'],
            $breach['actual'],
            $breach['threshold'],
            $stat->getStatDate(),
            $now,
        ));
        $this->thresholdEventLogger->observe(
            'guard',
            $this->guardThresholdIdentity($event, $domain, $properties),
            $breaches,
            [
                'action'                  => 'campaign_stopped',
                'reason'                  => $reason,
                'campaign_id'             => $campaign->getId(),
                'campaign_name'           => $campaign->getName(),
                'guard_event_id'          => $event->getId(),
                'email_id'                => $emailId > 0 ? $emailId : null,
                'domain'                  => $domain,
                'stat_date'               => $stat->getStatDate()->format('Y-m-d'),
                'stat_synced_at'          => $stat->getSyncedAt()->format(DATE_ATOM),
                'messages_sent'           => $stat->getMessagesSent(),
                'probably_spam_percent'   => $stat->getProbablySpamPercent(),
                'probably_spam_threshold' => (float) ($properties['probably_spam_threshold'] ?? 100),
                'spam_percent'            => $stat->getSpamPercent(),
                'spam_threshold'          => (float) ($properties['spam_threshold'] ?? 100),
            ],
        );

        return GuardResult::stopped($reason);
    }

    /** @param array<string, mixed> $properties */
    private function conditionThresholdIdentity(
        string $domain,
        array $properties,
        ?string $monitorIdentity = null,
    ): string
    {
        return implode('|', [
            $monitorIdentity ?? 'unscoped',
            $domain,
            number_format((float) ($properties['probably_spam_threshold'] ?? 100), 4, '.', ''),
            number_format((float) ($properties['spam_threshold'] ?? 100), 4, '.', ''),
            (string) $this->historyDays($properties, 'probably_spam_history_days'),
            (string) $this->historyDays($properties, 'spam_history_days'),
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function guardThresholdIdentity(Event $event, string $domain, array $properties): string
    {
        return implode('|', [
            (string) ($event->getCampaign()->getId() ?? 0),
            (string) ($event->getId() ?? 0),
            $this->conditionThresholdIdentity($domain, $properties),
        ]);
    }

    private function isActiveMonitor(Event $event, \DateTimeImmutable $now): bool
    {
        if (null !== $event->getDeleted()) {
            return false;
        }

        $campaign = $event->getCampaign();
        if (!$campaign->getIsPublished()) {
            return false;
        }
        $publishUp = $campaign->getPublishUp();
        if ($publishUp instanceof \DateTimeInterface && $publishUp > $now) {
            return false;
        }
        $publishDown = $campaign->getPublishDown();

        return !$publishDown instanceof \DateTimeInterface || $publishDown >= $now;
    }
}
