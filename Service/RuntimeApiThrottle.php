<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class RuntimeApiThrottle
{
    public function __construct(
        private readonly string $stateDir,
        private readonly int $intervalSeconds = 7,
    ) {
    }

    /**
     * Serialize API reads for one sender domain across detached watchers and
     * skip immediately when another process owns the lock. Campaign sending
     * must never wait for a slow Mail.ru request.
     *
     * @param callable(): mixed $operation
     */
    public function runIfDue(string $domain, callable $operation): RuntimeThrottleResult
    {
        $this->ensureStateDirectory();
        $path = $this->statePath($domain);
        $handle = fopen($path, 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Mail.ru Postmaster runtime poll lock.');
        }

        $locked = false;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return RuntimeThrottleResult::busy();
            }
            $locked = true;

            $state = $this->readState($handle);
            $completedAt = (float) ($state['completed_at'] ?? 0.0);
            $generation = (string) ($state['generation'] ?? '');
            if ($completedAt > 0 && microtime(true) - $completedAt < $this->intervalSeconds) {
                return RuntimeThrottleResult::reused(
                    $generation,
                    true === ($state['successful'] ?? false),
                );
            }

            $value = null;
            $error = null;
            try {
                $value = $operation();
            } catch (\Throwable $exception) {
                $error = $exception;
            }

            $completedAt = microtime(true);
            $generation = sprintf('%.6f', $completedAt);
            $this->writeState($handle, [
                'completed_at' => $completedAt,
                'generation'   => $generation,
                'successful'   => null === $error,
            ]);

            return RuntimeThrottleResult::attempted($generation, $value, $error);
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function ensureStateDirectory(): void
    {
        if (is_dir($this->stateDir)) {
            return;
        }
        if (!mkdir($this->stateDir, 0770, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException('Cannot create the Mail.ru Postmaster runtime poll directory.');
        }
    }

    private function statePath(string $domain): string
    {
        return rtrim($this->stateDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'domain-'.hash('sha256', strtolower($domain)).'.json';
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>
     */
    private function readState($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if (!is_string($contents) || '' === trim($contents)) {
            return [];
        }

        $state = json_decode($contents, true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param resource             $handle
     * @param array<string, mixed> $state
     */
    private function writeState($handle, array $state): void
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0)
            || false === fwrite($handle, $encoded.PHP_EOL)
            || !fflush($handle)) {
            throw new \RuntimeException('Cannot persist the Mail.ru Postmaster runtime poll state.');
        }
    }
}
