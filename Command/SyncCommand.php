<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Command;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\PostmasterSyncService;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
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
    public function __construct(
        private readonly PostmasterSyncService $syncService,
        private readonly PostmasterConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days to synchronize, including today.', '30')
            ->addOption('date-from', null, InputOption::VALUE_REQUIRED, 'Explicit start date in YYYY-MM-DD format.')
            ->addOption('date-to', null, InputOption::VALUE_REQUIRED, 'Explicit end date in YYYY-MM-DD format.')
            ->addOption('current-month', null, InputOption::VALUE_NONE, 'Synchronize only the current calendar month.')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Fill missing months in the complete 365-day Mail.ru history.')
            ->addOption('scheduled-full', null, InputOption::VALUE_NONE, 'Fill missing history months only when its configured weekly schedule is due.')
            ->addOption('force-rescan', null, InputOption::VALUE_NONE, 'Emergency rebuild: ignore completion markers and reread the complete 365-day history.')
            ->addOption('schedule-weekday', null, InputOption::VALUE_REQUIRED, 'Override scheduled-full weekday (0=Sunday, 6=Saturday).')
            ->addOption('schedule-time', null, InputOption::VALUE_REQUIRED, 'Override scheduled-full local time in HH:MM format.')
            ->addOption('active-guards', null, InputOption::VALUE_NONE, 'Refresh only sender domains used by active campaign guards.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $activeGuards = (bool) $input->getOption('active-guards');
        $lockHandle = null;

        try {
            $this->validateModeOptions($input);
            if ((bool) $input->getOption('scheduled-full') && !$this->isScheduledFullDue($input)) {
                $io->writeln('Full synchronization is not due in the current minute.');

                return Command::SUCCESS;
            }
            if (!$activeGuards) {
                $lockHandle = $this->acquireBulkSyncLock();
                if (null === $lockHandle) {
                    $io->writeln('Another Mail.ru Postmaster bulk synchronization is already running.');

                    return Command::SUCCESS;
                }
            }
            if ($activeGuards) {
                $result = $this->syncService->syncActiveGuardDomains();
            } else {
                [$dateFrom, $dateTo] = $this->resolveDates($input);
                $result              = $this->syncService->sync(
                    $dateFrom,
                    $dateTo,
                    (bool) $input->getOption('full') || (bool) $input->getOption('scheduled-full'),
                    (bool) $input->getOption('full')
                        || (bool) $input->getOption('scheduled-full')
                        || (bool) $input->getOption('force-rescan'),
                );
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
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

    /** @return resource|null */
    private function acquireBulkSyncLock()
    {
        $instanceKey = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: (string) getcwd();
        $path = sys_get_temp_dir().'/mautic-mailru-postmaster-'.substr(hash('sha256', $instanceKey), 0, 16).'.lock';
        $handle = fopen($path, 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot create Mail.ru Postmaster synchronization lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveDates(InputInterface $input): array
    {
        if ((bool) $input->getOption('full')
            || (bool) $input->getOption('scheduled-full')
            || (bool) $input->getOption('force-rescan')) {
            $dateTo = new \DateTimeImmutable('today');

            return [$dateTo->modify('-364 days'), $dateTo];
        }
        if ((bool) $input->getOption('current-month')) {
            $dateTo = new \DateTimeImmutable('today');

            return [$dateTo->modify('first day of this month'), $dateTo];
        }
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

    private function validateModeOptions(InputInterface $input): void
    {
        $modes = array_filter([
            (bool) $input->getOption('active-guards'),
            (bool) $input->getOption('current-month'),
            (bool) $input->getOption('full'),
            (bool) $input->getOption('scheduled-full'),
            (bool) $input->getOption('force-rescan'),
        ]);
        if (count($modes) > 1) {
            throw new \InvalidArgumentException('Choose only one synchronization mode.');
        }
    }

    private function isScheduledFullDue(InputInterface $input, ?\DateTimeImmutable $now = null): bool
    {
        $weekdayOption = $input->getOption('schedule-weekday');
        $weekday = null !== $weekdayOption
            ? filter_var($weekdayOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]])
            : $this->configuration->getFullSyncWeekday();
        if (false === $weekday) {
            throw new \InvalidArgumentException('--schedule-weekday must be between 0 and 6.');
        }

        $timeOption = $input->getOption('schedule-time');
        $time = is_string($timeOption) && '' !== $timeOption
            ? $timeOption
            : $this->configuration->getFullSyncTime();
        if (1 !== preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time)) {
            throw new \InvalidArgumentException('--schedule-time must use HH:MM format.');
        }

        $now ??= new \DateTimeImmutable();
        // PHP N: Monday=1..Sunday=7; public setting: Sunday=0..Saturday=6.
        $currentWeekday = (int) $now->format('N') % 7;

        return $currentWeekday === (int) $weekday && $now->format('H:i') === $time;
    }
}
