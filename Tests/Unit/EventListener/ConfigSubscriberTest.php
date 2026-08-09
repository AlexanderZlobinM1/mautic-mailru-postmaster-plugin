<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\ConfigSubscriber;
use PHPUnit\Framework\TestCase;

final class ConfigSubscriberTest extends TestCase
{
    public function testRegistersDedicatedConfigurationLifecycle(): void
    {
        $events = ConfigSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConfigEvents::CONFIG_ON_GENERATE, $events);
        self::assertArrayHasKey(ConfigEvents::CONFIG_PRE_SAVE, $events);
        self::assertArrayHasKey(ConfigEvents::CONFIG_POST_SAVE, $events);
    }
}
