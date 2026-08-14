<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ThresholdEvaluator;
use PHPUnit\Framework\TestCase;

final class ThresholdEvaluatorTest extends TestCase
{
    public function testEqualValueDoesNotBreachStrictThreshold(): void
    {
        $stat = $this->stat(0.5, 0.5);

        self::assertNull((new ThresholdEvaluator())->findBreach($stat, [
            'spam_threshold'          => 0.5,
            'probably_spam_threshold' => 0.5,
        ]));
    }

    public function testSpamBreachUsesTheSameMetricAsCampaignStopper(): void
    {
        $stat = $this->stat(0.94637224, 0.0);

        self::assertSame([
            'metric'    => 'spam_percent',
            'actual'    => 0.94637224,
            'threshold' => 0.5,
        ], (new ThresholdEvaluator())->findBreach($stat, [
            'spam_threshold'          => 0.5,
            'probably_spam_threshold' => 0.5,
        ]));
    }

    public function testProbablySpamBreachRoutesToExceededBranch(): void
    {
        $stat = $this->stat(0.0, 1.25);

        self::assertSame([
            'metric'    => 'probably_spam_percent',
            'actual'    => 1.25,
            'threshold' => 1.0,
        ], (new ThresholdEvaluator())->findBreach($stat, [
            'spam_threshold'          => 2.0,
            'probably_spam_threshold' => 1.0,
        ]));
    }

    private function stat(float $spamPercent, float $probablySpamPercent): DomainStat
    {
        $now = new \DateTimeImmutable('2026-08-11 13:40:28');
        $stat = new DomainStat();
        $stat->update('example.test', $now, [
            'spam_percent'          => $spamPercent,
            'probably_spam_percent' => $probablySpamPercent,
        ], $now);

        return $stat;
    }
}
