<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
            'Service/SyncResult.php',
            'Tests',
        ])).'}');

    $services->load('MauticPlugin\\MauticMailRuPostmasterBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);
};
