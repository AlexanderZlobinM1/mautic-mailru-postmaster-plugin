<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\RuntimeApiThrottle;
use PHPUnit\Framework\TestCase;

final class RuntimeApiThrottleTest extends TestCase
{
    public function testProcessesOneRequestAndSharesItsGeneration(): void
    {
        $stateDir = sys_get_temp_dir().'/mailru-postmaster-throttle-'.bin2hex(random_bytes(8));
        $throttle = new RuntimeApiThrottle($stateDir, 60);
        $calls = 0;

        try {
            $first = $throttle->runIfDue('example.test', static function () use (&$calls): int {
                ++$calls;

                return 3;
            });
            $second = $throttle->runIfDue('example.test', static function () use (&$calls): int {
                ++$calls;

                return 4;
            });

            self::assertTrue($first->attempted);
            self::assertTrue($first->successful);
            self::assertSame(3, $first->value);
            self::assertFalse($second->attempted);
            self::assertTrue($second->successful);
            self::assertSame($first->generation, $second->generation);
            self::assertSame(1, $calls);
        } finally {
            if (is_dir($stateDir)) {
                $files = glob($stateDir.'/*') ?: [];
                foreach ($files as $file) {
                    if (is_file($file) && !is_link($file)) {
                        unlink($file);
                    }
                }
                rmdir($stateDir);
            }
        }
    }

    public function testFailedRequestStillConsumesTheSharedInterval(): void
    {
        $stateDir = sys_get_temp_dir().'/mailru-postmaster-throttle-'.bin2hex(random_bytes(8));
        $throttle = new RuntimeApiThrottle($stateDir, 60);

        try {
            $first = $throttle->runIfDue('example.test', static function (): never {
                throw new \RuntimeException('temporary API failure');
            });
            $second = $throttle->runIfDue('example.test', static fn (): int => 1);

            self::assertTrue($first->attempted);
            self::assertFalse($first->successful);
            self::assertInstanceOf(\RuntimeException::class, $first->error);
            self::assertFalse($second->attempted);
            self::assertFalse($second->successful);
            self::assertSame($first->generation, $second->generation);
        } finally {
            if (is_dir($stateDir)) {
                $files = glob($stateDir.'/*') ?: [];
                foreach ($files as $file) {
                    if (is_file($file) && !is_link($file)) {
                        unlink($file);
                    }
                }
                rmdir($stateDir);
            }
        }
    }

    public function testBusyDomainLockSkipsWithoutWaiting(): void
    {
        $stateDir = sys_get_temp_dir().'/mailru-postmaster-throttle-'.bin2hex(random_bytes(8));
        mkdir($stateDir, 0770, true);
        $path = $stateDir.'/domain-'.hash('sha256', 'example.test').'.json';
        $handle = fopen($path, 'c+');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $startedAt = microtime(true);
            $result = (new RuntimeApiThrottle($stateDir, 60))->runIfDue(
                'example.test',
                static fn (): int => 1,
            );

            self::assertFalse($result->attempted);
            self::assertFalse($result->successful);
            self::assertSame('', $result->generation);
            self::assertLessThan(0.1, microtime(true) - $startedAt);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            unlink($path);
            rmdir($stateDir);
        }
    }
}
