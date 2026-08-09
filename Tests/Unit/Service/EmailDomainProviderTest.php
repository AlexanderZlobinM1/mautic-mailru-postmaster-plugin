<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class EmailDomainProviderTest extends TestCase
{
    public function testDefaultSenderDomainComesFromEffectiveMauticParameter(): void
    {
        $provider = new EmailDomainProvider(
            $this->createMock(Connection::class),
            new ParameterBag(['mautic.mailer_from_email' => 'No-Reply@Example.ORG']),
        );
        $method = new \ReflectionMethod(EmailDomainProvider::class, 'getDefaultDomain');

        self::assertSame('example.org', $method->invoke($provider));
    }
}
