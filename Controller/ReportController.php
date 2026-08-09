<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\DomainNormalizer;
use MauticPlugin\MauticMailRuPostmasterBundle\Entity\DomainStatRepository;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\ReportPeriodGrouper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportController extends CommonController
{
    public function indexAction(Request $request, DomainStatRepository $repository): Response
    {
        if (!$this->mayViewReports()) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters' => [
                'rows' => $repository->getLatestRows(),
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
        ReportPeriodGrouper $periodGrouper,
    ): Response
    {
        if (!$this->mayViewReports()) {
            return $this->accessDenied();
        }

        $domain = DomainNormalizer::normalize($domain);
        if (null === $domain) {
            throw new NotFoundHttpException('Unknown sender domain.');
        }

        $rows = $repository->getRowsForDomain($domain);
        if ([] === $rows) {
            throw new NotFoundHttpException('No Mail.ru Postmaster statistics for this domain.');
        }

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
