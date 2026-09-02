<?php

declare(strict_types=1);

use Plugin\flizpay\src\Controller\ReturnController;

class ReturnControllerTest extends TestCase
{
    public function testMatchingOrderAndSessionCartsAreCleanedUp(): void
    {
        $this->assertTrue(
            ReturnController::shouldCleanUpCart(42, 42, 'checkout-session', 'checkout-session'),
            'the cart used for the paid order is cleared',
        );
    }

    public function testMatchingCheckoutSessionIsCleanedUpWhenCartIdWasLost(): void
    {
        $this->assertTrue(
            ReturnController::shouldCleanUpCart(42, 0, 'checkout-session', 'checkout-session'),
            'JTL can retain the purchased positions after losing the persisted cart id',
        );
    }

    public function testAReplacementCartIsNotCleanedUp(): void
    {
        $this->assertFalse(
            ReturnController::shouldCleanUpCart(42, 99, 'checkout-session', 'checkout-session'),
            'items added to a new cart after checkout are preserved',
        );
    }

    public function testMissingCartsAreNotCleanedUp(): void
    {
        $this->assertFalse(
            ReturnController::shouldCleanUpCart(0, 42, 'checkout-session', 'checkout-session'),
            'an invalid order cart cannot trigger cleanup',
        );
        $this->assertFalse(
            ReturnController::shouldCleanUpCart(42, 0, 'checkout-session', 'other-session'),
            'a sessionless return cannot trigger cleanup',
        );
    }

    public function testCompletedStatusPollUsesTheSameCleanupGuard(): void
    {
        $this->assertTrue(
            ReturnController::shouldCleanUpCart(42, 42, 'checkout-session', 'checkout-session'),
            'the status polling completion path can safely clear the purchased cart',
        );
    }

    public function testCompletionUrlUsesTheOrderHash(): void
    {
        $this->assertSame(
            '/Bestellabschluss?i=order-hash',
            ReturnController::completionUrl('/Bestellabschluss', 'order-hash'),
            'paid customers are sent through JTL order completion',
        );
    }

    public function testCompletionUrlRejectsAnEmptyHash(): void
    {
        $this->assertSame(
            null,
            ReturnController::completionUrl('/Bestellabschluss', ''),
            'an unavailable order hash uses the existing fallback',
        );
    }
}
