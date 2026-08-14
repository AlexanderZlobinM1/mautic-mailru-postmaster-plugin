<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class ReportPeriodGrouper
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{year: int, months: list<array{key: string, number: int, rows: list<array<string, mixed>>, open: bool}>}>
     */
    public function group(array $rows, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('today');
        $currentKey = $now->format('Y-m');
        $grouped = [];

        foreach ($rows as $row) {
            $date = $this->parseDate($row['stat_date'] ?? null);
            if (null === $date) {
                continue;
            }

            $year = (int) $date->format('Y');
            $month = (int) $date->format('n');
            $grouped[$year][$month][] = $row;
        }

        // The current month is always visible and open, including before Mail.ru
        // has returned its first row for the month.
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $grouped[$currentYear][$currentMonth] ??= [];

        krsort($grouped, SORT_NUMERIC);
        $years = [];
        foreach ($grouped as $year => $months) {
            krsort($months, SORT_NUMERIC);
            $monthItems = [];
            foreach ($months as $month => $monthRows) {
                $key = sprintf('%04d-%02d', $year, $month);
                $monthItems[] = [
                    'key'    => $key,
                    'number' => (int) $month,
                    'rows'   => array_values($monthRows),
                    'open'   => $key === $currentKey,
                ];
            }
            $years[] = ['year' => (int) $year, 'months' => $monthItems];
        }

        return $years;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
