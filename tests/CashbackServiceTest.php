<?php

declare(strict_types=1);

use Plugin\flizpay\lib\Service\CashbackService;

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
}
