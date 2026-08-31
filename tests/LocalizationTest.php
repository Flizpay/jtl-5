<?php

declare(strict_types=1);

class LocalizationTest extends TestCase
{
    public function testCatalogsContainPluginDomain(): void
    {
        foreach (['de-DE', 'en-GB', 'en-US'] as $locale) {
            $catalog = (string)\file_get_contents(__DIR__ . '/../locale/' . $locale . '/base.po');
            $this->assertTrue(\str_contains($catalog, '"X-Domain: flizpay\\n"'));
        }
    }

    public function testEnglishCatalogContainsFrontendTranslation(): void
    {
        $catalog = (string)\file_get_contents(__DIR__ . '/../locale/en-US/base.po');

        $this->assertTrue(\str_contains($catalog, 'msgid "Pay now with FLIZpay"'));
        $this->assertTrue(\str_contains($catalog, 'msgstr "Pay now with FLIZpay"'));
    }

    public function testCompiledCatalogsExist(): void
    {
        $this->assertTrue(\is_file(__DIR__ . '/../locale/de-DE/base.mo'));
        $this->assertTrue(\is_file(__DIR__ . '/../locale/en-GB/base.mo'));
        $this->assertTrue(\is_file(__DIR__ . '/../locale/en-US/base.mo'));
    }
}
