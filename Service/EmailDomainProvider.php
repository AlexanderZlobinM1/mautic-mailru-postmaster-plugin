<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class EmailDomainProvider
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * Returns domains from explicit From addresses stored on real Mautic email entities.
     *
     * @return list<string>
     */
    public function getDomains(): array
    {
        $addresses = $this->connection->createQueryBuilder()
            ->select('DISTINCT e.from_address')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->executeQuery()
            ->fetchFirstColumn();

        $domains = [];
        foreach ($addresses as $address) {
            $explicit = is_string($address) ? trim($address) : '';
            $domain = '' !== $explicit
                ? DomainNormalizer::fromEmailAddress($explicit)
                : $this->getDefaultDomain();
            if (null !== $domain) {
                $domains[$domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains, SORT_STRING);

        return $domains;
    }

    public function getDomainForEmail(int $emailId): ?string
    {
        $address = $this->connection->createQueryBuilder()
            ->select('e.from_address')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->where('e.id = :id')
            ->setParameter('id', $emailId)
            ->executeQuery()
            ->fetchOne();

        $explicit = is_string($address) ? trim($address) : '';

        return '' !== $explicit
            ? DomainNormalizer::fromEmailAddress($explicit)
            : $this->getDefaultDomain();
    }

    private function getDefaultDomain(): ?string
    {
        foreach (['mautic.mailer_from_email', 'mailer_from_email'] as $parameter) {
            if (!$this->parameterBag->has($parameter)) {
                continue;
            }
            $value = $this->parameterBag->get($parameter);
            if (!is_string($value) || '' === trim($value)) {
                continue;
            }
            $domain = DomainNormalizer::fromEmailAddress(trim($value));
            if (null !== $domain) {
                return $domain;
            }
        }

        return null;
    }
}
