<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\SettlementService;

/**
 * Covers the settlement state machine: validation, idempotency, the attempt
 * model and the money-moving paths.
 */
class SettlementServiceTest extends TestCase
{
    /**
     * @return array{0: SettlementService, 1: FakeTransactionRepository, 2: FakeOrderService, 3: FakeDiscountService}
     */
    private function make(array $orderRow = [], array $orderData = [], array $transaction = []): array
    {
        $repository = new FakeTransactionRepository($orderRow);
        $repository->addTransaction($transaction);
        $orderService = new FakeOrderService($orderData);
        $discount     = new FakeDiscountService();

        return [new SettlementService($repository, $orderService, $discount), $repository, $orderService, $discount];
    }

    private function payload(array $overrides = []): array
    {
        return \array_merge([
            'metadata'       => ['orderId' => 1],
            'transactionId'  => 'tx_1',
            'status'         => 'completed',
            'amount'         => 100.00,
            'originalAmount' => 100.00,
            'currency'       => 'EUR',
        ], $overrides);
    }

    // ---------------------------------------------------------------- happy paths

    public function testCompletedBooksThePayment(): void
    {
        [$settlement, $repository, $orderService] = $this->make();

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertTrue($result['success'], 'completed settles successfully');
        $this->assertSame('payment_completed', $result['result'], 'completed reports payment_completed');
        $this->assertSame(1, \count($orderService->bookings), 'exactly one booking');
        $this->assertSame('100.00', $orderService->bookings[0]['amount'], 'books the charged amount');
        $this->assertSame('EUR', $orderService->bookings[0]['currency'], 'books the order currency');
        $this->assertSame(1, (int)$repository->orderRow->nPaid, 'order is flagged paid');
        $this->assertSame('completed', $repository->transactions['tx_1']->cStatus, 'transaction status updated');
    }

    public function testCashbackDiscountIsAppliedBeforeBooking(): void
    {
        [$settlement, , $orderService, $discount] = $this->make();

        $settlement->settle($this->payload(['amount' => 95.00]), 'webhook');

        $this->assertSame(1, \count($discount->applied), 'discount applied once');
        $this->assertSame(5.0, $discount->applied[0]['discount'], 'discount is original minus charged');
        $this->assertSame('95.00', $orderService->bookings[0]['amount'], 'books the actually charged amount');
    }

    public function testNoDiscountWhenFullAmountCharged(): void
    {
        [$settlement, , , $discount] = $this->make();

        $settlement->settle($this->payload(), 'webhook');

        $this->assertSame(0, \count($discount->applied), 'no discount for a full-price payment');
    }

    public function testTopLevelOrderIdIsAccepted(): void
    {
        [$settlement] = $this->make();

        $payload = $this->payload();
        unset($payload['metadata']);
        $payload['orderId'] = 1;

        $this->assertSame('payment_completed', $settlement->settle($payload, 'webhook')['result'], 'webhook payload shape works');
    }

    public function testPendingAndProcessingChangeNothing(): void
    {
        foreach (['pending', 'processing'] as $status) {
            [$settlement, $repository, $orderService] = $this->make();

            $result = $settlement->settle($this->payload(['status' => $status]), 'webhook');

            $this->assertSame('no_change', $result['result'], $status . ' is a no-op');
            $this->assertSame(0, \count($orderService->bookings), $status . ' books nothing');
            $this->assertSame($status, $repository->transactions['tx_1']->cStatus, $status . ' is recorded on the tx');
        }
    }

    // ---------------------------------------------------------------- idempotency

    public function testReplayedCompletedWebhookIsIgnored(): void
    {
        [$settlement, , $orderService] = $this->make();
        $settlement->settle($this->payload(), 'webhook');

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('already_paid', $result['result'], 'replay is a no-op');
        $this->assertSame(1, \count($orderService->bookings), 'no second booking');
    }

