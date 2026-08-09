<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Api;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use PHPUnit\Framework\TestCase;

final class TokenPayloadTest extends TestCase
{
    public function testParsesCopiedMailRuJsonAndPersistsRefreshToken(): void
    {
        $payload = TokenPayload::fromJson('{"access_token":"access","expires_in":3600,"refresh_token":"refresh"}');

        self::assertSame('access', $payload->getAccessToken());
        self::assertSame('refresh', $payload->getRefreshToken());

        $fresh   = $payload->withAccessToken('new-access', 3600, new \DateTimeImmutable('@1000'));
        $decoded = json_decode($fresh->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('new-access', $decoded['access_token']);
        self::assertSame('refresh', $decoded['refresh_token']);
        self::assertSame(4600, $decoded['expires_at']);
    }

    public function testRejectsJsonWithoutRefreshToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mailru.postmaster.config.error.refresh_token');

        TokenPayload::fromJson('{"access_token":"access"}');
    }

    public function testExpiresWithSafetyWindow(): void
    {
        $payload = TokenPayload::fromJson('{"access_token":"access","refresh_token":"refresh","expires_at":1060}');

        self::assertTrue($payload->hasExpired(new \DateTimeImmutable('@1000')));
    }
}
