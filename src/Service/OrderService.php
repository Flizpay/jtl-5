<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;
use Plugin\flizpay\src\FlizPlugin;
use Plugin\flizpay\paymentmethod\FlizPay;
use stdClass;

/**
 * Order-level operations for payment booking and the JTL-Wawi hold. State
 * idempotency lives in TransactionRepository; callers are mutex winners.
 */
class OrderService
{
    private DbInterface $db;

    private TransactionRepository $repository;

    public function __construct(
        ?DbInterface $db = null,
        ?TransactionRepository $repository = null,
    ) {
        $this->db = $db ?? FlizPlugin::getDB();
        $this->repository = $repository ?? new TransactionRepository($this->db);
    }

    /**
     */
    public function loadOrder(int $kBestellung): ?Bestellung
    {
        if ($kBestellung <= 0) {
            return null;
        }
        $order = new Bestellung($kBestellung, false, $this->db);
        if ((int) $order->kBestellung !== $kBestellung) {
            return null;
        }
        // signature: fuelleBestellung(bool $htmlCurrency, $external, $initProduct, $disableFactor)
        $order->fuelleBestellung(false, 0, false);

        return $order;
    }

    public function getPaymentMethod(): FlizPay
    {
        return new FlizPay(FlizPlugin::getModuleId());
    }

    /**
     * Books the payment: incoming payment record, paid status, Wawi release
     * and (once) the paid-confirmation mail. Mirrors the Mollie booking
     * sequence for JTL-Wawi compatibility.
     */
    public function bookPayment(
        int $kBestellung,
        string $amount,
        string $currency,
        string $transactionId,
    ): void {
        $order = $this->loadOrder($kBestellung);
        if ($order === null) {
            FlizPlugin::log("bookPayment: order not found", \LOGLEVEL_ERROR, [
                "order" => $kBestellung,
            ]);

            return;
        }
        $method = $this->getPaymentMethod();

        // guard against a repair-run double booking: only insert the incoming
        // payment when the order is unpaid AND no payment row exists yet
        $existingPayment = $this->db->getSingleObject(
            "SELECT kZahlungseingang FROM tzahlungseingang WHERE kBestellung = :oid",
            ["oid" => $kBestellung],
        );
        $addPayment =
            $order->dBezahltDatum === null && $existingPayment === null;
        FlizPlugin::debug("bookPayment: incoming payment", [
            "order" => $kBestellung,
            "tx" => $transactionId,
            "added" => $addPayment,
            "skipped" => $addPayment
                ? null
                : ($existingPayment !== null
                    ? "payment_row_exists"
                    : "already_paid"),
        ]);
        if ($addPayment) {
            $method->addIncomingPayment(
                $order,
                (object) [
                    "fBetrag" => (float) $amount,
                    "cISO" => $currency,
                    "cZahlungsanbieter" => "FLIZpay",
                    "cHinweis" => $transactionId,
                ],
            );
        }
        $method->setOrderStatusToPaid($order);
        $this->releaseWawiHold($kBestellung);

        $sendMail = $this->repository->markMailSentOnce($kBestellung);
        FlizPlugin::debug("bookPayment: status paid, wawi released", [
            "order" => $kBestellung,
            "tx" => $transactionId,
            "sendMail" => $sendMail,
        ]);
        if ($sendMail) {
            // sendMail() itself honours the method's nMailSenden mail flags
            $method->sendConfirmationMail($order);
        }

        FlizPlugin::log("payment booked", \LOGLEVEL_NOTICE, [
            "order" => $kBestellung,
            "tx" => $transactionId,
            "amount" => $amount,
            "currency" => $currency,
        ]);
    }

    /**
     * Hands the order over to JTL-Wawi once payment (and a possible discount)
     * has been settled.
     */
    public function releaseWawiHold(int $kBestellung): void
    {
        $row = $this->repository->getOrderRow($kBestellung);
        if ($row === null || (int) $row->nWawiHold !== 1) {
            return;
        }
        $this->db->queryPrepared(
            "UPDATE tbestellung SET cAbgeholt = 'N' WHERE kBestellung = :oid AND cAbgeholt = 'Y'",
            ["oid" => $kBestellung],
        );
    }

    /**
     * Merchant-facing note in the order remark (visible in JTL-Wawi). Used
     * only when the merchant must act — routine events go to the payment log.
     */
    public function appendOrderRemark(int $kBestellung, string $note): void
    {
        $this->db->queryPrepared(
            "UPDATE tbestellung
                SET cKommentar = TRIM(BOTH '\n' FROM CONCAT(COALESCE(cKommentar, ''), '\n', :note))
                WHERE kBestellung = :oid",
            ["note" => $note, "oid" => $kBestellung],
        );
    }

    /**
     * @return stdClass|null the raw tbestellung row
     */
    public function getOrderData(int $kBestellung): ?stdClass
    {
        return $this->db->getSingleObject(
            'SELECT kBestellung, kZahlungsart, kWarenkorb, cBestellNr, cStatus, cAbgeholt,
                    dBezahltDatum, fGesamtsumme, fWaehrungsFaktor
                FROM tbestellung
                WHERE kBestellung = :oid',
            ["oid" => $kBestellung],
        );
    }

    public function isFlizPayOrder(stdClass $orderData): bool
    {
        $methodId = FlizPlugin::getPaymentMethodId();

        return $methodId > 0 && (int) $orderData->kZahlungsart === $methodId;
    }
}
