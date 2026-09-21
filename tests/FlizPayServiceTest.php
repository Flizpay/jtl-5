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

    public function testAcceptsFirstPurchaseOnlyPayload(): void
    {
        // The FLIZ backend omits `amount` (JSON.stringify strips undefined)
        // when a merchant configures only a first-purchase cashback.
        $this->assertSame(
            [
                'first_purchase_amount' => 20.0,
                'standard_amount' => 0.0,
            ],
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => 20,
                    'unit' => 'percentage',
                ],
            ]),
        );
    }

    public function testAcceptsStandardOnlyPayload(): void
    {
        $this->assertSame(
            [
                'first_purchase_amount' => 0.0,
                'standard_amount' => 2.5,
            ],
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'amount' => 2.5,
                    'unit' => 'percentage',
                ],
            ]),
        );
    }

    public function testRejectsCashbackPayloadWithNoNumericFields(): void
    {
        $this->assertSame(
            null,
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'unit' => 'percentage',
                ],
            ]),
        );
        $this->assertSame(
            null,
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => 'abc',
                    'amount' => null,
                ],
            ]),
        );
    }

    public function testTreatsNonNumericSideAsMissing(): void
    {
        // Non-numeric fields (e.g. accidental null / string) collapse to 0
        // so long as the other side carries a valid numeric value.
        $this->assertSame(
            [
                'first_purchase_amount' => 0.0,
                'standard_amount' => 2.0,
            ],
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => 'abc',
                    'amount' => 2,
                ],
            ]),
        );
    }

    public function testAcceptsExplicitZeroPair(): void
    {
        // Cashback cleared from the merchant app: both sides zero. This is
        // a valid, addressable state — downstream services already render
        // it as "no active cashback" via previewTitleSuffix()/…
        $this->assertSame(
            [
                'first_purchase_amount' => 0.0,
                'standard_amount' => 0.0,
            ],
            FlizPayService::normalizeCashback([
                'cashback' => [
                    'firstPurchaseAmount' => 0,
                    'amount' => 0,
                ],
            ]),
        );
    }

    public function testNormalizesWebhookShapedPayloadDirectly(): void
    {
        // WebhookController hands the decoded webhook body straight to
        // normalizeCashbackEntry; extra envelope keys must be ignored.
        $this->assertSame(
            [
                'first_purchase_amount' => 20.0,
                'standard_amount' => 0.0,
            ],
            FlizPayService::normalizeCashbackEntry([
                'updateCashbackInfo' => true,
                'firstPurchaseAmount' => 20,
            ]),
        );
        $this->assertSame(
            [
                'first_purchase_amount' => 5.0,
                'standard_amount' => 2.5,
            ],
            FlizPayService::normalizeCashbackEntry([
                'updateCashbackInfo' => true,
                'firstPurchaseAmount' => 5,
                'amount' => 2.5,
            ]),
        );
        $this->assertSame(
            null,
            FlizPayService::normalizeCashbackEntry([
                'updateCashbackInfo' => true,
            ]),
        );
        $this->assertSame(
            null,
            FlizPayService::normalizeCashbackEntry([
                'updateCashbackInfo' => true,
                'firstPurchaseAmount' => -1,
            ]),
        );
    }
}
