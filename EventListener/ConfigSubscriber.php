<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\ConfigBundle\Event\ConfigEvent;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\PostmasterConfigType;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConfigSubscriber implements EventSubscriberInterface
{
    /** @var array<string, mixed>|null */
    private ?array $pendingSettings = null;

    public function __construct(private readonly PostmasterConfiguration $configuration)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
            ConfigEvents::CONFIG_PRE_SAVE    => ['onConfigPreSave', 0],
            ConfigEvents::CONFIG_POST_SAVE   => ['onConfigPostSave', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $event->addForm([
            'bundle'     => 'MauticMailRuPostmasterBundle',
            'formAlias'  => PostmasterConfigType::ALIAS,
            'formType'   => PostmasterConfigType::class,
            'formTheme'  => '@MauticMailRuPostmaster/FormTheme/_config_mailru_postmaster_config_widget.html.twig',
            'parameters' => $this->configuration->getSettingsForForm(),
        ]);
    }

    public function onConfigPreSave(ConfigEvent $event): void
    {
        $data = $event->getConfig(PostmasterConfigType::ALIAS);
        $this->pendingSettings = is_array($data) ? $data : null;

        // The canonical copy remains in encrypted integration settings. Do not
        // leak the token or duplicate plugin values in config/local.php.
        $event->setConfig([], PostmasterConfigType::ALIAS);
    }

    public function onConfigPostSave(ConfigEvent $event): void
    {
        if (null === $this->pendingSettings) {
            return;
        }

        $settings = $this->pendingSettings;
        $this->pendingSettings = null;
        $this->configuration->saveSettingsFromForm($settings);
    }
}
