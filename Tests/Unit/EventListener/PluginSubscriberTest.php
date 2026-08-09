<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\IntegrationRepository;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\PluginSubscriber;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\MailRuPostmasterIntegration;
use PHPUnit\Framework\TestCase;

final class PluginSubscriberTest extends TestCase
{
    public function testInstallCreatesDisabledIntegrationConfiguration(): void
    {
        $repository = $this->createMock(IntegrationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneByName')
            ->willReturnMap([
                [MailRuPostmasterIntegration::NAME, null],
                ['mailru_postmaster', null],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity): bool {
                self::assertInstanceOf(Integration::class, $entity);
                self::assertSame(MailRuPostmasterIntegration::NAME, $entity->getName());
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

    public function testInstallMigratesLegacyConfigurationWithoutLosingKeys(): void
    {
        $legacy = new Integration();
        $legacy->setName('mailru_postmaster');
        $legacy->setApiKeys(['token_json' => 'encrypted-value']);

        $repository = $this->createMock(IntegrationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneByName')
            ->willReturnMap([
                [MailRuPostmasterIntegration::NAME, null],
                ['mailru_postmaster', $legacy],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with($legacy);

        $plugin = new Plugin();
        $plugin->setBundle('MauticMailRuPostmasterBundle');

        (new PluginSubscriber($repository, $entityManager))->onInstall(
            new PluginInstallEvent($plugin, null, null),
        );

        self::assertSame(MailRuPostmasterIntegration::NAME, $legacy->getName());
        self::assertSame(['token_json' => 'encrypted-value'], $legacy->getApiKeys());
    }
}
