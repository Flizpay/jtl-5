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

    public function testCatalogsDroppedTheDiscountInformationCard(): void
    {
        // The card is gone; <fliz-checkout> shows the current rates instead.
        foreach (['de-DE', 'en-GB', 'en-US'] as $locale) {
            $catalog = (string)\file_get_contents(__DIR__ . '/../locale/' . $locale . '/base.po');

            $this->assertFalse(\str_contains($catalog, 'msgid "Discount Information"'));
            $this->assertFalse(\str_contains($catalog, 'msgid "Current Discount Rates:"'));
            $this->assertFalse(\str_contains($catalog, 'msgid "First Purchase"'));
            $this->assertFalse(\str_contains($catalog, 'msgid "Configure discount rates in your"'));
            // Still used by the welcome card.
            $this->assertTrue(\str_contains($catalog, 'msgid "FLIZpay Dashboard"'));
        }
    }

    public function testCatalogsContainWelcomeCardTranslations(): void
    {
        foreach (['de-DE', 'en-GB', 'en-US'] as $locale) {
            $catalog = (string)\file_get_contents(__DIR__ . '/../locale/' . $locale . '/base.po');

            $this->assertTrue(\str_contains($catalog, 'msgid "Welcome to FLIZpay!"'));
            $this->assertTrue(\str_contains($catalog, 'msgid "Instructions:"'));
            $this->assertTrue(\str_contains($catalog, 'msgid "Go to Shipping Methods"'));
            $this->assertTrue(\str_contains(
                $catalog,
                'msgid "If you use JTL-Wawi: create a payment method named exactly \"FLIZpay\"',
            ));
        }
    }

    public function testCatalogsContainCheckoutWidgetTranslations(): void
    {
        foreach (['de-DE', 'en-GB', 'en-US'] as $locale) {
            $catalog = (string)\file_get_contents(__DIR__ . '/../locale/' . $locale . '/base.po');

            $this->assertTrue(\str_contains($catalog, 'msgid "Headline"'));
            $this->assertTrue(\str_contains($catalog, 'msgid "Show the FLIZpay offer instead of the payment method name"'));
            $this->assertTrue(\str_contains($catalog, 'msgid "Save your FLIZpay API key to load the checkout preview."'));
            $this->assertTrue(\str_contains($catalog, 'msgid "The preview prices a sample basket of %s.'));
            $this->assertFalse(\str_contains($catalog, 'msgid "The discount value is retrieved'));
        }
    }
}
