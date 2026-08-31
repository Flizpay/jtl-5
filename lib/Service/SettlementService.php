<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use Plugin\flizpay\lib\FlizPlugin;

/**
 * The FLIZpay settlement state machine — a faithful port of the WooCommerce
 * plugin's Flizpay_Settlement, fed exclusively by authenticated webhooks.
 *
 * Validation rules (each with a stable rejection code):
 *  - the order must exist and be a FLIZpay order
 *  - the transactionId must be in the allow-list issued for that order
 *  - originalAmount/currency must match the snapshot taken at creation
 *  - 0 < amount <= originalAmount
 *
 * Idempotency: per-status terminal markers, an attempt counter advanced by
 * failed/canceled settlements, and paid orders that can never be downgraded.
 * Where WooCommerce re-reads and re-validates before mutating, this port uses
 * conditional-UPDATE mutexes (affected rows = winner) — a stronger primitive.
 *
 * Deliberate deviations from WooCommerce:
 *  - failed/canceled leave the order OFFEN so the customer can pay again.
 *  - a `completed` from ANY attempt is booked (money moved), only
 *    failed/canceled from older attempts are ignored.
 */
class SettlementService
{
    public const KNOWN_STATUSES = ['completed', 'failed', 'canceled', 'pending', 'processing'];

    private TransactionRepository $repository;

    private OrderService $orderService;

    private DiscountService $discountService;

    public function __construct(
        ?TransactionRepository $repository = null,
        ?OrderService $orderService = null,
        ?DiscountService $discountService = null
    ) {
        $this->repository      = $repository ?? new TransactionRepository();
        $this->orderService    = $orderService ?? new OrderService(null, $this->repository);
        $this->discountService = $discountService ?? new DiscountService(null, $this->repository, $this->orderService);
    }

    /**
     * @param array  $data   provider webhook payload
     * @param string $source log source, normally 'webhook'
     * @return array{success: bool, result: string, message: string}
     */
    public function settle(array $data, string $source = 'webhook'): array
    {
        $norm = $this->normalize($data);
        if ($norm === null) {
            return $this->finish(false, 'invalid_payload', 'Malformed payment payload', $source, $data);
        }

        $orderData = $this->orderService->getOrderData($norm['orderId']);
        if ($orderData === null) {
            return $this->finish(false, 'order_not_found', 'Order does not exist', $source, $norm);
        }
        if (!$this->orderService->isFlizPayOrder($orderData)) {
            return $this->finish(false, 'payment_method_mismatch', 'Order was not placed with FLIZpay', $source, $norm);
        }

        $txRow = $this->repository->getTransactionByTxId($norm['transactionId']);
        if ($txRow === null || (int)$txRow->kBestellung !== $norm['orderId']) {
            return $this->finish(false, 'transaction_mismatch', 'Transaction was not issued for this order', $source, $norm);
        }

        if (!\in_array($norm['status'], self::KNOWN_STATUSES, true)) {
            return $this->finish(false, 'unknown_status', 'Unknown provider status', $source, $norm);
        }

        $snapshotAmount = \number_format((float)$txRow->fOriginalAmount, 2, '.', '');
        if ($norm['originalAmount'] !== $snapshotAmount) {
            return $this->finish(false, 'amount_mismatch', 'originalAmount differs from the issued snapshot', $source, $norm);
        }
        if ($norm['currency'] !== \strtoupper((string)$txRow->cCurrency)) {
            return $this->finish(false, 'currency_mismatch', 'Currency differs from the issued snapshot', $source, $norm);
        }
        $amount   = (float)$norm['amount'];
        $original = (float)$norm['originalAmount'];
        if ($amount <= 0 || $original <= 0 || $amount > $original + 0.001) {
            return $this->finish(false, 'invalid_amount', 'Implausible amounts', $source, $norm);
        }

        if ($norm['status'] === 'pending' || $norm['status'] === 'processing') {
            $this->repository->updateTransactionStatus($norm['transactionId'], $norm['status']);

            return $this->finish(true, 'no_change', 'Payment still in progress', $source, $norm);
        }

        $orderRow = $this->repository->getOrderRow($norm['orderId']);
        if ($orderRow === null) {
            return $this->finish(false, 'internal_error', 'FLIZpay order state row missing', $source, $norm);
        }

        if ($norm['status'] === 'completed') {
            return $this->settleCompleted($norm, $orderData, $orderRow, $source);
        }

        return $this->settleFailedOrCanceled($norm, $txRow, $orderData, $orderRow, $source);
    }

