<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use JTL\DB\DbInterface;
use Plugin\flizpay\src\FlizPlugin;
use stdClass;

/**
 * Persistence for xplugin_flizpay_order and xplugin_flizpay_transaction.
 *
 * The order row carries the idempotency state; every state transition is
 * guarded by a conditional UPDATE whose affected-row count acts as a mutex, so
 * duplicate or concurrent webhook deliveries can race safely.
 */
class TransactionRepository
{
    private DbInterface $db;

    public function __construct(?DbInterface $db = null)
    {
        $this->db = $db ?? FlizPlugin::getDB();
    }

    // ------------------------------------------------------------------
    // Order rows
    // ------------------------------------------------------------------

    public function ensureOrderRow(int $kBestellung): stdClass
    {
        $this->db->queryPrepared(
            "INSERT IGNORE INTO xplugin_flizpay_order (kBestellung, dCreated) VALUES (:oid, NOW())",
            ["oid" => $kBestellung],
        );

        /** @var stdClass $row guaranteed to exist after the insert */
        $row = $this->getOrderRow($kBestellung);

        return $row;
    }

    public function getOrderRow(int $kBestellung): ?stdClass
    {
        return $this->db->getSingleObject(
            "SELECT * FROM xplugin_flizpay_order WHERE kBestellung = :oid",
            ["oid" => $kBestellung],
        );
    }

    public function setWawiHold(int $kBestellung, bool $held): void
    {
        $this->db->queryPrepared(
            "UPDATE xplugin_flizpay_order SET nWawiHold = :h, dUpdated = NOW() WHERE kBestellung = :oid",
            ["h" => $held ? 1 : 0, "oid" => $kBestellung],
        );
    }

    public function setDiscount(int $kBestellung, string $discount): void
    {
        $this->db->queryPrepared(
            "UPDATE xplugin_flizpay_order SET fDiscount = :d, dUpdated = NOW() WHERE kBestellung = :oid",
            ["d" => $discount, "oid" => $kBestellung],
        );
    }

    // ------------------------------------------------------------------
    // Transaction rows (allow-list + snapshots)
    // ------------------------------------------------------------------

    public function saveTransaction(
        int $kBestellung,
        string $transactionId,
        string $reference,
        int $attempt,
        string $originalAmount,
        string $currency,
    ): void {
        // FLIZpay's Idempotency-Key returns the identical transaction when the
        // completion page is reloaded — the upsert keeps that a single row.
        $this->db->queryPrepared(
            'INSERT INTO xplugin_flizpay_transaction
                (kBestellung, cTransactionId, cReference, nAttempt, fOriginalAmount, cCurrency, cStatus, dCreated)
                VALUES (:oid, :tx, :ref, :att, :amount, :cur, :status, NOW())
                ON DUPLICATE KEY UPDATE dUpdated = NOW()',
            [
                "oid" => $kBestellung,
                "tx" => $transactionId,
                "ref" => $reference,
                "att" => $attempt,
                "amount" => $originalAmount,
                "cur" => $currency,
                "status" => "created",
            ],
        );
    }

    public function getTransactionByTxId(string $transactionId): ?stdClass
    {
        return $this->db->getSingleObject(
            "SELECT * FROM xplugin_flizpay_transaction WHERE cTransactionId = :tx",
            ["tx" => $transactionId],
        );
    }

    /**
     * The most recently issued transaction for an order, regardless of attempt.
     *
     * Use this to answer "how did this order's payment go?" — after a failure
     * the order's attempt counter has already moved on, so the transaction that
     * actually failed is no longer the current-attempt one.
     */
    public function getLatestTransaction(int $kBestellung): ?stdClass
    {
        return $this->db->getSingleObject(
            'SELECT * FROM xplugin_flizpay_transaction
                WHERE kBestellung = :oid
                ORDER BY kFlizTransaction DESC
                LIMIT 1',
            ["oid" => $kBestellung],
        );
    }

