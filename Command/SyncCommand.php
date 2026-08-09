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
            ->addOption('date-to', null, InputOption::VALUE_REQUIRED, 'Explicit end date in YYYY-MM-DD format.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            [$dateFrom, $dateTo] = $this->resolveDates($input);
            $result              = $this->syncService->sync($dateFrom, $dateTo);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Stored %d rows for %d tracked domain(s); stopped %d campaign(s).',
            $result->storedRows,
            count($result->trackedDomains),
            $result->stoppedCampaigns,
        ));
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
                'options' => ['min_range' => 1, 'max_range' => 366],
            ]);
            if (false === $days) {
                throw new \InvalidArgumentException('--days must be between 1 and 366.');
            }
            $dateFrom = $dateTo->modify(sprintf('-%d days', $days - 1));
        }

        return [$dateFrom->setTime(0, 0), $dateTo->setTime(0, 0)];
    }
}
