<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class GuardStop
{
    private ?int $id = null;
    private int $campaignId;
    private int $emailId;
    private string $domain;
    private string $metric;
    private string $actualValue;
    private string $thresholdValue;
    private \DateTimeImmutable $statDate;
    private \DateTimeImmutable $stoppedAt;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('mailru_postmaster_guard_stops')
            ->setCustomRepositoryClass(GuardStopRepository::class)
            ->addIndex(['campaign_id', 'stopped_at'], 'mailru_postmaster_campaign_stop')
            ->addIndex(['domain', 'stat_date'], 'mailru_postmaster_domain_stop');

        $builder->addId();
        $builder->createField('campaignId', Types::INTEGER)->columnName('campaign_id')->option('unsigned', true)->build();
        $builder->createField('emailId', Types::INTEGER)->columnName('email_id')->option('unsigned', true)->build();
        $builder->createField('domain', Types::STRING)->length(253)->build();
        $builder->createField('metric', Types::STRING)->length(32)->build();
        $builder->createField('actualValue', Types::DECIMAL)->columnName('actual_value')->precision(18)->scale(8)->build();
        $builder->createField('thresholdValue', Types::DECIMAL)->columnName('threshold_value')->precision(18)->scale(8)->build();
        $builder->createField('statDate', Types::DATE_IMMUTABLE)->columnName('stat_date')->build();
        $builder->createField('stoppedAt', Types::DATETIME_IMMUTABLE)->columnName('stopped_at')->build();
    }

    public static function create(
        int $campaignId,
        int $emailId,
        string $domain,
        string $metric,
        float $actualValue,
        float $thresholdValue,
        \DateTimeImmutable $statDate,
        \DateTimeImmutable $stoppedAt,
    ): self {
        $stop                 = new self();
        $stop->campaignId     = $campaignId;
        $stop->emailId        = $emailId;
        $stop->domain         = $domain;
        $stop->metric         = $metric;
        $stop->actualValue    = number_format($actualValue, 8, '.', '');
        $stop->thresholdValue = number_format($thresholdValue, 8, '.', '');
        $stop->statDate       = $statDate;
        $stop->stoppedAt      = $stoppedAt;

        return $stop;
    }
}
