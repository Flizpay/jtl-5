<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use JTL\DB\DbInterface;
use Plugin\flizpay\lib\FlizPlugin;

/**
 * Applies the FLIZpay cashback discount to an order after payment.
 *
 * FLIZpay decides the discount server-side: the webhook reports the charged
 * `amount` which can be lower than the requested `originalAmount`. While the
 * order is still held back from JTL-Wawi, the difference is inserted as a
 * negative coupon position (nPosTyp = C_WARENKORBPOS_TYP_KUPON, split across
 * the order's tax rates like a JTL coupon) and the order total is corrected —
 * Wawi then imports the discounted order. Once Wawi owns the order, positions
 * are never touched; the merchant gets an order remark instead.
 */
class DiscountService
{
    public const POSITION_NAME = 'FLIZpay Rabatt';

    private DbInterface $db;

    private TransactionRepository $repository;

    private OrderService $orderService;

    public function __construct(
        ?DbInterface $db = null,
        ?TransactionRepository $repository = null,
        ?OrderService $orderService = null
    ) {
        $this->db           = $db ?? FlizPlugin::getDB();
        $this->repository   = $repository ?? new TransactionRepository($this->db);
        $this->orderService = $orderService ?? new OrderService($this->db, $this->repository);
    }

    /**
     * @param float $discountOrderCurrency gross discount in the order's currency
     * @return bool true when the order was adjusted (or already carried the discount)
     */
    public function apply(int $kBestellung, float $discountOrderCurrency, string $transactionId): bool
    {
        if ($discountOrderCurrency < 0.005) {
            return true;
        }
        $orderData = $this->orderService->getOrderData($kBestellung);
        $orderRow  = $this->repository->getOrderRow($kBestellung);
        if ($orderData === null || $orderRow === null) {
            return false;
        }

        if ((int)$orderRow->nWawiHold !== 1 || $orderData->cAbgeholt !== 'Y') {
            // Wawi may already own this order — never mutate it, tell the merchant instead.
            $note = \sprintf(
                'FLIZpay: Rabatt von %s wurde bei der Zahlung gewährt, konnte aber nicht mehr automatisch '
                . 'übernommen werden. Bitte die Bestellung in JTL-Wawi manuell um den Rabatt anpassen.',
                $this->formatAmount($discountOrderCurrency)
            );
            $this->orderService->appendOrderRemark($kBestellung, $note);
            FlizPlugin::log(
                'discount NOT applied (order already released to Wawi) — merchant remark added',
                \LOGLEVEL_ERROR,
                ['order' => $kBestellung, 'tx' => $transactionId, 'discount' => $discountOrderCurrency]
            );

            return false;
        }

        $kWarenkorb = (int)$orderData->kWarenkorb;
        $existing   = $this->db->getSingleObject(
            'SELECT kWarenkorbPos FROM twarenkorbpos
                WHERE kWarenkorb = :cart AND nPosTyp = :type AND cName = :name',
            ['cart' => $kWarenkorb, 'type' => \C_WARENKORBPOS_TYP_KUPON, 'name' => self::POSITION_NAME]
        );
        if ($existing !== null) {
            return true;
        }

        $factor          = (float)$orderData->fWaehrungsFaktor > 0 ? (float)$orderData->fWaehrungsFaktor : 1.0;
        $discountDefault = $discountOrderCurrency / $factor;

        foreach ($this->splitByTaxRate($kWarenkorb, $discountDefault) as $share) {
            $net = -1 * \round($share['gross'] / (1 + $share['rate'] / 100), 5);
            $this->db->insert('twarenkorbpos', (object)[
                'kWarenkorb'                => $kWarenkorb,
                'kArtikel'                  => 0,
                'kVersandklasse'            => 0,
                'cName'                     => self::POSITION_NAME,
                'cLieferstatus'             => '',
                'cArtNr'                    => '',
                'cEinheit'                  => '',
                'fPreisEinzelNetto'         => $net,
                'fPreis'                    => $net,
                'fMwSt'                     => $share['rate'],
                'nAnzahl'                   => 1,
                'nPosTyp'                   => \C_WARENKORBPOS_TYP_KUPON,
                'cHinweis'                  => '',
                'cUnique'                   => '',
                'cResponsibility'           => 'core',
                'kKonfigitem'               => 0,
                'kBestellpos'               => 0,
                'fLagerbestandVorAbschluss' => 0,
            ]);
        }

        $this->db->queryPrepared(
            'UPDATE tbestellung SET fGesamtsumme = fGesamtsumme - :discount WHERE kBestellung = :oid',
            ['discount' => \round($discountDefault, 4), 'oid' => $kBestellung]
        );
        $this->repository->setDiscount($kBestellung, \number_format($discountOrderCurrency, 2, '.', ''));

        FlizPlugin::log(
            'cashback discount applied as coupon position',
            \LOGLEVEL_NOTICE,
            ['order' => $kBestellung, 'tx' => $transactionId, 'discount' => $discountOrderCurrency]
        );

        return true;
    }

    /**
     * Splits the gross discount proportionally across the tax rates present in
     * the order (JTL coupon semantics). Rounding remainder goes to the largest
     * share so the parts always sum to the exact discount.
     *
     * @return array<int, array{rate: float, gross: float}>
     */
    private function splitByTaxRate(int $kWarenkorb, float $discountGross): array
    {
        $rates = $this->db->getObjects(
            'SELECT fMwSt AS rate, SUM((fPreis * (1 + fMwSt / 100)) * nAnzahl) AS gross
                FROM twarenkorbpos
                WHERE kWarenkorb = :cart AND nPosTyp != :coupon
                GROUP BY fMwSt
                HAVING gross > 0
                ORDER BY gross DESC',
            ['cart' => $kWarenkorb, 'coupon' => \C_WARENKORBPOS_TYP_KUPON]
        );
        if (\count($rates) === 0) {
            return [['rate' => 0.0, 'gross' => \round($discountGross, 2)]];
        }

        $total  = \array_sum(\array_map(static fn($r) => (float)$r->gross, $rates));
        $shares = [];
        $used   = 0.0;
        foreach ($rates as $i => $rate) {
            $gross = ($i === \count($rates) - 1)
                ? \round($discountGross - $used, 2)
                : \round($discountGross * (float)$rate->gross / $total, 2);
            $used += $gross;
            if ($gross > 0) {
                $shares[] = ['rate' => (float)$rate->rate, 'gross' => $gross];
            }
        }

        return $shares !== [] ? $shares : [['rate' => 0.0, 'gross' => \round($discountGross, 2)]];
    }

    private function formatAmount(float $amount): string
    {
        return \number_format($amount, 2, ',', '.') . ' (Bestellwährung)';
    }
}
