<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Service;

interface ConditionRuntimePoller
{
    public function pollDomainForDecision(string $domain, string $source): RuntimePollResult;
}
