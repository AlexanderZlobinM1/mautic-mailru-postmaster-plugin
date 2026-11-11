<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Psr\Log\LoggerInterface;

final class ThresholdEventLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $stateDir,
    ) {
    }

    /**
     * Persist the current threshold state and write only a transition into a
     * breached state. Safe observations reset the state without producing a
     * log entry.
     *
     * @param list<array{metric: string, actual: float, threshold: float}> $breaches
     * @param array<string, mixed>                                        $context
     */
    public function observe(string $monitor, string $identity, array $breaches, array $context): void
    {
        $metrics = array_values(array_unique(array_map(
            static fn (array $breach): string => $breach['metric'],
            $breaches,
        )));
        sort($metrics, SORT_STRING);
        $state = [] === $metrics ? 'safe' : implode('+', $metrics);

        try {
            $changed = $this->storeState($monitor, $identity, $state);
        } catch (\Throwable) {
            // Audit persistence must never interrupt campaign processing. If
            // state cannot be persisted, retain the important breach event.
            $changed = true;
        }

        if (!$changed || 'safe' === $state) {
            return;
        }

        $this->logger->warning('mailru_postmaster.'.$monitor.'_threshold_triggered', $context + [
            'triggered_metrics' => $metrics,
            'breaches'          => $breaches,
        ]);
    }

    private function storeState(string $monitor, string $identity, string $state): bool
    {
        if (!is_dir($this->stateDir)
            && !mkdir($this->stateDir, 0770, true)
            && !is_dir($this->stateDir)) {
            throw new \RuntimeException('Cannot create the Mail.ru Postmaster threshold state directory.');
        }

        $path = rtrim($this->stateDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'threshold-'.hash('sha256', $monitor."\0".$identity).'.json';
        $handle = fopen($path, 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Mail.ru Postmaster threshold state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the Mail.ru Postmaster threshold state.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $stored = is_string($contents) && '' !== trim($contents)
                ? json_decode($contents, true)
                : [];
            $previous = is_array($stored) ? ($stored['state'] ?? null) : null;
            if ($previous === $state) {
                return false;
            }

            $encoded = json_encode(['state' => $state], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0)
                || false === fwrite($handle, $encoded.PHP_EOL)
                || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist the Mail.ru Postmaster threshold state.');
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
