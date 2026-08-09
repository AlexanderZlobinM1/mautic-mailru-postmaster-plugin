<?php

declare(strict_types=1);

use MauticPlugin\MauticMailRuPostmasterBundle\Controller\ReportController;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\MailRuPostmasterIntegration;

return [
    'name'        => 'Mail.ru Postmaster',
    'description' => 'Mail.ru Postmaster statistics, reports and automatic campaign protection.',
    'version'     => '0.5.0',
    'author'      => 'Sales Snap',
    'routes'      => [
        'main' => [
            'mautic_mailru_postmaster_report' => [
                'path'       => '/mailru-postmaster/reports',
                'controller' => ReportController::class.'::indexAction',
                'method'     => 'GET',
            ],
            'mautic_mailru_postmaster_domain' => [
                'path'         => '/mailru-postmaster/reports/{domain}',
                'controller'   => ReportController::class.'::domainAction',
                'method'       => 'GET',
                'requirements' => [
                    'domain' => '[A-Za-z0-9._-]+',
                ],
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'mailru.postmaster.menu' => [
                'route'     => 'mautic_mailru_postmaster_report',
                'iconClass' => 'ri-mail-settings-line',
                'access'    => [
                    'report:reports:viewown',
                    'report:reports:viewother',
                ],
                'priority' => 19,
            ],
        ],
    ],
    'services' => [
        'integrations' => [
            'mautic.integration.mailrupostmaster' => [
                'class'     => MailRuPostmasterIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],
];
