<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\Exceptions\CampaignAlreadyUnpublishedException;
use Mautic\CampaignBundle\Model\Exceptions\CampaignVersionMismatchedException;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\GuardStop;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\GuardStopRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\CampaignSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuardService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EmailDomainProvider $emailDomainProvider,
        private readonly DomainStatRepository $statRepository,
        private readonly GuardStopRepository $stopRepository,
        private readonly CampaignModel $campaignModel,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function evaluateAll(?\DateTimeImmutable $now = null): int
    {
        $stopped = 0;
        $now ??= new \DateTimeImmutable();

        foreach ($this->eventRepository->findBy(['type' => CampaignSubscriber::EVENT_TYPE]) as $event) {
            if (!$this->isActiveGuard($event, $now)) {
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
        $now ??= new \DateTimeImmutable();
        $domains = [];
        foreach ($this->eventRepository->findBy(['type' => CampaignSubscriber::EVENT_TYPE]) as $event) {
            if (!$this->isActiveGuard($event, $now)) {
                continue;
            }
            $properties = $event->getProperties();
            $domain = $this->emailDomainProvider->getDomainForEmail((int) ($properties['email'] ?? 0));
            if (null !== $domain) {
                $domains[$domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains, SORT_STRING);

        return $domains;
    }

    public function evaluate(
        Event $event,
        ?\DateTimeImmutable $now = null,
        string $source = 'manual',
    ): GuardResult
    {
        $now ??= new \DateTimeImmutable();
        $properties = $event->getProperties();
        $emailId    = (int) ($properties['email'] ?? 0);
        $domain     = $this->emailDomainProvider->getDomainForEmail($emailId);

        if (null === $domain) {
            $reason = $this->translator->trans('mailru.postmaster.guard.reason.no_email_domain');

            return $this->auditResult($event, $source, $now, $emailId, null, $properties, null, 'pass_no_email_domain', $reason);
        }

        $stat = $this->statRepository->getLatestForDomain($domain);
        if (!$stat instanceof DomainStat) {
            $reason = $this->translator->trans('mailru.postmaster.guard.reason.no_stats');

            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, null, 'pass_no_stats', $reason);
        }

        if ($stat->getStatDate()->format('Y-m-d') !== $now->format('Y-m-d')) {
            $reason = $this->translator->trans('mailru.postmaster.guard.reason.not_today');

            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'pass_not_today', $reason);
        }

        if ($stat->getSyncedAt() < $now->modify('-20 minutes')) {
            $reason = $this->translator->trans('mailru.postmaster.guard.reason.stale');

            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'pass_stale', $reason);
        }

        $breach = $this->findBreach($stat, $properties);
        if (null === $breach) {
            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'pass_below_threshold');
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

            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'pass_already_unpublished', $alreadyUnpublished);
        } catch (CampaignVersionMismatchedException $exception) {
            $this->logger->warning(
                $reason.' '.$this->translator->trans('mailru.postmaster.guard.reason.version_changed_log'),
                ['exception' => $exception],
            );

            $versionChanged = $this->translator->trans('mailru.postmaster.guard.reason.version_changed');

            return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'pass_version_changed', $versionChanged);
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
        $this->logger->warning($reason);

        return $this->auditResult($event, $source, $now, $emailId, $domain, $properties, $stat, 'stopped', $reason, true);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function auditResult(
        Event $event,
        string $source,
        \DateTimeImmutable $now,
        int $emailId,
        ?string $domain,
        array $properties,
        ?DomainStat $stat,
        string $decision,
        string $reason = '',
        bool $stopped = false,
    ): GuardResult
    {
        $campaign = $event->getCampaign();
        $syncedAt = $stat?->getSyncedAt();
        $this->logger->warning('mailru_postmaster.guard_evaluation', [
            'source'                       => $source,
            'decision'                     => $decision,
            'reason'                       => $reason,
            'evaluated_at'                 => $now->format(DATE_ATOM),
            'campaign_id'                  => $campaign->getId(),
            'campaign_name'                => $campaign->getName(),
            'guard_event_id'               => $event->getId(),
            'email_id'                     => $emailId,
            'domain'                       => $domain,
            'stat_date'                    => $stat?->getStatDate()->format('Y-m-d'),
            'stat_synced_at'               => $syncedAt?->format(DATE_ATOM),
            'stat_age_seconds'             => $syncedAt instanceof \DateTimeImmutable
                ? max(0, $now->getTimestamp() - $syncedAt->getTimestamp())
                : null,
            'messages_sent'                => $stat?->getMessagesSent(),
            'probably_spam_percent'        => $stat?->getProbablySpamPercent(),
            'probably_spam_threshold'      => (float) ($properties['probably_spam_threshold'] ?? 100),
            'spam_percent'                 => $stat?->getSpamPercent(),
            'spam_threshold'               => (float) ($properties['spam_threshold'] ?? 100),
        ]);

        return $stopped ? GuardResult::stopped($reason) : GuardResult::pass($reason);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array{metric: string, actual: float, threshold: float}|null
     */
    private function findBreach(DomainStat $stat, array $properties): ?array
    {
        $checks = [
            [
                'metric'    => 'spam_percent',
                'actual'    => $stat->getSpamPercent(),
                'threshold' => (float) ($properties['spam_threshold'] ?? 100),
            ],
            [
                'metric'    => 'probably_spam_percent',
                'actual'    => $stat->getProbablySpamPercent(),
                'threshold' => (float) ($properties['probably_spam_threshold'] ?? 100),
            ],
        ];

        foreach ($checks as $check) {
            if ($check['actual'] > $check['threshold']) {
                return $check;
            }
        }

        return null;
    }

    private function isActiveGuard(Event $event, \DateTimeImmutable $now): bool
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
