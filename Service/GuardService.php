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

        foreach ($this->eventRepository->findBy(['eventType' => CampaignSubscriber::EVENT_TYPE]) as $event) {
            if (!$this->isActiveGuard($event, $now)) {
                continue;
            }

            if ($this->evaluate($event, $now)->stopped) {
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
        foreach ($this->eventRepository->findBy(['eventType' => CampaignSubscriber::EVENT_TYPE]) as $event) {
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

    public function evaluate(Event $event, ?\DateTimeImmutable $now = null): GuardResult
    {
        $now ??= new \DateTimeImmutable();
        $properties = $event->getProperties();
        $emailId    = (int) ($properties['email'] ?? 0);
        $domain     = $this->emailDomainProvider->getDomainForEmail($emailId);

        if (null === $domain) {
            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.no_email_domain'));
        }

        $stat = $this->statRepository->getLatestForDomain($domain);
        if (!$stat instanceof DomainStat) {
            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.no_stats'));
        }

        if ($stat->getStatDate()->format('Y-m-d') !== $now->format('Y-m-d')) {
            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.not_today'));
        }

        if ($stat->getSyncedAt() < $now->modify('-20 minutes')) {
            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.stale'));
        }

        $breach = $this->findBreach($stat, $properties);
        if (null === $breach) {
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
            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.already_unpublished'));
        } catch (CampaignVersionMismatchedException $exception) {
            $this->logger->warning(
                $reason.' '.$this->translator->trans('mailru.postmaster.guard.reason.version_changed_log'),
                ['exception' => $exception],
            );

            return GuardResult::pass($this->translator->trans('mailru.postmaster.guard.reason.version_changed'));
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

        return GuardResult::stopped($reason);
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
