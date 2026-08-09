<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration\Support;

use Mautic\IntegrationsBundle\DTO\Note;
use Mautic\IntegrationsBundle\Integration\ConfigFormNotesTrait;
use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormNotesInterface;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\ConfigAuthType;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterIntegration;

final class ConfigSupport extends PostmasterIntegration implements ConfigFormInterface, ConfigFormAuthInterface, ConfigFormNotesInterface
{
    use ConfigFormNotesTrait;
    use DefaultConfigFormTrait;

    public function getAuthConfigFormName(): string
    {
        return ConfigAuthType::class;
    }

    public function getAuthorizationNote(): Note
    {
        return new Note('mailru.postmaster.config.instructions', Note::TYPE_INFO);
    }
}
