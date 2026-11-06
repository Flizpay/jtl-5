<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\CheckoutPresentationService;
use Plugin\flizpay\src\Service\CheckoutWidget;

class CheckoutWidgetTest extends TestCase
{
    private function widget(string $publicId = 'pub_1', ?int $amount = 12345): CheckoutWidget
    {
        return new CheckoutWidget($publicId, 'de-DE', $amount, true, true, true);
    }

    public function testAmountInCentsForEuro(): void
    {
        $this->assertSame(12345, CheckoutWidget::amountInCents(123.45, 'EUR'));
        $this->assertSame(1999, CheckoutWidget::amountInCents(19.985, 'eur'));
        $this->assertSame(0, CheckoutWidget::amountInCents(0.0, 'EUR'));
    }

    public function testNoAmountForOtherCurrenciesOrInvalidTotals(): void
    {
        $this->assertSame(null, CheckoutWidget::amountInCents(10.0, 'CHF'));
        $this->assertSame(null, CheckoutWidget::amountInCents(10.0, null));
        $this->assertSame(null, CheckoutWidget::amountInCents(-1.0, 'EUR'));
        $this->assertSame(null, CheckoutWidget::amountInCents(\NAN, 'EUR'));
        $this->assertSame(null, CheckoutWidget::amountInCents(\INF, 'EUR'));
    }

    public function testLocaleMapping(): void
    {
        $this->assertSame('de-DE', CheckoutWidget::locale('ger'));
        $this->assertSame('de-DE', CheckoutWidget::locale('GER'));
        $this->assertSame('en-GB', CheckoutWidget::locale('eng'));
        $this->assertSame('fr', CheckoutWidget::locale('fr'));
        $this->assertSame('de-DE', CheckoutWidget::locale(''));
    }

    public function testRenderableOnlyWithPublicId(): void
    {
        $this->assertTrue($this->widget()->isRenderable());
        $this->assertFalse($this->widget('')->isRenderable());
    }

    public function testElementCarriesComponentInputs(): void
    {
        $this->assertSame(
            '<fliz-checkout data-public-id="pub_1" data-surface="title" data-locale="de-DE" data-amount="12345">FLIZpay</fliz-checkout>',
            $this->widget()->elementHtml('title', 'FLIZpay'),
        );
    }

    public function testElementOmitsAmountWhenUnknown(): void
    {
        $html = $this->widget('pub_1', null)->elementHtml('description');

        $this->assertFalse(\str_contains($html, 'data-amount'));
        $this->assertTrue(\str_contains($html, 'data-surface="description"'));
    }

    public function testElementEscapesAttributes(): void
    {
        $html = $this->widget('"><script>x</script>')->elementHtml('title', '<b>');

        $this->assertFalse(\str_contains($html, '<script>'));
        $this->assertFalse(\str_contains($html, '<b>'));
        $this->assertTrue(\str_contains($html, '&quot;&gt;&lt;script&gt;'));
    }

    public function testScriptTagKeepsConsentManagersFromBlockingIt(): void
    {
        $html = CheckoutWidget::scriptTagHtml();

        $this->assertTrue(\str_contains($html, 'src="' . CheckoutWidget::SCRIPT_URL . '"'));
        $this->assertTrue(\str_contains($html, ' async '));
        $this->assertTrue(\str_contains($html, 'data-cookieconsent="ignore"'));
        $this->assertTrue(\str_contains($html, 'data-borlabs-cookie-script-blocker-ignore'));
    }

    public function testLoaderScriptIsIdempotentAndTogglesDescription(): void
    {
        $html = CheckoutPresentationService::loaderScriptHtml();

        $this->assertTrue(\str_contains($html, 'window.flizpayCheckoutLoader'));
        $this->assertTrue(\str_contains($html, 'script[data-flizpay-widget]'));
        $this->assertTrue(\str_contains($html, '"' . CheckoutWidget::SCRIPT_URL . '"'));
        $this->assertTrue(\str_contains($html, 'data-cookieconsent'));
        $this->assertTrue(\str_contains($html, 'el.hidden ='));
    }

    public function testScriptUrlOverride(): void
    {
        // Last: a defined constant cannot be undefined again.
        \define(CheckoutWidget::SCRIPT_URL_OVERRIDE, ' https://staging.example/flizpay.js ');

        $this->assertSame('https://staging.example/flizpay.js', CheckoutWidget::scriptUrl());
    }
}
