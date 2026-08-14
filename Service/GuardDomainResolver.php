<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;

final class GuardDomainResolver
{
    public function __construct(private readonly EmailDomainProvider $emailDomainProvider)
    {
    }

    public function resolve(Event $guard): ?string
    {
        $properties = $guard->getProperties();
        $selected = DomainNormalizer::normalize((string) ($properties['domain'] ?? ''));
        if (null !== $selected) {
            return $selected;
        }

        // Runtime compatibility for guard nodes saved before domain selection
        // replaced the old email selector.
        $legacyEmailId = (int) ($properties['email'] ?? 0);

        return $legacyEmailId > 0
            ? $this->emailDomainProvider->getDomainForEmail($legacyEmailId)
            : null;
    }

    public function getLegacyEmailId(Event $guard): int
    {
        return max(0, (int) ($guard->getProperties()['email'] ?? 0));
    }
}