    public function testSecondTransactionCannotDoubleBookAPaidOrder(): void
    {
        [$settlement, $repository, $orderService] = $this->make();
        $repository->addTransaction(['cTransactionId' => 'tx_2', 'cReference' => 'ref_2']);
        $settlement->settle($this->payload(), 'webhook');

        $result = $settlement->settle($this->payload(['transactionId' => 'tx_2']), 'webhook');

        $this->assertSame('already_paid', $result['result'], 'a different transaction cannot re-book');
        $this->assertSame(1, \count($orderService->bookings), 'still only one booking');
    }

    public function testFailedNeverDowngradesAPaidOrder(): void
    {
        [$settlement, $repository] = $this->make();
        $settlement->settle($this->payload(), 'webhook');

        $result = $settlement->settle($this->payload(['status' => 'failed']), 'webhook');

        $this->assertSame('already_paid', $result['result'], 'paid orders are never downgraded');
        $this->assertSame(1, (int)$repository->orderRow->nPaid, 'order stays paid');
    }

    public function testDuplicateFailureIsIgnored(): void
    {
        [$settlement, $repository] = $this->make();
        $settlement->settle($this->payload(['status' => 'failed']), 'webhook');
        $attemptAfterFirst = (int)$repository->orderRow->nAttempt;

        $result = $settlement->settle($this->payload(['status' => 'failed']), 'webhook');

        $this->assertSame('duplicate', $result['result'], 'the same failure twice is a no-op');
        $this->assertSame($attemptAfterFirst, (int)$repository->orderRow->nAttempt, 'attempt counter not advanced twice');
    }

    // ---------------------------------------------------------------- attempts / retries

    public function testFailureAdvancesTheAttemptAndKeepsTheOrderOpen(): void
    {
        [$settlement, $repository, $orderService] = $this->make();

        $result = $settlement->settle($this->payload(['status' => 'failed']), 'webhook');

        $this->assertSame('payment_failed', $result['result'], 'failure is recorded');
        $this->assertSame(1, (int)$repository->orderRow->nAttempt, 'attempt counter advanced');
        $this->assertSame('tx_1', (string)$repository->orderRow->cFailedTx, 'failure marker set');
        $this->assertSame(
            BESTELLUNG_STATUS_OFFEN,
            (int)$orderService->orderData->cStatus,
            'order stays open so the customer can pay again'
        );
    }

    public function testCanceledIsRecordedLikeAFailure(): void
    {
        [$settlement, $repository] = $this->make();

        $result = $settlement->settle($this->payload(['status' => 'canceled']), 'webhook');

        $this->assertSame('payment_canceled', $result['result'], 'cancellation is recorded');
        $this->assertSame('tx_1', (string)$repository->orderRow->cCanceledTx, 'cancel marker set');
        $this->assertSame(1, (int)$repository->orderRow->nAttempt, 'attempt counter advanced');
    }

    public function testStaleFailureFromAnEarlierAttemptIsIgnored(): void
    {
        // order is on attempt 1; a late failure for the attempt-0 transaction arrives
        [$settlement, $repository] = $this->make(['nAttempt' => 1], [], ['nAttempt' => 0]);

        $result = $settlement->settle($this->payload(['status' => 'failed']), 'webhook');

        $this->assertSame('older_attempt', $result['result'], 'stale failures do not disturb the retry');
        $this->assertSame(1, (int)$repository->orderRow->nAttempt, 'attempt counter untouched');
    }

    public function testLateCompletionFromAnEarlierAttemptStillBooks(): void
    {
        // the money moved on attempt 0 even though the customer already retried
        [$settlement, , $orderService] = $this->make(['nAttempt' => 1], [], ['nAttempt' => 0]);

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('payment_completed', $result['result'], 'a real payment is always booked');
        $this->assertSame(1, \count($orderService->bookings), 'payment booked');
    }

    public function testAbandonedBookingIsRepairedOnRetry(): void
    {
        // claim was taken but the booking never finished (crash between the two)
        [$settlement, $repository, $orderService] = $this->make(['nPaid' => 1, 'cCompletedTx' => 'tx_1']);
        $repository->completionClaimIsStale = true;

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('payment_completed', $result['result'], 'an abandoned settlement is completed');
        $this->assertSame(1, \count($orderService->bookings), 'payment is booked on the repair run');
    }

