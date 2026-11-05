<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\CashbackService;

class CashbackServiceTest extends TestCase
{
    public function testFormatsAdminDiscountPercentages(): void
    {
        $reflection = new ReflectionClass(CashbackService::class);
        /** @var CashbackService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        $this->assertSame("5", $service->formatPercent(5.0, false));
        $this->assertSame("5.25", $service->formatPercent(5.25, false));
        $this->assertSame("5,25", $service->formatPercent(5.25, true));
    }

    public function testSyncWritesPlainNameAndNoDescription(): void
    {
        // The offer text belongs to <fliz-checkout>; nothing cashback-derived
        // may be written into tzahlungsartsprache anymore.
        $source = \file_get_contents(
            __DIR__ . "/../src/Service/CashbackService.php",
        );

        $this->assertSame('FLIZpay', CashbackService::TITLE_PLAIN);
        $this->assertTrue(\str_contains($source, "cHinweisTextShop = :descr"));
        $this->assertTrue(\str_contains($source, '"descr" => "",'));
        $this->assertFalse(\str_contains($source, "previewTitle"));
        $this->assertFalse(\str_contains($source, "Spare"));
    }

    public function testPaymentTemplateRendersPaymentTitle(): void
    {
        $template = \file_get_contents(
            __DIR__ . "/../paymentmethod/template/index.tpl",
        );

        $this->assertTrue(
            \str_contains($template, '{$flizPaymentTitle|escape:\'html\'}'),
        );
    }

    public function testCheckoutPresentationTargetsThePaymentMethodById(): void
    {
        $service = \file_get_contents(
            __DIR__ . "/../src/Service/CheckoutPresentationService.php",
        );

        $this->assertTrue(
            \str_contains($service, 'input[name="Zahlungsart"][value="'),
        );
        $this->assertTrue(\str_contains($service, "flizpay-payment-copy"));
        $this->assertTrue(\str_contains($service, "append(\$note)"));
    }

    public function testCheckoutPresentationRendersBothSurfaces(): void
    {
        $service = \file_get_contents(
            __DIR__ . "/../src/Service/CheckoutPresentationService.php",
        );

        $this->assertTrue(\str_contains($service, 'elementHtml("title"'));
        $this->assertTrue(\str_contains($service, 'elementHtml("description")'));
        // Hidden, never remounted: a remount makes the element refetch.
        $this->assertTrue(\str_contains($service, '" hidden"'));
        // NOVA prints the name in span.content when no logo is shown.
        $this->assertTrue(\str_contains($service, 'children(".content")->remove()'));
        // Storefront rendering must never wait on FLIZpay.
        $this->assertTrue(\str_contains($service, "->resolve()"));
        $this->assertFalse(\str_contains($service, "->ensure("));
    }
}
