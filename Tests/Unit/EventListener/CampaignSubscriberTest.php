<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\CampaignSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CampaignSubscriberTest extends TestCase
{
    public function testGuardIsAvailableOnlyBeforeEmailSendAction(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $builder = new CampaignBuilderEvent($translator);
        $subscriber = (new \ReflectionClass(CampaignSubscriber::class))->newInstanceWithoutConstructor();

        $subscriber->onCampaignBuild($builder);

        $actions = $builder->getActions();
        self::assertArrayHasKey(CampaignSubscriber::EVENT_TYPE, $actions);
        self::assertTrue($actions[CampaignSubscriber::EVENT_TYPE]['hideTriggerMode']);
        self::assertSame(
            ['target' => [Event::TYPE_ACTION => ['email.send']]],
            $actions[CampaignSubscriber::EVENT_TYPE]['connectionRestrictions'],
        );
    }

    public function testRuntimeWatcherHooksNormalCampaignTriggerWithoutEmailInterception(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        self::assertSame(['onCampaignTrigger', 0], $events[CampaignEvents::CAMPAIGN_ON_TRIGGER]);
        self::assertCount(3, $events);
    }
}
