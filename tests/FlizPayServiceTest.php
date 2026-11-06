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

    public function testParsesPublicId(): void
    {
        $ok = ['status' => 200, 'data' => ['publicId' => ' pub_1 '], 'error' => null, 'jsonError' => false];
        $this->assertSame('pub_1', FlizPayService::parsePublicId($ok));
    }

    public function testRejectsUnusablePublicIdResponses(): void
    {
        $base = ['status' => 200, 'data' => null, 'error' => null, 'jsonError' => false];
        $this->assertSame(null, FlizPayService::parsePublicId(['data' => ['publicId' => '  ']] + $base));
        $this->assertSame(null, FlizPayService::parsePublicId(['data' => ['publicId' => 42]] + $base));
        $this->assertSame(null, FlizPayService::parsePublicId(['data' => []] + $base));
        $this->assertSame(null, FlizPayService::parsePublicId($base));
        $this->assertSame(null, FlizPayService::parsePublicId(['status' => 404, 'data' => ['publicId' => 'pub_1']] + $base));
        $this->assertSame(null, FlizPayService::parsePublicId(['status' => 0] + $base));
    }
}
