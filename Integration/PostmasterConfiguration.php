<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;

final class PostmasterConfiguration
{
    public function __construct(private readonly IntegrationHelper $integrationHelper)
    {
    }

    public function isEnabled(): bool
    {
        return $this->getIntegration()->isEnabled();
    }

    public function getTokenPayload(): TokenPayload
    {
        return TokenPayload::fromJson($this->getIntegration()->getTokenJson());
    }

    public function saveTokenPayload(TokenPayload $payload): void
    {
        $this->getIntegration()->saveTokenPayload($payload);
    }

    public function getRetentionDays(): int
    {
        return $this->getIntegration()->getRetentionDays();
    }

    private function getIntegration(): MailRuPostmasterIntegration
    {
        $integration = $this->integrationHelper->getIntegrationObject(MailRuPostmasterIntegration::NAME);
        if (!$integration instanceof MailRuPostmasterIntegration) {
            throw new \RuntimeException('Mail.ru Postmaster integration is not registered. Reload Mautic plugins.');
        }

        return $integration;
    }
}