    public function testConcurrentBookingIsNotDuplicated(): void
    {
        // the claim is held by a settlement that is still booking right now —
        // the classic case of the webhook and the customer's return racing
        [$settlement, $repository, $orderService] = $this->make(['nPaid' => 1, 'cCompletedTx' => 'tx_1']);
        $repository->completionClaimIsStale = false;

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('in_progress', $result['result'], 'the loser stands down');
        $this->assertTrue($result['success'], 'standing down is not an error');
        $this->assertSame(0, \count($orderService->bookings), 'no second booking');
    }

    public function testAnotherTransactionsClaimIsNeverTakenOver(): void
    {
        [$settlement, $repository, $orderService] = $this->make(['nPaid' => 1, 'cCompletedTx' => 'tx_other']);
        $repository->completionClaimIsStale = true;

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('already_paid', $result['result'], 'a foreign claim is left alone');
        $this->assertSame(0, \count($orderService->bookings), 'no booking');
    }

    // ---------------------------------------------------------------- validation

    public function testUnknownTransactionIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['transactionId' => 'tx_forged']), 'webhook');

        $this->assertFalse($result['success'], 'forged transaction rejected');
        $this->assertSame('transaction_mismatch', $result['result'], 'reports transaction_mismatch');
    }

    public function testTransactionOfAnotherOrderIsRejected(): void
    {
        [$settlement, $repository] = $this->make();
        $repository->addTransaction(['cTransactionId' => 'tx_other', 'kBestellung' => 999]);

        $result = $settlement->settle($this->payload(['transactionId' => 'tx_other']), 'webhook');

        $this->assertSame('transaction_mismatch', $result['result'], 'cross-order transaction rejected');
    }

    public function testAmountMismatchIsRejected(): void
    {
        [$settlement, , $orderService] = $this->make();

        $result = $settlement->settle($this->payload(['amount' => 250.00, 'originalAmount' => 250.00]), 'webhook');

        $this->assertSame('amount_mismatch', $result['result'], 'tampered amount rejected');
        $this->assertSame(0, \count($orderService->bookings), 'nothing booked');
    }

    public function testCurrencyMismatchIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['currency' => 'USD']), 'webhook');

        $this->assertSame('currency_mismatch', $result['result'], 'foreign currency rejected');
    }

    public function testChargedMoreThanRequestedIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['amount' => 120.00]), 'webhook');

        $this->assertSame('invalid_amount', $result['result'], 'overcharge rejected');
    }

    public function testZeroAmountIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['amount' => 0]), 'webhook');

        $this->assertSame('invalid_amount', $result['result'], 'zero payment rejected');
    }

    public function testUnknownStatusIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['status' => 'refunded']), 'webhook');

        $this->assertSame('unknown_status', $result['result'], 'unknown status rejected');
    }

    public function testMissingOrderIsRejected(): void
    {
        [$settlement] = $this->make();

        $result = $settlement->settle($this->payload(['metadata' => ['orderId' => 4242]]), 'webhook');

        $this->assertSame('order_not_found', $result['result'], 'unknown order rejected');
    }

    public function testNonFlizPayOrderIsRejected(): void
    {
        [$settlement] = $this->make([], ['kZahlungsart' => 99]);

        $result = $settlement->settle($this->payload(), 'webhook');

        $this->assertSame('payment_method_mismatch', $result['result'], 'other payment methods are none of our business');
    }

    public function testMalformedPayloadIsRejected(): void
    {
        [$settlement] = $this->make();

        foreach ([
            ['transactionId' => ''],
            ['status' => ''],
            ['amount' => 'not-a-number'],
            ['currency' => 'EURO'],
            ['metadata' => ['orderId' => 'abc']],
        ] as $broken) {
            $result = $settlement->settle($this->payload($broken), 'webhook');
            $this->assertSame('invalid_payload', $result['result'], 'malformed payload rejected: ' . \key($broken));
        }
    }
}
