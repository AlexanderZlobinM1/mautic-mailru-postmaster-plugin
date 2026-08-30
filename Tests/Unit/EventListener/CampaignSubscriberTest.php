<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\CampaignSubscriber;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CampaignSubscriberTest extends TestCase
{
    public function testGuardIsAvailableOnlyBeforeEmailSendAction(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $builder = new CampaignBuilderEvent($translator);
        $subscriber = $this->subscriber(true);

        $subscriber->onCampaignBuild($builder);

        $actions = $builder->getActions();
        self::assertArrayHasKey(CampaignSubscriber::EVENT_TYPE, $actions);
        self::assertTrue($actions[CampaignSubscriber::EVENT_TYPE]['hideTriggerMode']);
        self::assertSame(
            ['target' => [Event::TYPE_ACTION => ['email.send']]],
            $actions[CampaignSubscriber::EVENT_TYPE]['connectionRestrictions'],
        );
    }

    public function testThresholdRouterIsRegisteredAsImmediateCondition(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $builder = new CampaignBuilderEvent($translator);
        $subscriber = $this->subscriber(true);

        $subscriber->onCampaignBuild($builder);

        $conditions = $builder->getConditions();
        self::assertArrayHasKey(CampaignSubscriber::CONDITION_TYPE, $conditions);
        self::assertSame(
            CampaignSubscriber::CONDITION_EXECUTE_EVENT,
            $conditions[CampaignSubscriber::CONDITION_TYPE]['eventName'],
        );
        self::assertSame(
            ['mode' => 'condition'],
            $conditions[CampaignSubscriber::CONDITION_TYPE]['formTypeOptions'],
        );
        self::assertSame(
            '@MauticMailRuPostmaster/Campaign/condition.html.twig',
            $conditions[CampaignSubscriber::CONDITION_TYPE]['template'],
        );
        self::assertTrue($conditions[CampaignSubscriber::CONDITION_TYPE]['hideTriggerMode']);
    }

    public function testRuntimeWatcherHooksNormalCampaignTriggerWithoutEmailInterception(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        self::assertSame(['onCampaignTrigger', 0], $events[CampaignEvents::CAMPAIGN_ON_TRIGGER]);
        self::assertSame(['onConditionExecute', 0], $events[CampaignSubscriber::CONDITION_EXECUTE_EVENT]);
        self::assertCount(4, $events);
    }

    public function testDisabledIntegrationAddsNoCampaignControls(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $builder = new CampaignBuilderEvent($translator);

        $this->subscriber(false)->onCampaignBuild($builder);

        self::assertArrayNotHasKey(CampaignSubscriber::EVENT_TYPE, $builder->getActions());
        self::assertArrayNotHasKey(CampaignSubscriber::CONDITION_TYPE, $builder->getConditions());
    }

    private function subscriber(bool $enabled): CampaignSubscriber
    {
        $subscriber = (new \ReflectionClass(CampaignSubscriber::class))->newInstanceWithoutConstructor();
        $configuration = $this->createMock(PostmasterConfiguration::class);
        $configuration->method('isEnabled')->willReturn($enabled);
        $property = new \ReflectionProperty(CampaignSubscriber::class, 'configuration');
        $property->setValue($subscriber, $configuration);

        return $subscriber;
    }
}
