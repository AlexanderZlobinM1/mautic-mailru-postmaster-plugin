<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Api;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\PostmasterApiClient;
use PHPUnit\Framework\TestCase;

final class PostmasterApiClientTest extends TestCase
{
    public function testRateLimitDelayUsesMailRuAvailabilityHint(): void
    {
        $client = (new \ReflectionClass(PostmasterApiClient::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PostmasterApiClient::class, 'rateLimitDelay');

        self::assertSame(4, $method->invoke($client, [
            'detail' => 'Запрос был проигнорирован. Expected available in 3.0 seconds.',
        ]));
        self::assertSame(7, $method->invoke($client, ['detail' => 'rate limited']));
    }
}
