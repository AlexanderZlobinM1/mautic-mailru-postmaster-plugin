<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\CampaignWatchRegistry;
use PHPUnit\Framework\TestCase;

final class CampaignWatchRegistryTest extends TestCase
{
    public function testTracksLiveTriggerAndAllowsOnlyOneWatcher(): void
    {
        $stateDir = sys_get_temp_dir().'/mailru-postmaster-watch-'.bin2hex(random_bytes(8));
        $registry = new CampaignWatchRegistry($stateDir);

        try {
            $registry->registerParent(167, getmypid());
            self::assertTrue($registry->hasLiveParent(167));

            $first = $registry->acquireWatcherLock(167);
            self::assertIsResource($first);
            self::assertNull($registry->acquireWatcherLock(167));

            $registry->releaseWatcherLock($first);
            $second = $registry->acquireWatcherLock(167);
            self::assertIsResource($second);
            $registry->releaseWatcherLock($second);
        } finally {
            if (is_dir($stateDir)) {
                foreach (glob($stateDir.'/*') ?: [] as $file) {
                    if (is_file($file) && !is_link($file)) {
                        unlink($file);
                    }
                }
                rmdir($stateDir);
            }
        }
    }
}
