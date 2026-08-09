<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\IntegrationRepository;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterIntegration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PluginSubscriber implements EventSubscriberInterface
{
    private const BUNDLE = 'MauticMailRuPostmasterBundle';

    public function __construct(
        private readonly IntegrationRepository $integrationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', 50],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onUpdate', 50],
        ];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        $this->ensureIntegration($event->getPlugin());
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        $this->ensureIntegration($event->getPlugin());
    }

    private function ensureIntegration(Plugin $plugin): void
    {
        if (self::BUNDLE !== $plugin->getBundle()) {
            return;
        }

        if ($this->integrationRepository->findOneByName(PostmasterIntegration::NAME) instanceof Integration) {
            return;
        }

        $integration = new Integration();
        $integration->setName(PostmasterIntegration::NAME);
        $integration->setPlugin($plugin);
        $integration->setIsPublished(false);
        $integration->setApiKeys([]);
        $integration->setFeatureSettings([]);
        $integration->setSupportedFeatures([]);

        // Plugin reload persists and flushes the Plugin entity after this event.
        // Persist the related integration in the same unit of work without an early flush.
        $this->entityManager->persist($integration);
    }
}
