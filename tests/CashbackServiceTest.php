<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\CashbackService;
use Plugin\flizpay\src\Service\ConfigService;

class CashbackConfigStub extends ConfigService
{
    public function __construct(private ?array $cashback)
    {
    }

    public function getCashback(): ?array
    {
        return $this->cashback;
    }
}

class CashbackServiceTest extends TestCase
{
    public function testFormatsAdminDiscountPercentages(): void
    {
        $reflection = new ReflectionClass(CashbackService::class);
        /** @var CashbackService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('5', $service->formatPercent(5.0, false));
        $this->assertSame('5.25', $service->formatPercent(5.25, false));
        $this->assertSame('5,25', $service->formatPercent(5.25, true));
    }

    public function testUsesPlainTitleWithoutDiscount(): void
    {
        $service = $this->service(null);

        $this->assertSame('FLIZpay', $service->previewTitle(false));
        $this->assertSame('FLIZpay', $service->previewTitle(true));
    }

    public function testBuildsFirstPaymentTitleLikeMagento(): void
    {
        $service = $this->service([
            'first_purchase_amount' => 5.0,
            'standard_amount' => 0.0,
        ]);

        $this->assertSame(
            'FLIZpay - Save 5% on your first payment',
            $service->previewTitle(false),
        );
        $this->assertSame(
            'FLIZpay – Spare 5% bei deiner ersten Zahlung',
            $service->previewTitle(true),
        );
    }

    public function testBuildsBothRatesTitleLikeMagento(): void
    {
        $service = $this->service([
            'first_purchase_amount' => 5.0,
            'standard_amount' => 2.5,
        ]);

        $this->assertSame(
            'FLIZpay - Save up to 5%',
            $service->previewTitle(false),
        );
        $this->assertSame(
            'FLIZpay – Spare bis zu 5%',
            $service->previewTitle(true),
        );
    }

    public function testBuildsStandardTitleLikeMagento(): void
    {
        $service = $this->service([
            'first_purchase_amount' => 0.0,
            'standard_amount' => 2.5,
        ]);

        $this->assertSame(
            'FLIZpay - Up to 2.5% discount',
            $service->previewTitle(false),
        );
        $this->assertSame(
            'FLIZpay – Bis zu 2,5% Rabatt',
            $service->previewTitle(true),
        );
    }

    public function testPaymentTemplateRendersPaymentTitle(): void
    {
        $template = \file_get_contents(
            __DIR__ . '/../paymentmethod/template/index.tpl',
        );

        $this->assertTrue(
            \str_contains($template, '{$flizPaymentTitle|escape:\'html\'}'),
        );
    }

    public function testCheckoutPresentationTargetsThePaymentMethodById(): void
    {
        $service = \file_get_contents(
            __DIR__ . '/../src/Service/CheckoutPresentationService.php',
        );

        $this->assertTrue(
            \str_contains($service, 'input[name="Zahlungsart"][value="'),
        );
        $this->assertTrue(
            \str_contains($service, 'flizpay-payment-copy'),
        );
        $this->assertTrue(
            \str_contains($service, "append(\$note)"),
        );
        $this->assertTrue(
            \str_contains($service, "children('img')->length === 0"),
        );
    }

    private function service(?array $cashback): CashbackService
    {
        $reflection = new ReflectionClass(CashbackService::class);
        /** @var CashbackService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $config = $reflection->getProperty('config');
        $config->setValue($service, new CashbackConfigStub($cashback));

        return $service;
    }
}
