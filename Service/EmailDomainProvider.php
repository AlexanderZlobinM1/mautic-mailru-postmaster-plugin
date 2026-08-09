<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;

final class EmailDomainProvider
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parametersHelper,
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
        // Use the same helper as Mautic's actual mail sender. In Mautic 7 the
        // raw DI parameter may be an unresolved MAUTIC_MAILER_FROM_EMAIL env
        // placeholder while config/local.php contains the effective value.
        $value = $this->parametersHelper->get('mailer_from_email');
        if (is_string($value) && '' !== trim($value)) {
            return DomainNormalizer::fromEmailAddress(trim($value));
        }

        return null;
    }
}
