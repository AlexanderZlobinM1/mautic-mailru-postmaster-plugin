<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\CampaignGuardType;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CampaignSubscriber implements EventSubscriberInterface
{
    public const EVENT_TYPE    = 'mailru.postmaster.guard';
    public const EXECUTE_EVENT = 'mailru.postmaster.guard.execute';

    public function __construct(private readonly GuardService $guardService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD  => ['onCampaignBuild', 0],
            self::EXECUTE_EVENT                => ['onExecute', 0],
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
            'connectionRestrictions' => [
                'target' => [
                    Event::TYPE_ACTION => ['email.send'],
                ],
            ],
        ]);
    }

    public function onExecute(PendingEvent $pendingEvent): void
    {
        $result = $this->guardService->evaluate($pendingEvent->getEvent());
        if ($result->stopped) {
            $pendingEvent->failAll($result->reason);

            return;
        }

        $pendingEvent->passAll();
    }
}
