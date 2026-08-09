<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Api;

final class DomainNormalizer
{
    public static function fromEmailAddress(?string $address): ?string
    {
        $address = trim((string) $address);
        if (false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $at = strrpos($address, '@');

        return false === $at ? null : self::normalize(substr($address, $at + 1));
    }

    public static function normalize(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ('' === $domain) {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (false !== $ascii) {
                $domain = strtolower($ascii);
            }
        }

        if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain)) {
            return null;
        }

        return $domain;
    }
}
