<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use PHPUnit\Framework\TestCase;

final class EmailDomainProviderTest extends TestCase
{
    public function testDefaultSenderDomainComesFromEffectiveMauticParameter(): void
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->expects(self::once())
            ->method('get')
            ->with('mailer_from_email')
            ->willReturn('No-Reply@Example.ORG');
        $provider = new EmailDomainProvider($this->createMock(Connection::class), $parameters);
        $method = new \ReflectionMethod(EmailDomainProvider::class, 'getDefaultDomain');

        self::assertSame('example.org', $method->invoke($provider));
    }
}
