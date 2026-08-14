<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStat;

final class ThresholdEvaluator
{
    /**
     * @param array<string, mixed> $properties
     *
     * @return array{metric: string, actual: float, threshold: float}|null
     */
    public function findBreach(DomainStat $stat, array $properties): ?array
    {
        $checks = [
            [
                'metric'    => 'spam_percent',
                'actual'    => $stat->getSpamPercent(),
                'threshold' => (float) ($properties['spam_threshold'] ?? 100),
            ],
            [
                'metric'    => 'probably_spam_percent',
                'actual'    => $stat->getProbablySpamPercent(),
                'threshold' => (float) ($properties['probably_spam_threshold'] ?? 100),
            ],
        ];

        foreach ($checks as $check) {
            if ($check['actual'] > $check['threshold']) {
                return $check;
            }
        }

        return null;
    }
}
