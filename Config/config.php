<?php

declare(strict_types=1);

use MauticPlugin\MauticMailRuPostmasterBundle\Controller\ReportController;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterIntegration;

return [
    'name'        => 'Mail.ru Postmaster',
    'description' => 'Mail.ru Postmaster domain statistics and automatic campaign protection.',
    'version'     => '0.1.1',
    'author'      => 'Alexander Zlobin',
    'routes'      => [
        'main' => [
            'mautic_mailru_postmaster_report' => [
                'path'       => '/reports/mailru-postmaster',
                'controller' => ReportController::class.'::indexAction',
                'method'     => 'GET',
            ],
            'mautic_mailru_postmaster_domain' => [
                'path'         => '/reports/mailru-postmaster/{domain}',
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
                'checks' => [
                    'integration' => [
                        PostmasterIntegration::NAME => [
                            'enabled' => true,
                        ],
                    ],
                ],
                'priority' => 19,
            ],
        ],
    ],
];