    /**
     * @param array{orderId:int, transactionId:string, status:string, amount:string, originalAmount:string, currency:string} $norm
     */
    private function settleCompleted(array $norm, object $orderData, object $orderRow, string $source): array
    {
        $orderId = $norm['orderId'];
        $txId    = $norm['transactionId'];

        if ($orderData->dBezahltDatum !== null) {
            return $this->finish(true, 'already_paid', 'Order is already paid', $source, $norm);
        }

        if (!$this->repository->markPaidOnce($orderId, $txId)) {
            // Someone else holds the completion claim. Decide on freshly read
            // state because a webhook retry can race an in-flight booking.
            $fresh = $this->orderService->getOrderData($orderId);
            if ($fresh === null || $fresh->dBezahltDatum !== null) {
                return $this->finish(true, 'already_paid', 'Order is already paid', $source, $norm);
            }
            $freshRow = $this->repository->getOrderRow($orderId);
            if ($freshRow === null || (string)$freshRow->cCompletedTx !== $txId) {
                return $this->finish(true, 'already_paid', 'Another transaction completed this order', $source, $norm);
            }
            // The claim belongs to this transaction but no payment date exists.
            // Take over only once the claim has gone silent, so we never book
            // alongside a booking that is still running.
            if (!$this->repository->claimStaleCompletion($orderId, $txId)) {
                return $this->finish(true, 'in_progress', 'Another settlement is booking this payment', $source, $norm);
            }
            FlizPlugin::log(
                'repairing an abandoned completion claim',
                \LOGLEVEL_ERROR,
                ['order' => $orderId, 'tx' => $txId]
            );
        }

        try {
            $discount = \round((float)$norm['originalAmount'] - (float)$norm['amount'], 2);
            if ($discount >= 0.005) {
                $this->discountService->apply($orderId, $discount, $txId);
                // the discount pass can be slow; keep the claim visibly alive
                $this->repository->touchOrderRow($orderId);
            }
            $this->orderService->bookPayment($orderId, $norm['amount'], $norm['currency'], $txId);
            $this->repository->updateTransactionStatus($txId, 'completed');
        } catch (\Throwable $e) {
            FlizPlugin::log(
                'CRITICAL: payment confirmed by FLIZpay but booking failed; returning an error for webhook retry',
                \LOGLEVEL_ERROR,
                ['order' => $orderId, 'tx' => $txId, 'error' => $e->getMessage()]
            );

            return $this->finish(false, 'booking_failed', 'Booking failed after payment confirmation', $source, $norm);
        }

        return $this->finish(true, 'payment_completed', 'Order marked as paid', $source, $norm);
    }

    /**
     * @param array{orderId:int, transactionId:string, status:string, amount:string, originalAmount:string, currency:string} $norm
     */
    private function settleFailedOrCanceled(
        array $norm,
        object $txRow,
        object $orderData,
        object $orderRow,
        string $source
    ): array {
        $orderId = $norm['orderId'];
        $txId    = $norm['transactionId'];

        if ($orderData->dBezahltDatum !== null || (int)$orderRow->nPaid === 1) {
            return $this->finish(true, 'already_paid', 'Paid orders are never downgraded', $source, $norm);
        }
        // the terminal marker is the more specific diagnosis, so it is checked
        // before the attempt counter — settling a failure advances the counter,
        // which would otherwise make every replay look like a stale attempt
        $marker = $norm['status'] === 'failed' ? (string)$orderRow->cFailedTx : (string)$orderRow->cCanceledTx;
        if ($marker === $txId) {
            return $this->finish(true, 'duplicate', 'Already processed', $source, $norm);
        }
        if ((int)$txRow->nAttempt < (int)$orderRow->nAttempt) {
            return $this->finish(true, 'older_attempt', 'Stale attempt ignored', $source, $norm);
        }

        $won = $norm['status'] === 'failed'
            ? $this->repository->markFailedOnce($orderId, $txId, (int)$txRow->nAttempt)
            : $this->repository->markCanceledOnce($orderId, $txId, (int)$txRow->nAttempt);
        if (!$won) {
            return $this->finish(true, 'duplicate', 'Concurrent settlement already processed this event', $source, $norm);
        }

        $this->repository->updateTransactionStatus($txId, $norm['status']);
        // The order deliberately stays OFFEN so the customer can retry via
        // "pay again".

        return $this->finish(
            true,
            $norm['status'] === 'failed' ? 'payment_failed' : 'payment_canceled',
            'Payment did not complete; order kept open for retry',
            $source,
            $norm
        );
    }

    /**
     * @return array{orderId:int, transactionId:string, status:string, amount:string, originalAmount:string, currency:string}|null
     */
    private function normalize(array $data): ?array
    {
        $orderId = $data['orderId'] ?? ($data['metadata']['orderId'] ?? null);
        if (!\is_numeric($orderId) || (int)$orderId <= 0) {
            return null;
        }
        $transactionId = $data['transactionId'] ?? null;
        if (!\is_string($transactionId) || \trim($transactionId) === '') {
            return null;
        }
        $status = \strtolower(\trim((string)($data['status'] ?? '')));
        if ($status === '') {
            return null;
        }
        foreach (['amount', 'originalAmount'] as $field) {
            if (!isset($data[$field]) || !\is_numeric($data[$field])) {
                return null;
            }
        }
        $currency = \strtoupper(\trim((string)($data['currency'] ?? '')));
        if (\strlen($currency) !== 3) {
            return null;
        }

        return [
            'orderId'        => (int)$orderId,
            'transactionId'  => \trim($transactionId),
            'status'         => $status,
            'amount'         => \number_format((float)$data['amount'], 2, '.', ''),
            'originalAmount' => \number_format((float)$data['originalAmount'], 2, '.', ''),
            'currency'       => $currency,
        ];
    }

    /**
     * @return array{success: bool, result: string, message: string}
     */
    private function finish(bool $success, string $result, string $message, string $source, array $context): array
    {
        FlizPlugin::log(
            'settlement: ' . $result,
            $success ? \LOGLEVEL_NOTICE : \LOGLEVEL_ERROR,
            [
                'source' => $source,
                'order'  => $context['orderId'] ?? null,
                'tx'     => $context['transactionId'] ?? null,
                'status' => $context['status'] ?? null,
            ]
        );

        return ['success' => $success, 'result' => $result, 'message' => $message];
    }
}
