<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class PostmasterIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'mailru_postmaster';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Mail.ru Postmaster';
    }

    public function getIcon(): string
    {
        return 'plugins/MauticMailRuPostmasterBundle/Assets/img/postmaster.svg';
    }
}
