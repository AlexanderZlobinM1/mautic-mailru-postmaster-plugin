<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Api;

final class TokenPayload
{
    private function __construct(
        private readonly string $refreshToken,
        private readonly ?string $accessToken,
        private readonly ?int $expiresAt,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $json = trim($json);
        if ('' === $json) {
            throw new \InvalidArgumentException('mailru.postmaster.config.error.token_required');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('mailru.postmaster.config.error.token_json');
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('mailru.postmaster.config.error.token_json');
        }

        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));
        if ('' === $refreshToken) {
            throw new \InvalidArgumentException('mailru.postmaster.config.error.refresh_token');
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $expiresAt   = isset($data['expires_at']) && is_numeric($data['expires_at'])
            ? (int) $data['expires_at']
            : null;

        return new self($refreshToken, '' === $accessToken ? null : $accessToken, $expiresAt);
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now->getTimestamp() + 60;
    }

    public function withAccessToken(string $accessToken, int $expiresIn, \DateTimeImmutable $now): self
    {
        $accessToken = trim($accessToken);
        if ('' === $accessToken) {
            throw new \InvalidArgumentException('Mail.ru returned an empty access token.');
        }

        return new self(
            $this->refreshToken,
            $accessToken,
            $now->getTimestamp() + max(60, $expiresIn),
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'access_token'  => $this->accessToken,
            'expires_at'    => $this->expiresAt,
            'refresh_token' => $this->refreshToken,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
