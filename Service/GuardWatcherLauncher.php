<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Psr\Log\LoggerInterface;

final class GuardWatcherLauncher
{
    public function __construct(
        private readonly GuardService $guardService,
        private readonly CampaignWatchRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $consolePath,
    ) {
    }

    public function launch(int $campaignId): void
    {
        if ($campaignId <= 0 || !$this->guardService->hasActiveMonitorForCampaign($campaignId)) {
            return;
        }

        $parentPid = getmypid();
        if (false === $parentPid || $parentPid <= 0) {
            $this->logger->warning('mailru_postmaster.guard_watcher_launch_failed', [
                'campaign_id' => $campaignId,
                'reason'      => 'Cannot determine campaign trigger PID.',
            ]);

            return;
        }
        $this->registry->registerParent($campaignId, $parentPid);

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            $this->logger->warning('mailru_postmaster.guard_watcher_launch_failed', [
                'campaign_id' => $campaignId,
                'reason'      => 'The PHP PCNTL extension is unavailable.',
            ]);

            return;
        }

        $pid = pcntl_fork();
        if (-1 === $pid) {
            $this->logger->warning('mailru_postmaster.guard_watcher_launch_failed', [
                'campaign_id' => $campaignId,
                'reason'      => 'Cannot fork the background watcher.',
            ]);

            return;
        }
        if ($pid > 0) {
            return;
        }

        if (function_exists('posix_setsid')) {
            @posix_setsid();
        }
        $this->detachStandardStreams();
        pcntl_exec(PHP_BINARY, [
            $this->consolePath,
            'mautic:mailru-postmaster:guard-watch',
            '--campaign-id='.$campaignId,
            '--env=prod',
            '--no-debug',
            '--quiet',
        ]);

        exit(127);
    }

    private function detachStandardStreams(): void
    {
        @fclose(STDIN);
        @fclose(STDOUT);
        @fclose(STDERR);
        fopen('/dev/null', 'r');
        fopen('/dev/null', 'a');
        fopen('/dev/null', 'a');
    }
}
