<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use JTL\DB\DbInterface;
use Plugin\flizpay\src\FlizPlugin;

/**
 * Stores the cashback data from the FLIZpay backend and keeps the stored
 * payment method presentation (plain name, no description, logo) in sync with
 * the merchant's display settings.
 *
 * The offer text itself comes from the hosted <fliz-checkout> component (see
 * CheckoutPresentationService). Manual renames of the method are overwritten
 * by design.
 */
class CashbackService
{
    public const TITLE_PLAIN = "FLIZpay";

    private DbInterface $db;

    private ConfigService $config;

    public function __construct(
        ?DbInterface $db = null,
        ?ConfigService $config = null,
    ) {
        $this->db = $db ?? FlizPlugin::getDB();
        $this->config = $config ?? new ConfigService($this->db);
    }

    /**
     * @param array{first_purchase_amount: float, standard_amount: float}|null $cashback
     */
    public function update(?array $cashback): void
    {
        $this->config->setCashback($cashback);
        $this->syncPresentation();
    }

    /**
     * Resets the method name to the plain "FLIZpay" and clears the shop
     * description in every language, and applies the logo setting.
     *
     * The offer text is rendered by the hosted <fliz-checkout> component, so
     * nothing cashback-derived is written into tzahlungsartsprache anymore.
     * Keeping the name exactly "FLIZpay" also lets JTL-Wawi match the method.
     */
    public function syncPresentation(): void
    {
        $kZahlungsart = FlizPlugin::getPaymentMethodId();
        if ($kZahlungsart <= 0) {
            return;
        }
        $this->syncLogo($kZahlungsart);

        $this->db->queryPrepared(
            'UPDATE tzahlungsartsprache
                SET cName = :name, cHinweisTextShop = :descr
                WHERE kZahlungsart = :pm',
            [
                "name" => self::TITLE_PLAIN,
                "descr" => "",
                "pm" => $kZahlungsart,
            ],
        );
    }

    /**
     * Hides/restores the method logo (tzahlungsart.cBild) according to the
     * display setting; the installed image path is parked in the runtime
     * config while hidden.
     */
    private function syncLogo(int $kZahlungsart): void
    {
        $row = $this->db->getSingleObject(
            "SELECT cBild FROM tzahlungsart WHERE kZahlungsart = :pm",
            ["pm" => $kZahlungsart],
        );
        $current = (string) ($row->cBild ?? "");
        if ($this->config->displayLogo()) {
            $parked =
                (string) ($this->config->get(ConfigService::KEY_METHOD_IMAGE) ??
                    "");
            if ($current === "" && $parked !== "") {
                $this->db->update(
                    "tzahlungsart",
                    "kZahlungsart",
                    $kZahlungsart,
                    (object) ["cBild" => $parked],
                );
            }
        } elseif ($current !== "") {
            $this->config->set(ConfigService::KEY_METHOD_IMAGE, $current);
            $this->db->update(
                "tzahlungsart",
                "kZahlungsart",
                $kZahlungsart,
                (object) ["cBild" => ""],
            );
        }
    }

    /**
     * Formats a provider percentage for checkout and admin presentation.
     */
    public function formatPercent(float $value, bool $german): string
    {
        $formatted = \rtrim(
            \rtrim(\number_format($value, 2, ".", ""), "0"),
            ".",
        );

        return $german ? \str_replace(".", ",", $formatted) : $formatted;
    }
}
