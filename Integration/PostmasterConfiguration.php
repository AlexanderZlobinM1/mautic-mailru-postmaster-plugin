<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;

final class PostmasterConfiguration
{
    public function __construct(private readonly IntegrationsHelper $integrationsHelper)
    {
    }

    public function isEnabled(): bool
    {
        return $this->getConfiguration()->getIsPublished();
    }

    public function getTokenPayload(): TokenPayload
    {
        $keys = $this->getConfiguration()->getApiKeys();

        return TokenPayload::fromJson((string) ($keys['token_json'] ?? ''));
    }

    public function saveTokenPayload(TokenPayload $payload): void
    {
        $configuration      = $this->getConfiguration();
        $keys               = $configuration->getApiKeys();
        $keys['token_json'] = $payload->toJson();
        $configuration->setApiKeys($keys);

        $this->integrationsHelper->saveIntegrationConfiguration($configuration);
    }

    private function getConfiguration(): Integration
    {
        return $this->integrationsHelper
            ->getIntegration(PostmasterIntegration::NAME)
            ->getIntegrationConfiguration();
    }
}
