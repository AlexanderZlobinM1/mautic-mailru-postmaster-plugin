<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<GuardStop>
 */
final class GuardStopRepository extends CommonRepository
{
    public function save(GuardStop $stop): void
    {
        $this->getEntityManager()->persist($stop);
        $this->getEntityManager()->flush();
    }
}
