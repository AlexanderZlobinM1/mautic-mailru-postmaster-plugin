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
        return $this->findBreaches($stat, $properties)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return list<array{metric: string, actual: float, threshold: float}>
     */
    public function findBreaches(DomainStat $stat, array $properties): array
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

        return array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['actual'] > $check['threshold'],
        ));
    }
}
