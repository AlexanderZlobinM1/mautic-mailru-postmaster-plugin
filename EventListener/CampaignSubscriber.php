<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Event\CampaignTriggerEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\CampaignGuardType;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardService;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardWatcherLauncher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CampaignSubscriber implements EventSubscriberInterface
{
    public const EVENT_TYPE              = 'mailru.postmaster.guard';
    public const EXECUTE_EVENT           = 'mailru.postmaster.guard.execute';
    public const CONDITION_TYPE          = 'mailru.postmaster.condition';
    public const CONDITION_EXECUTE_EVENT = 'mailru.postmaster.condition.execute';

    public function __construct(
        private readonly GuardService $guardService,
        private readonly GuardWatcherLauncher $watcherLauncher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD   => ['onCampaignBuild', 0],
            CampaignEvents::CAMPAIGN_ON_TRIGGER => ['onCampaignTrigger', 0],
            self::EXECUTE_EVENT                 => ['onExecute', 0],
            self::CONDITION_EXECUTE_EVENT       => ['onConditionExecute', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(self::EVENT_TYPE, [
            'label'                  => 'mailru.postmaster.guard.label',
            'description'            => 'mailru.postmaster.guard.description',
            'formType'               => CampaignGuardType::class,
            'batchEventName'         => self::EXECUTE_EVENT,
            'template'               => '@MauticMailRuPostmaster/Campaign/guard.html.twig',
            'hideTriggerMode'         => true,
            'connectionRestrictions' => [
                'target' => [
                    Event::TYPE_ACTION => ['email.send'],
                ],
            ],
        ]);

        $event->addCondition(self::CONDITION_TYPE, [
            'label'           => 'mailru.postmaster.condition.label',
            'description'     => 'mailru.postmaster.condition.description',
            'formType'        => CampaignGuardType::class,
            'formTypeOptions' => ['mode' => 'condition'],
            'eventName'       => self::CONDITION_EXECUTE_EVENT,
            'template'        => '@MauticMailRuPostmaster/Campaign/condition.html.twig',
            'hideTriggerMode' => true,
        ]);
    }

    public function onExecute(PendingEvent $pendingEvent): void
    {
        // This is deliberately a database-only check. The API watcher runs in
        // a detached plugin process so campaign throughput never waits for it.
        $result = $this->guardService->evaluate($pendingEvent->getEvent(), source: 'campaign_execution');
        if ($result->stopped) {
            $pendingEvent->failAll($result->reason);

            return;
        }

        $pendingEvent->passAll();
    }

    public function onConditionExecute(CampaignExecutionEvent $event): void
    {
        if (!$event->checkContext(self::CONDITION_TYPE)) {
            return;
        }

        // Conditions route contacts from the already stored current-day
        // snapshot. API polling remains detached from campaign throughput.
        $event->setResult($this->guardService->isThresholdSafe($event->getConfig()));
    }

    public function onCampaignTrigger(CampaignTriggerEvent $event): void
    {
        $campaignId = $event->getCampaign()->getId();
        if (null === $campaignId) {
            return;
        }

        $this->watcherLauncher->launch((int) $campaignId);
    }
}