    public function updateTransactionStatus(
        string $transactionId,
        string $status,
    ): void {
        $this->db->queryPrepared(
            "UPDATE xplugin_flizpay_transaction SET cStatus = :status, dUpdated = NOW() WHERE cTransactionId = :tx",
            ["status" => $status, "tx" => $transactionId],
        );
    }

    // ------------------------------------------------------------------
    // Conditional-UPDATE mutexes
    // ------------------------------------------------------------------

    /**
     * Claims the completion of an order. Exactly one caller wins; everyone
     * else (a duplicate or racing webhook) gets false.
     */
    public function markPaidOnce(int $kBestellung, string $transactionId): bool
    {
        return $this->db->getAffectedRows(
            'UPDATE xplugin_flizpay_order
                SET nPaid = 1, cCompletedTx = :tx, dUpdated = NOW()
                WHERE kBestellung = :oid AND nPaid = 0',
            ["tx" => $transactionId, "oid" => $kBestellung],
        ) === 1;
    }

    /**
     * Takes over a completion claim whose booking never finished.
     *
     * A booking in progress keeps touching dUpdated, so only a claim that has
     * been silent for $staleMinutes is considered abandoned. This is what keeps
     * concurrent webhook deliveries from booking the same payment twice while
     * allowing a later webhook retry to recover from a crashed booking.
     */
    public function claimStaleCompletion(
        int $kBestellung,
        string $transactionId,
        int $staleMinutes = 5,
    ): bool {
        return $this->db->getAffectedRows(
            'UPDATE xplugin_flizpay_order
                SET dUpdated = NOW()
                WHERE kBestellung = :oid
                    AND nPaid = 1
                    AND cCompletedTx = :tx
                    AND (dUpdated IS NULL OR dUpdated < (NOW() - INTERVAL :mins MINUTE))',
            [
                "tx" => $transactionId,
                "oid" => $kBestellung,
                "mins" => $staleMinutes,
            ],
        ) === 1;
    }

    /**
     * Keeps an in-flight booking's claim fresh so concurrent settlements keep
     * treating it as alive.
     */
    public function touchOrderRow(int $kBestellung): void
    {
        $this->db->queryPrepared(
            "UPDATE xplugin_flizpay_order SET dUpdated = NOW() WHERE kBestellung = :oid",
            ["oid" => $kBestellung],
        );
    }

    /**
     * Claims a failed settlement for the order's current attempt and advances
     * the attempt counter in the same statement.
     */
    public function markFailedOnce(
        int $kBestellung,
        string $transactionId,
        int $attempt,
    ): bool {
        return $this->db->getAffectedRows(
            'UPDATE xplugin_flizpay_order
                SET cFailedTx = :tx, nAttempt = nAttempt + 1, dUpdated = NOW()
                WHERE kBestellung = :oid
                    AND nPaid = 0
                    AND nAttempt = :att
                    AND (cFailedTx IS NULL OR cFailedTx <> :tx)',
            ["tx" => $transactionId, "oid" => $kBestellung, "att" => $attempt],
        ) === 1;
    }

    public function markCanceledOnce(
        int $kBestellung,
        string $transactionId,
        int $attempt,
    ): bool {
        return $this->db->getAffectedRows(
            'UPDATE xplugin_flizpay_order
                SET cCanceledTx = :tx, nAttempt = nAttempt + 1, dUpdated = NOW()
                WHERE kBestellung = :oid
                    AND nPaid = 0
                    AND nAttempt = :att
                    AND (cCanceledTx IS NULL OR cCanceledTx <> :tx)',
            ["tx" => $transactionId, "oid" => $kBestellung, "att" => $attempt],
        ) === 1;
    }

    public function markMailSentOnce(int $kBestellung): bool
    {
        return $this->db->getAffectedRows(
            'UPDATE xplugin_flizpay_order
                SET nMailSent = 1, dUpdated = NOW()
                WHERE kBestellung = :oid AND nMailSent = 0',
            ["oid" => $kBestellung],
        ) === 1;
    }

}
