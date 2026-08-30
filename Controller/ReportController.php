<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ReportPeriodGrouper;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ReportRowFiller;
use MauticPlugin\MauticMailRuPostmasterBundle\Integration\PostmasterConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportController extends CommonController
{
    public function indexAction(
        Request $request,
        DomainStatRepository $repository,
        EmailDomainProvider $domainProvider,
        ReportRowFiller $rowFiller,
        PostmasterConfiguration $configuration,
    ): Response
    {
        if (!$configuration->isEnabled()) {
            throw new NotFoundHttpException();
        }

        if (!$this->mayViewReports()) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters' => [
                'rows' => $rowFiller->fillLatestRows($domainProvider->getDomains(), $repository->getLatestRows()),
            ],
            'contentTemplate' => '@MauticMailRuPostmaster/Report/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_mailru_postmaster_report',
                'mauticContent' => 'mailruPostmaster',
                'route'         => $this->generateUrl('mautic_mailru_postmaster_report'),
            ],
        ]);
    }

    public function domainAction(
        Request $request,
        string $domain,
        DomainStatRepository $repository,
        EmailDomainProvider $domainProvider,
        ReportPeriodGrouper $periodGrouper,
        ReportRowFiller $rowFiller,
        PostmasterConfiguration $configuration,
    ): Response
    {
        if (!$configuration->isEnabled()) {
            throw new NotFoundHttpException();
        }

        if (!$this->mayViewReports()) {
            return $this->accessDenied();
        }

        $domain = DomainNormalizer::normalize($domain);
        if (null === $domain || !in_array($domain, $domainProvider->getDomains(), true)) {
            throw new NotFoundHttpException($this->translator->trans('mailru.postmaster.report.error.unknown_domain'));
        }

        $rows = $rowFiller->fillDomainRows($domain, $repository->getRowsForDomain($domain));

        return $this->delegateView([
            'viewParameters' => [
                'domain' => $domain,
                'years'  => $periodGrouper->group($rows),
            ],
            'contentTemplate' => '@MauticMailRuPostmaster/Report/domain.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_mailru_postmaster_report',
                'mauticContent' => 'mailruPostmaster',
                'route'         => $this->generateUrl('mautic_mailru_postmaster_domain', ['domain' => $domain]),
            ],
        ]);
    }

    private function mayViewReports(): bool
    {
        return (bool) $this->security?->isGranted([
            'report:reports:viewown',
            'report:reports:viewother',
        ], 'MATCH_ONE');
    }
}
