<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

final class ReportTableTest extends TestCase
{
    public function testReportRendersProviderMetricsWithoutDerivedSendingStatus(): void
    {
        $template = file_get_contents(__DIR__.'/../../../Resources/views/Report/table.html.twig');

        self::assertIsString($template);
        self::assertStringNotContainsString('delivery_status', $template);
        self::assertStringNotContainsString('mailru.postmaster.report.status', $template);
        self::assertStringContainsString('mailru.postmaster.report.deliverability', $template);
        self::assertStringContainsString('mailru.postmaster.report.probably_spam', $template);
        self::assertStringContainsString('mailru.postmaster.report.spam', $template);
    }
}
