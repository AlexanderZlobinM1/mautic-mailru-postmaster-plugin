<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\EventListener;

use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ReportSubscriber implements EventSubscriberInterface
{
    public const CONTEXT_STATS = 'mailru_postmaster_stats';
    public const CONTEXT_STOPS = 'mailru_postmaster_guard_stops';

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD    => ['onReportBuilder', 0],
            ReportEvents::REPORT_ON_GENERATE => ['onReportGenerate', 0],
        ];
    }

    public function onReportBuilder(ReportBuilderEvent $event): void
    {
        if ($event->checkContext(self::CONTEXT_STATS)) {
            $columns = [
                'ps.domain'                => self::column('mailru.postmaster.report.domain', 'string'),
                'ps.stat_date'             => self::column('mailru.postmaster.report.date', 'date'),
                'ps.messages_sent'         => self::column('mailru.postmaster.report.messages_sent', 'int'),
                'ps.delivered'             => self::column('mailru.postmaster.report.delivered', 'int'),
                'ps.complaints'            => self::column('mailru.postmaster.report.complaints', 'int'),
                'ps.spam'                  => self::column('mailru.postmaster.report.spam_count', 'int'),
                'ps.probably_spam'         => self::column('mailru.postmaster.report.probably_spam_count', 'int'),
                'ps.spam_percent'          => self::column('mailru.postmaster.report.spam_percent', 'float', '%'),
                'ps.probably_spam_percent' => self::column('mailru.postmaster.report.probably_spam_percent', 'float', '%'),
                'ps.reputation'            => self::column('mailru.postmaster.report.reputation', 'float', '%'),
                'ps.trend'                 => self::column('mailru.postmaster.report.trend', 'float', '%'),
                'ps.read_count'            => self::column('mailru.postmaster.report.read', 'int'),
                'ps.deleted_read'          => self::column('mailru.postmaster.report.deleted_read', 'int'),
                'ps.deleted_unread'        => self::column('mailru.postmaster.report.deleted_unread', 'int'),
                'ps.synced_at'             => self::column('mailru.postmaster.report.last_sync', 'datetime'),
            ];

            $event->addTable(self::CONTEXT_STATS, [
                'display_name' => 'mailru.postmaster.report.source.stats',
                'columns'      => $columns,
                'filters'      => $columns,
            ], 'mailru_postmaster');
        }

        if ($event->checkContext(self::CONTEXT_STOPS)) {
            $columns = [
                'gs.campaign_id'    => self::column('mailru.postmaster.report.campaign_id', 'int'),
                'gs.email_id'       => self::column('mailru.postmaster.report.email_id', 'int'),
                'gs.domain'         => self::column('mailru.postmaster.report.domain', 'string'),
                'gs.metric'         => self::column('mailru.postmaster.report.metric', 'string'),
                'gs.actual_value'   => self::column('mailru.postmaster.report.actual_value', 'float', '%'),
                'gs.threshold_value'=> self::column('mailru.postmaster.report.threshold_value', 'float', '%'),
                'gs.stat_date'      => self::column('mailru.postmaster.report.date', 'date'),
                'gs.stopped_at'     => self::column('mailru.postmaster.report.stopped_at', 'datetime'),
            ];

            $event->addTable(self::CONTEXT_STOPS, [
                'display_name' => 'mailru.postmaster.report.source.stops',
                'columns'      => $columns,
                'filters'      => $columns,
            ], 'mailru_postmaster');
        }
    }

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if ($event->checkContext(self::CONTEXT_STATS)) {
            $queryBuilder = $event->getQueryBuilder();
            $queryBuilder->from(MAUTIC_TABLE_PREFIX.'mailru_postmaster_stats', 'ps')
                ->andWhere('ps.is_tracked = 1')
                ->andWhere('ps.stat_date >= :mailru_postmaster_cutoff')
                ->setParameter(
                    'mailru_postmaster_cutoff',
                    (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d'),
                );
            $event->applyDateFilters($queryBuilder, 'stat_date', 'ps');
            $event->setQueryBuilder($queryBuilder);

            return;
        }

        if ($event->checkContext(self::CONTEXT_STOPS)) {
            $queryBuilder = $event->getQueryBuilder();
            $queryBuilder->from(MAUTIC_TABLE_PREFIX.'mailru_postmaster_guard_stops', 'gs');
            $event->applyDateFilters($queryBuilder, 'stopped_at', 'gs');
            $event->setQueryBuilder($queryBuilder);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function column(string $label, string $type, ?string $suffix = null): array
    {
        $column = ['label' => $label, 'type' => $type];
        if (null !== $suffix) {
            $column['suffix'] = $suffix;
        }

        return $column;
    }
}
