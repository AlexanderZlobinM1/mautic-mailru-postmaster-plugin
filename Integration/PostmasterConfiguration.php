<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\PostmasterConfigType;

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

    public function getFullSyncWeekday(): int
    {
        return $this->getIntegration()->getFullSyncWeekday();
    }

    public function getFullSyncTime(): string
    {
        return $this->getIntegration()->getFullSyncTime();
    }

    /** @return array<string, mixed> */
    public function getSettingsForForm(): array
    {
        $integration = $this->getIntegration();

        return [
            PostmasterConfigType::ENABLED => $integration->isEnabled(),
            PostmasterConfigType::TOKEN_JSON => $integration->getTokenJson(),
            PostmasterConfigType::RETENTION_DAYS => $integration->getRetentionDays(),
            PostmasterConfigType::FULL_SYNC_WEEKDAY => $integration->getFullSyncWeekday(),
            PostmasterConfigType::FULL_SYNC_TIME => $integration->getFullSyncTime(),
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettingsFromForm(array $settings): void
    {
        $this->getIntegration()->savePluginSettings(
            (bool) ($settings[PostmasterConfigType::ENABLED] ?? false),
            (string) ($settings[PostmasterConfigType::TOKEN_JSON] ?? ''),
            (int) ($settings[PostmasterConfigType::RETENTION_DAYS] ?? MailRuPostmasterIntegration::DEFAULT_RETENTION_DAYS),
            (int) ($settings[PostmasterConfigType::FULL_SYNC_WEEKDAY] ?? MailRuPostmasterIntegration::DEFAULT_FULL_SYNC_WEEKDAY),
            (string) ($settings[PostmasterConfigType::FULL_SYNC_TIME] ?? MailRuPostmasterIntegration::DEFAULT_FULL_SYNC_TIME),
        );
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
