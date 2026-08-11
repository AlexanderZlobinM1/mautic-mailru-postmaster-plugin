<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Command;

use MauticPlugin\MauticMailRuPostmasterBundle\EventListener\CampaignSubscriber;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\CampaignWatchRegistry;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardRuntimePoller;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\GuardService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mautic:mailru-postmaster:guard-watch',
    description: 'Internal non-blocking Mail.ru Postmaster watcher for one running campaign trigger.',
    hidden: true,
)]
final class GuardWatchCommand extends Command
{
    public function __construct(
        private readonly GuardService $guardService,
        private readonly GuardRuntimePoller $runtimePoller,
        private readonly CampaignWatchRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'campaign-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Campaign ID observed by the parent trigger.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $campaignId = filter_var(
            $input->getOption('campaign-id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (false === $campaignId) {
            return Command::INVALID;
        }

        $watchLock = $this->registry->acquireWatcherLock($campaignId);
        if (!is_resource($watchLock)) {
            return Command::SUCCESS;
        }

        /** @var array<string, string> $lastGenerations */
        $lastGenerations = [];
        try {
            do {
                $monitors = $this->guardService->getActiveMonitorsForCampaign($campaignId);
                if ([] === $monitors) {
                    break;
                }

                $freshDomains = [];
                $monitorDomains = [];
                foreach ($monitors as $monitor) {
                    $poll = $this->runtimePoller->pollGuard($monitor, 'campaign_watcher');
                    $monitorId = (int) $monitor->getId();
                    $monitorDomains[$monitorId] = $poll->domain;
                    if (null === $poll->domain
                        || '' === $poll->generation
                        || ($lastGenerations[$poll->domain] ?? null) === $poll->generation) {
                        continue;
                    }

                    $lastGenerations[$poll->domain] = $poll->generation;
                    if ($poll->successful) {
                        $freshDomains[$poll->domain] = true;
                    }
                }

                foreach ($monitors as $monitor) {
                    if (CampaignSubscriber::EVENT_TYPE !== $monitor->getType()) {
                        continue;
                    }
                    $domain = $monitorDomains[(int) $monitor->getId()] ?? null;
                    if (null === $domain || !isset($freshDomains[$domain])) {
                        continue;
                    }
                    if ($this->guardService->evaluate($monitor, source: 'campaign_watcher')->stopped) {
                        break 2;
                    }
                }

                if (!$this->registry->hasLiveParent($campaignId)) {
                    break;
                }
                sleep(1);
            } while (true);
        } finally {
            $this->registry->releaseWatcherLock($watchLock);
        }

        return Command::SUCCESS;
    }
}
