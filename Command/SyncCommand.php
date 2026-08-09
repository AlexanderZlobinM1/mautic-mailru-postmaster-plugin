<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Command;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\PostmasterSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:mailru-postmaster:sync',
    description: 'Synchronize Mail.ru Postmaster statistics and evaluate campaign guards.',
)]
final class SyncCommand extends Command
{
    public function __construct(private readonly PostmasterSyncService $syncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days to synchronize, including today.', '2')
            ->addOption('date-from', null, InputOption::VALUE_REQUIRED, 'Explicit start date in YYYY-MM-DD format.')
            ->addOption('date-to', null, InputOption::VALUE_REQUIRED, 'Explicit end date in YYYY-MM-DD format.')
            ->addOption('active-guards', null, InputOption::VALUE_NONE, 'Refresh only sender domains used by active campaign guards.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $activeGuards = (bool) $input->getOption('active-guards');
            if ($activeGuards) {
                $result = $this->syncService->syncActiveGuardDomains();
            } else {
                [$dateFrom, $dateTo] = $this->resolveDates($input);
                $result              = $this->syncService->sync($dateFrom, $dateTo);
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            $activeGuards
                ? 'Refreshed %d row(s) for %d active guard domain(s); stopped %d campaign(s).'
                : 'Stored %d rows for %d tracked domain(s); stopped %d campaign(s).',
            $result->storedRows,
            count($result->trackedDomains),
            $result->stoppedCampaigns,
        ));
        if ($activeGuards) {
            $io->definitionList(
                ['Active guard domains' => implode(', ', $result->mauticDomains) ?: 'none'],
                ['Verified tracked domains' => implode(', ', $result->trackedDomains) ?: 'none'],
            );

            return Command::SUCCESS;
        }
        $io->definitionList(
            ['Mautic From domains' => implode(', ', $result->mauticDomains) ?: 'none'],
            ['Postmaster domains'  => implode(', ', $result->registeredDomains) ?: 'none'],
            ['Tracked intersection'=> implode(', ', $result->trackedDomains) ?: 'none'],
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveDates(InputInterface $input): array
    {
        $dateToValue   = $input->getOption('date-to');
        $dateFromValue = $input->getOption('date-from');
        $dateTo        = new \DateTimeImmutable(is_string($dateToValue) && '' !== $dateToValue ? $dateToValue : 'today');

        if (is_string($dateFromValue) && '' !== $dateFromValue) {
            $dateFrom = new \DateTimeImmutable($dateFromValue);
        } else {
            $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 365],
            ]);
            if (false === $days) {
                throw new \InvalidArgumentException('--days must be between 1 and 365.');
            }
            $dateFrom = $dateTo->modify(sprintf('-%d days', $days - 1));
        }

        return [$dateFrom->setTime(0, 0), $dateTo->setTime(0, 0)];
    }
}
