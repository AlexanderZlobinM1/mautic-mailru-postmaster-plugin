<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\CampaignWatchRegistry;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardRuntimePoller;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardService;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardWatcherLauncher;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\RuntimeApiThrottle;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\MauticMailRuPostmasterBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, [
            'Api/DomainNormalizer.php',
            'Api/PostmasterApiException.php',
            'Api/TokenPayload.php',
            'Config',
            'DependencyInjection',
            'Entity',
            'Integration/MailRuPostmasterIntegration.php',
            'MauticMailRuPostmasterBundle.php',
            'Resources',
            'Service/GuardResult.php',
            'Service/RuntimePollResult.php',
            'Service/RuntimeThrottleResult.php',
            'Service/SyncResult.php',
            'Tests',
        ])).'}');

    $services->load('MauticPlugin\\MauticMailRuPostmasterBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    $services->set('mailru.postmaster.guard_audit_handler', RotatingFileHandler::class)
        ->args([
            '%kernel.logs_dir%/mailru_postmaster_guard.log',
            30,
            Logger::INFO,
        ]);

    $services->set('mailru.postmaster.guard_audit_logger', Logger::class)
        ->args(['mailru_postmaster_guard'])
        ->call('pushHandler', [service('mailru.postmaster.guard_audit_handler')]);

    $services->get(GuardService::class)
        ->arg('$logger', service('mailru.postmaster.guard_audit_logger'));

    $services->get(GuardRuntimePoller::class)
        ->arg('$logger', service('mailru.postmaster.guard_audit_logger'));

    $services->get(GuardWatcherLauncher::class)
        ->arg('$logger', service('mailru.postmaster.guard_audit_logger'))
        ->arg('$consolePath', '%kernel.project_dir%/bin/console');

    $services->get(CampaignWatchRegistry::class)
        ->arg('$stateDir', '%kernel.cache_dir%/mailru-postmaster-runtime/watchers');

    $services->get(RuntimeApiThrottle::class)
        ->arg('$stateDir', '%kernel.cache_dir%/mailru-postmaster-runtime')
        ->arg('$intervalSeconds', 7);
};
