<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

final class ReportRowFiller
{
    /**
     * @param list<string>               $domains
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function fillLatestRows(array $domains, array $rows, ?\DateTimeImmutable $today = null): array
    {
        $rowsByDomain = [];
        foreach ($rows as $row) {
            $domain = $row['domain'] ?? null;
            if (is_string($domain)) {
                $row['has_statistics'] = true;
                $rowsByDomain[$domain] = $row;
            }
        }

        $domains = array_values(array_unique($domains));
        sort($domains, SORT_STRING);

        $result = [];
        foreach ($domains as $domain) {
            $result[] = $rowsByDomain[$domain] ?? $this->emptyRow($domain, $today);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function fillDomainRows(string $domain, array $rows, ?\DateTimeImmutable $today = null): array
    {
        if ([] !== $rows) {
            foreach ($rows as &$row) {
                $row['has_statistics'] = true;
            }
            unset($row);

            return $rows;
        }

        return [$this->emptyRow($domain, $today)];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(string $domain, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');

        return [
            'domain'                => $domain,
            'stat_date'             => $today->format('Y-m-d'),
            'messages_sent'         => 0,
            'complaints'            => 0,
            'reputation'            => 0.0,
            'trend'                 => 0.0,
            'spam_percent'          => 0.0,
            'probably_spam_percent' => 0.0,
            'has_statistics'        => false,
        ];
    }
}
