<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\DiscountService;
use Plugin\flizpay\src\Service\OrderService;
use Plugin\flizpay\src\Service\TransactionRepository;

/**
 * In-memory stand-ins for the settlement collaborators. The mutex helpers
 * reproduce the semantics of the real conditional UPDATEs (a claim succeeds
 * exactly once), which is what the concurrency tests exercise.
 */
class FakeTransactionRepository extends TransactionRepository
{
    public stdClass $orderRow;

    /** @var array<string, stdClass> keyed by transaction id */
    public array $transactions = [];

    public ?string $discount = null;

    public bool $mailSent = false;

    /** false models a concurrent settlement that is still booking right now */
    public bool $completionClaimIsStale = true;

    public int $touches = 0;

    public function __construct(array $orderRow = [])
    {
        // deliberately no parent::__construct() — no database in tests
        $this->orderRow = (object)\array_merge([
            'kBestellung'  => 1,
            'nAttempt'     => 0,
            'cCompletedTx' => null,
            'cFailedTx'    => null,
            'cCanceledTx'  => null,
            'nPaid'        => 0,
            'nMailSent'    => 0,
            'nWawiHold'    => 1,
            'fDiscount'    => null,
        ], $orderRow);
    }

    public function addTransaction(array $row): void
    {
        $tx                            = (object)\array_merge([
            'kBestellung'     => 1,
            'cTransactionId'  => 'tx_1',
            'cReference'      => 'ref_1',
            'nAttempt'        => 0,
            'fOriginalAmount' => '100.00',
            'cCurrency'       => 'EUR',
            'cStatus'         => 'created',
        ], $row);
        $this->transactions[$tx->cTransactionId] = $tx;
    }

    public function getOrderRow(int $kBestellung): ?stdClass
    {
        return (int)$this->orderRow->kBestellung === $kBestellung ? $this->orderRow : null;
    }

    public function getTransactionByTxId(string $transactionId): ?stdClass
    {
        return $this->transactions[$transactionId] ?? null;
    }

    public function getCurrentTransaction(int $kBestellung): ?stdClass
    {
        foreach ($this->transactions as $tx) {
            if ((int)$tx->kBestellung === $kBestellung && (int)$tx->nAttempt === (int)$this->orderRow->nAttempt) {
                return $tx;
            }
        }

        return null;
    }

    public function getLatestTransaction(int $kBestellung): ?stdClass
    {
        $latest = null;
        foreach ($this->transactions as $tx) {
            if ((int)$tx->kBestellung === $kBestellung) {
                $latest = $tx;
            }
        }

        return $latest;
    }

    public function claimStaleCompletion(int $kBestellung, string $transactionId, int $staleMinutes = 5): bool
    {
        return $this->completionClaimIsStale;
    }

    public function touchOrderRow(int $kBestellung): void
    {
        $this->touches++;
    }

    public function updateTransactionStatus(string $transactionId, string $status): void
    {
        if (isset($this->transactions[$transactionId])) {
            $this->transactions[$transactionId]->cStatus = $status;
        }
    }

    public function markPaidOnce(int $kBestellung, string $transactionId): bool
    {
        if ((int)$this->orderRow->nPaid !== 0) {
            return false;
        }
        $this->orderRow->nPaid        = 1;
        $this->orderRow->cCompletedTx = $transactionId;

        return true;
    }

    public function markFailedOnce(int $kBestellung, string $transactionId, int $attempt): bool
    {
        if ((int)$this->orderRow->nPaid !== 0
            || (int)$this->orderRow->nAttempt !== $attempt
            || (string)$this->orderRow->cFailedTx === $transactionId
        ) {
            return false;
        }
        $this->orderRow->cFailedTx = $transactionId;
        $this->orderRow->nAttempt  = (int)$this->orderRow->nAttempt + 1;

        return true;
    }

    public function markCanceledOnce(int $kBestellung, string $transactionId, int $attempt): bool
    {
        if ((int)$this->orderRow->nPaid !== 0
            || (int)$this->orderRow->nAttempt !== $attempt
            || (string)$this->orderRow->cCanceledTx === $transactionId
        ) {
            return false;
        }
        $this->orderRow->cCanceledTx = $transactionId;
        $this->orderRow->nAttempt    = (int)$this->orderRow->nAttempt + 1;

        return true;
    }

    public function markMailSentOnce(int $kBestellung): bool
    {
        if ($this->mailSent) {
            return false;
        }

        return $this->mailSent = true;
    }

    public function setDiscount(int $kBestellung, string $discount): void
    {
        $this->discount = $discount;
    }
}

class FakeOrderService extends OrderService
{
    public stdClass $orderData;

    /** @var array<int, array{amount: string, currency: string, tx: string}> */
    public array $bookings = [];

    public function __construct(array $orderData = [])
    {
        // deliberately no parent::__construct() — no database in tests
        $this->orderData = (object)\array_merge([
            'kBestellung'      => 1,
            'kZahlungsart'     => 7,
            'kWarenkorb'       => 42,
            'cBestellNr'       => 'BN-1',
            'cStatus'          => BESTELLUNG_STATUS_OFFEN,
            'cAbgeholt'        => 'Y',
            'dBezahltDatum'    => null,
            'fGesamtsumme'     => 100.00,
            'fWaehrungsFaktor' => 1.0,
        ], $orderData);
    }

    public function getOrderData(int $kBestellung): ?stdClass
    {
        return (int)$this->orderData->kBestellung === $kBestellung ? $this->orderData : null;
    }

    public function isFlizPayOrder(stdClass $orderData): bool
    {
        return (int)$orderData->kZahlungsart === 7;
    }

    public function bookPayment(int $kBestellung, string $amount, string $currency, string $transactionId): void
    {
        $this->bookings[]                 = ['amount' => $amount, 'currency' => $currency, 'tx' => $transactionId];
        $this->orderData->dBezahltDatum   = '2026-08-26 12:00:00';
        $this->orderData->cStatus         = BESTELLUNG_STATUS_BEZAHLT;
        $this->orderData->cAbgeholt       = 'N';
    }
}

class FakeDiscountService extends DiscountService
{
    /** @var array<int, array{order: int, discount: float, tx: string}> */
    public array $applied = [];

    public bool $result = true;

    public function __construct()
    {
        // deliberately no parent::__construct() — no database in tests
    }

    public function apply(int $kBestellung, float $discountOrderCurrency, string $transactionId): bool
    {
        $this->applied[] = ['order' => $kBestellung, 'discount' => $discountOrderCurrency, 'tx' => $transactionId];

        return $this->result;
    }
}
