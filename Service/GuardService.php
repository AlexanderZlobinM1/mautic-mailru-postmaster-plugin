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

final class GuardService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EmailDomainProvider $emailDomainProvider,
        private readonly DomainStatRepository $statRepository,
        private readonly GuardStopRepository $stopRepository,
        private readonly CampaignModel $campaignModel,
        private readonly LoggerInterface $logger,
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
            return GuardResult::pass('The selected email has no valid explicit From address.');
        }

        $stat = $this->statRepository->getLatestForDomain($domain);
        if (!$stat instanceof DomainStat) {
            return GuardResult::pass('No stored Mail.ru Postmaster statistics for the sender domain.');
        }

        if ($stat->getStatDate()->format('Y-m-d') !== $now->format('Y-m-d')) {
            return GuardResult::pass('The latest Mail.ru Postmaster statistic is not from today.');
        }

        if ($stat->getSyncedAt() < $now->modify('-20 minutes')) {
            return GuardResult::pass('The latest Mail.ru Postmaster statistic is stale.');
        }

        $breach = $this->findBreach($stat, $properties);
        if (null === $breach) {
            return GuardResult::pass();
        }

        $campaign = $event->getCampaign();
        $reason   = sprintf(
            'Mail.ru Postmaster stopped campaign "%s": %s for %s is %.4f%%, above %.4f%%.',
            $campaign->getName(),
            $breach['metric'],
            $domain,
            $breach['actual'],
            $breach['threshold'],
        );

        try {
            $this->campaignModel->transactionalCampaignUnPublish($campaign);
        } catch (CampaignAlreadyUnpublishedException) {
            return GuardResult::pass('The campaign is already unpublished.');
        } catch (CampaignVersionMismatchedException $exception) {
            $this->logger->warning($reason.' Campaign version changed concurrently.', ['exception' => $exception]);

            return GuardResult::pass('Campaign version changed concurrently; retry on the next synchronization.');
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
