<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class DomainSyncPeriod
{
    private ?int $id = null;
    private string $domain;
    private \DateTimeImmutable $monthStart;
    private \DateTimeImmutable $completedAt;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('mailru_postmaster_sync_periods')
            ->setCustomRepositoryClass(DomainSyncPeriodRepository::class)
            ->addUniqueConstraint(['domain', 'month_start'], 'mailru_postmaster_domain_month')
            ->addIndex(['completed_at'], 'mailru_postmaster_period_completed');

        $builder->addId();
        $builder->createField('domain', Types::STRING)->length(253)->build();
        $builder->createField('monthStart', Types::DATE_IMMUTABLE)->columnName('month_start')->build();
        $builder->createField('completedAt', Types::DATETIME_IMMUTABLE)->columnName('completed_at')->build();
    }

    public function complete(string $domain, \DateTimeImmutable $monthStart, \DateTimeImmutable $completedAt): void
    {
        $this->domain      = $domain;
        $this->monthStart = $monthStart->modify('first day of this month')->setTime(0, 0);
        $this->completedAt = $completedAt;
    }
}
