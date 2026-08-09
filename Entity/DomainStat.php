<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class DomainStat
{
    private ?int $id = null;
    private string $domain;
    private \DateTimeImmutable $statDate;
    private string $messagesSent        = '0';
    private string $delivered           = '0';
    private string $complaints          = '0';
    private string $spam                = '0';
    private string $probablySpam        = '0';
    private string $readCount           = '0';
    private string $deletedRead         = '0';
    private string $deletedUnread       = '0';
    private string $spamPercent         = '0.00000000';
    private string $probablySpamPercent = '0.00000000';
    private string $reputation          = '0.00000000';
    private string $trend               = '0.00000000';
    private bool $isTracked             = true;
    private \DateTimeImmutable $syncedAt;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('mailru_postmaster_stats')
            ->setCustomRepositoryClass(DomainStatRepository::class)
            ->addUniqueConstraint(['domain', 'stat_date'], 'mailru_postmaster_domain_date')
            ->addIndex(['stat_date'], 'mailru_postmaster_stat_date')
            ->addIndex(['is_tracked', 'stat_date'], 'mailru_postmaster_tracked_date')
            ->addIndex(['synced_at'], 'mailru_postmaster_synced_at');

        $builder->addId();
        $builder->createField('domain', Types::STRING)->length(253)->build();
        $builder->createField('statDate', Types::DATE_IMMUTABLE)->columnName('stat_date')->build();

        foreach ([
            'messagesSent'  => 'messages_sent',
            'delivered'     => 'delivered',
            'complaints'    => 'complaints',
            'spam'          => 'spam',
            'probablySpam'  => 'probably_spam',
            'readCount'     => 'read_count',
            'deletedRead'   => 'deleted_read',
            'deletedUnread' => 'deleted_unread',
        ] as $field => $column) {
            $builder->createField($field, Types::BIGINT)
                ->columnName($column)
                ->option('unsigned', true)
                ->build();
        }

        foreach ([
            'spamPercent'         => 'spam_percent',
            'probablySpamPercent' => 'probably_spam_percent',
            'reputation'          => 'reputation',
            'trend'               => 'trend',
        ] as $field => $column) {
            $builder->createField($field, Types::DECIMAL)
                ->columnName($column)
                ->precision(18)
                ->scale(8)
                ->build();
        }

        $builder->createField('isTracked', Types::BOOLEAN)->columnName('is_tracked')->build();
        $builder->createField('syncedAt', Types::DATETIME_IMMUTABLE)->columnName('synced_at')->build();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $domain, \DateTimeImmutable $statDate, array $data, \DateTimeImmutable $syncedAt): void
    {
        $this->domain              = $domain;
        $this->statDate            = $statDate;
        $this->messagesSent        = self::count($data, 'messages_sent');
        $this->delivered           = self::count($data, 'delivered');
        $this->complaints          = self::count($data, 'complaints');
        $this->spam                = self::count($data, 'spam');
        $this->probablySpam        = self::count($data, 'probably_spam');
        $this->readCount           = self::count($data, 'read');
        $this->deletedRead         = self::count($data, 'deleted_read');
        $this->deletedUnread       = self::count($data, 'deleted_unread');
        $this->spamPercent         = self::decimal($data, 'spam_percent');
        $this->probablySpamPercent = self::decimal($data, 'probably_spam_percent');
        $this->reputation          = self::decimal($data, 'reputation');
        $this->trend               = self::decimal($data, 'trend');
        $this->isTracked           = true;
        $this->syncedAt            = $syncedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getStatDate(): \DateTimeImmutable
    {
        return $this->statDate;
    }

    public function getMessagesSent(): int
    {
        return (int) $this->messagesSent;
    }

    public function getSpamPercent(): float
    {
        return (float) $this->spamPercent;
    }

    public function getProbablySpamPercent(): float
    {
        return (float) $this->probablySpamPercent;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function count(array $data, string $key): string
    {
        return (string) max(0, (int) ($data[$key] ?? 0));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function decimal(array $data, string $key): string
    {
        return number_format((float) ($data[$key] ?? 0), 8, '.', '');
    }
}
