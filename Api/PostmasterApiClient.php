<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Api;

use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PostmasterApiClient
{
    private const API_BASE_URL = 'https://postmaster.mail.ru/ext-api/';
    private const TOKEN_URL    = 'https://o2.mail.ru/token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PostmasterConfiguration $configuration,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getRegisteredDomains(): array
    {
        $response = $this->request('reg-list/');
        $domains  = [];

        foreach (($response['domains'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['domain'])) {
                continue;
            }

            $domain = DomainNormalizer::normalize((string) $row['domain']);
            if (null !== $domain) {
                $domains[$domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains, SORT_STRING);

        return $domains;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDetailedStatistics(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $domain = null,
    ): array
    {
        $query = [
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to'   => $dateTo->format('Y-m-d'),
        ];
        if (null !== $domain) {
            $query['domain'] = $domain;
        }

        $response = $this->request('stat-list-detailed/', $query);

        return array_values(array_filter(
            $response['data'] ?? [],
            static fn (mixed $row): bool => is_array($row),
        ));
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function request(
        string $path,
        array $query = [],
        bool $retryAfterRefresh = true,
        int $rateLimitRetries = 3,
    ): array
    {
        if (!$this->configuration->isEnabled()) {
            throw new PostmasterApiException('Mail.ru Postmaster integration is disabled.');
        }

        $now     = new \DateTimeImmutable();
        $payload = $this->configuration->getTokenPayload();
        if (null === $payload->getAccessToken() || $payload->hasExpired($now)) {
            $payload = $this->refreshAccessToken($payload, $now);
        }

        $response = $this->httpClient->request('GET', self::API_BASE_URL.ltrim($path, '/'), [
            'headers' => [
                // Mail.ru Postmaster documents a custom `Bearer: TOKEN` header.
                'Bearer' => $payload->getAccessToken(),
            ],
            'query'   => $query,
            'timeout' => 30,
        ]);

        if (403 === $response->getStatusCode() && $retryAfterRefresh) {
            $this->refreshAccessToken($payload, $now);

            return $this->request($path, $query, false, $rateLimitRetries);
        }

        $data = $this->decodeResponse($response);
        if (429 === $response->getStatusCode() && $rateLimitRetries > 0) {
            sleep($this->rateLimitDelay($data));

            return $this->request($path, $query, $retryAfterRefresh, $rateLimitRetries - 1);
        }
        if ($response->getStatusCode() >= 400 || true !== ($data['ok'] ?? false)) {
            throw new PostmasterApiException($this->errorMessage($data, $response->getStatusCode()));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rateLimitDelay(array $data): int
    {
        $detail = (string) ($data['detail'] ?? '');
        if (1 === preg_match('/Expected available in\s+([0-9]+(?:\.[0-9]+)?)\s+seconds/i', $detail, $matches)) {
            return max(1, min(30, (int) ceil((float) $matches[1]) + 1));
        }

        return 7;
    }

    private function refreshAccessToken(TokenPayload $payload, \DateTimeImmutable $now): TokenPayload
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'client_id'     => 'postmaster_api_client',
                'grant_type'    => 'refresh_token',
                'refresh_token' => $payload->getRefreshToken(),
            ],
            'timeout' => 30,
        ]);

        $data = $this->decodeResponse($response);
        if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
            throw new PostmasterApiException($this->errorMessage($data, $response->getStatusCode()));
        }

        $freshPayload = $payload->withAccessToken(
            (string) $data['access_token'],
            (int) ($data['expires_in'] ?? 3600),
            $now,
        );
        $this->configuration->saveTokenPayload($freshPayload);

        return $freshPayload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $content = $response->getContent(false);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PostmasterApiException(sprintf('Mail.ru Postmaster returned invalid JSON (HTTP %d).', $response->getStatusCode()));
        }

        if (!is_array($data)) {
            throw new PostmasterApiException('Mail.ru Postmaster returned an unexpected response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function errorMessage(array $data, int $statusCode): string
    {
        $message = $data['error_description'] ?? $data['error'] ?? $data['detail'] ?? 'API request failed';

        return sprintf('Mail.ru Postmaster API error (HTTP %d): %s', $statusCode, (string) $message);
    }
}
