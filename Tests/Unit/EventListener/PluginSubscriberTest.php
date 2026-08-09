<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\IntegrationRepository;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\PluginSubscriber;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterIntegration;
use PHPUnit\Framework\TestCase;

final class PluginSubscriberTest extends TestCase
{
    public function testInstallCreatesDisabledIntegrationConfiguration(): void
    {
        $repository = $this->createMock(IntegrationRepository::class);
        $repository->expects(self::once())
            ->method('findOneByName')
            ->with(PostmasterIntegration::NAME)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity): bool {
                self::assertInstanceOf(Integration::class, $entity);
                self::assertSame(PostmasterIntegration::NAME, $entity->getName());
                self::assertFalse($entity->getIsPublished());
                self::assertSame([], $entity->getApiKeys());

                return true;
            }));

        $plugin = new Plugin();
        $plugin->setBundle('MauticMailRuPostmasterBundle');

        (new PluginSubscriber($repository, $entityManager))->onInstall(
            new PluginInstallEvent($plugin, null, null),
        );
    }
}
