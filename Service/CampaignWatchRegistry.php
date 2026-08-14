<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class CampaignWatchRegistry
{
    private const MAX_PARENT_AGE_SECONDS = 21600;

    public function __construct(private readonly string $stateDir)
    {
    }

    public function registerParent(int $campaignId, int $parentPid): void
    {
        if ($campaignId <= 0 || $parentPid <= 0) {
            return;
        }

        $this->withParentState($campaignId, function (array $state) use ($parentPid): array {
            $parents = is_array($state['parents'] ?? null) ? $state['parents'] : [];
            $parents[(string) $parentPid] = time();
            $state['parents'] = $parents;

            return $state;
        });
    }

    public function hasLiveParent(int $campaignId): bool
    {
        $live = false;
        $this->withParentState($campaignId, function (array $state) use (&$live): array {
            $parents = is_array($state['parents'] ?? null) ? $state['parents'] : [];
            $minimumStartedAt = time() - self::MAX_PARENT_AGE_SECONDS;
            $filtered = [];
            foreach ($parents as $pid => $startedAt) {
                $parentPid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (false === $parentPid
                    || (int) $startedAt < $minimumStartedAt
                    || !$this->isProcessAlive((int) $parentPid)) {
                    continue;
                }
                $filtered[(string) $parentPid] = (int) $startedAt;
            }

            $live = [] !== $filtered;
            $state['parents'] = $filtered;

            return $state;
        });

        return $live;
    }

    /** @return resource|null */
    public function acquireWatcherLock(int $campaignId)
    {
        $this->ensureStateDirectory();
        $handle = fopen($this->watchLockPath($campaignId), 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Mail.ru Postmaster campaign watcher lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public function releaseWatcherLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $update */
    private function withParentState(int $campaignId, callable $update): void
    {
        $this->ensureStateDirectory();
        $handle = fopen($this->parentStatePath($campaignId), 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Mail.ru Postmaster campaign watcher state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the Mail.ru Postmaster campaign watcher state.');
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && '' !== trim($contents)
                ? json_decode($contents, true)
                : [];
            $state = $update(is_array($decoded) ? $decoded : []);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0)
                || false === fwrite($handle, $encoded.PHP_EOL)
                || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist the Mail.ru Postmaster campaign watcher state.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }

    private function ensureStateDirectory(): void
    {
        if (is_dir($this->stateDir)) {
            return;
        }
        if (!mkdir($this->stateDir, 0770, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException('Cannot create the Mail.ru Postmaster watcher directory.');
        }
    }

    private function parentStatePath(int $campaignId): string
    {
        return rtrim($this->stateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'campaign-'.$campaignId.'.json';
    }

    private function watchLockPath(int $campaignId): string
    {
        return rtrim($this->stateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'campaign-'.$campaignId.'.watch.lock';
    }
}
