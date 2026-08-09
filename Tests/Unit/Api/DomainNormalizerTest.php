<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Api;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function emailProvider(): iterable
    {
        yield 'normal address' => ['no-reply@AlexPersonal.Online', 'alexpersonal.online'];
        yield 'subdomain'      => ['mail@news.example.org', 'news.example.org'];
        yield 'display name'   => ['Alex <mail@example.org>', null];
        yield 'template token' => ['{contactfield=email}', null];
        yield 'empty'          => [null, null];
    }

    #[DataProvider('emailProvider')]
    public function testExtractsOnlyRealExplicitSenderAddresses(?string $address, ?string $expected): void
    {
        self::assertSame($expected, DomainNormalizer::fromEmailAddress($address));
    }
}
