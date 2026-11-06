<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\PaymentOptionPresentationService;

class PaymentOptionPresentationServiceTest extends TestCase
{
    public function testSyncWritesPlainNameAndNoDescription(): void
    {
        // The offer text belongs to <fliz-checkout>; nothing offer-derived
        // may be written into tzahlungsartsprache anymore.
        $source = \file_get_contents(
            __DIR__ . "/../src/Service/PaymentOptionPresentationService.php",
        );

        $this->assertSame(
            'FLIZpay',
            PaymentOptionPresentationService::TITLE_PLAIN,
        );
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
