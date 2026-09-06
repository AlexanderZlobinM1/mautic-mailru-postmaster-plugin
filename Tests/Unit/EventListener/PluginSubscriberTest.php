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
        $plugin = new Plugin();
        $plugin->setBundle('MauticMailRuPostmasterBundle');

        $repository = $this->createMock(IntegrationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneByName')
            ->willReturnMap([
                [MailRuPostmasterIntegration::NAME, null],
                ['mailru_postmaster', null],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static function (object $entity) use ($plugin): bool {
                if ($entity === $plugin) {
                    return true;
                }
                self::assertInstanceOf(Integration::class, $entity);
                self::assertSame(MailRuPostmasterIntegration::NAME, $entity->getName());
                self::assertFalse($entity->getIsPublished());
                self::assertSame([], $entity->getApiKeys());

                return true;
            }));

        (new PluginSubscriber($repository, $entityManager))->onInstall(
            new PluginInstallEvent($plugin, null, null),
        );
    }

    public function testInstallMigratesLegacyConfigurationWithoutLosingKeys(): void
    {
        $plugin = new Plugin();
        $plugin->setBundle('MauticMailRuPostmasterBundle');

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
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static fn (object $entity) => $entity === $plugin || $entity === $legacy));

        (new PluginSubscriber($repository, $entityManager))->onInstall(
            new PluginInstallEvent($plugin, null, null),
        );

        self::assertSame(MailRuPostmasterIntegration::NAME, $legacy->getName());
        self::assertSame(['token_json' => 'encrypted-value'], $legacy->getApiKeys());
    }
}
