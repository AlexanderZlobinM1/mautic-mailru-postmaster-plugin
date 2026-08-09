<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

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
        self::assertSame(
            ['target' => [Event::TYPE_ACTION => ['email.send']]],
            $actions[CampaignSubscriber::EVENT_TYPE]['connectionRestrictions'],
        );
    }
}
