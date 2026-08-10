<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    public function testEnglishAndRussianCatalogsContainTheSameKeys(): void
    {
        $root    = dirname(__DIR__, 3);
        $english = $this->catalog($root.'/Translations/en_US/messages.ini');
        $russian = $this->catalog($root.'/Translations/ru/messages.ini');

        self::assertSame(array_keys($english), array_keys($russian));
    }

    public function testRussianLocaleAliasesStayIdentical(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertSame(
            $this->catalog($root.'/Translations/ru/messages.ini'),
            $this->catalog($root.'/Translations/ru_RU/messages.ini'),
        );
    }

    public function testUiKeysUsedOutsidePluginTemplatesAreTranslated(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['en_US', 'ru', 'ru_RU'] as $locale) {
            $catalog = $this->catalog($root.'/Translations/'.$locale.'/messages.ini');

            self::assertArrayHasKey('mailru.postmaster.action.back', $catalog);
            self::assertArrayHasKey('mautic.campaign.mailru.postmaster.guard', $catalog);
        }
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $path): array
    {
        $catalog = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($catalog);

        return $catalog;
    }
}
