<?php

declare(strict_types=1);

use Plugin\flizpay\src\Api\FlizPayService;

class FlizPayServiceTest extends TestCase
{
    public function testNormalizesCurrentCashbackResponse(): void
    {
        $this->assertSame(
            [
                'first_purchase_amount' => 5.0,
                'standard_amount' => 2.5,
            ],
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => 5,
                    'amount' => '2.5',
                    'unit' => 'percentage',
                ],
            ]),
        );
    }

    public function testNormalizesLegacyCashbackResponse(): void
    {
        $this->assertSame(
            [
                'first_purchase_amount' => 4.0,
                'standard_amount' => 2.0,
            ],
            FlizPayService::normalizeCashback([
                'cashbacks' => [
                    ['active' => false, 'firstPurchaseAmount' => 9, 'amount' => 8],
                    ['active' => true, 'firstPurchaseAmount' => 4, 'amount' => 2],
                ],
            ]),
        );
    }

    public function testRejectsInvalidCashbackResponse(): void
    {
        $this->assertSame(
            null,
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => -1,
                    'amount' => 2,
                ],
            ]),
        );
    }
}
