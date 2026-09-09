<?php

declare(strict_types=1);

use Plugin\flizpay\src\Api\FlizPayService;

class FlizPayServiceTest extends TestCase
{
    public function testPaymentKeyIsStableForTheSameOrderAndAttempt(): void
    {
        $key = FlizPayService::transactionIdempotencyKey(1, 'order-hash-a', 0);
        $this->assertSame($key, FlizPayService::transactionIdempotencyKey(1, 'order-hash-a', 0));
        $this->assertSame(68, strlen($key));
        $this->assertTrue(str_starts_with($key, 'jtl-'));
        $this->assertFalse(str_contains($key, 'order-hash-a'));
    }

    public function testPaymentKeyDistinguishesRecreatedOrdersAndRetries(): void
    {
        $key = FlizPayService::transactionIdempotencyKey(1, 'order-hash-a', 0);
        $this->assertFalse($key === FlizPayService::transactionIdempotencyKey(1, 'order-hash-b', 0));
        $this->assertFalse($key === FlizPayService::transactionIdempotencyKey(1, 'order-hash-a', 1));
        $this->assertFalse($key === FlizPayService::transactionIdempotencyKey(2, 'order-hash-a', 0));
        $this->assertFalse($key === 'jtl-' . hash('sha256', '1:0'));
    }

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
