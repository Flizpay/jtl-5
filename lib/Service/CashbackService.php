<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use JTL\DB\DbInterface;
use Plugin\flizpay\lib\FlizPlugin;

/**
 * Keeps the checkout presentation of the payment method in sync with the
 * cashback data from the FLIZpay backend and the merchant's display settings.
 *
 * The checkout label/description come from tzahlungsartsprache (per language),
 * so this service rewrites those rows whenever cashback or display settings
 * change (settings save, handshake, updateCashbackInfo webhook). Manual
 * renames of the method are therefore overwritten by design.
 */
class CashbackService
{
    private const TITLE_PLAIN = "FLIZpay";

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
     * Rewrites method name, description and logo according to the stored
     * cashback data and the display settings.
     */
    public function syncPresentation(): void
    {
        $kZahlungsart = FlizPlugin::getPaymentMethodId();
        if ($kZahlungsart <= 0) {
            return;
        }
        $this->syncLogo($kZahlungsart);

        $rows = $this->db->getObjects(
            "SELECT cISOSprache FROM tzahlungsartsprache WHERE kZahlungsart = :pm",
            ["pm" => $kZahlungsart],
        );
        foreach ($rows as $row) {
            $german = \strtolower((string) $row->cISOSprache) === "ger";
            $this->db->queryPrepared(
                'UPDATE tzahlungsartsprache
                    SET cName = :name, cHinweisTextShop = :descr
                    WHERE kZahlungsart = :pm AND cISOSprache = :iso',
                [
                    "name" =>
                        self::TITLE_PLAIN . $this->previewTitleSuffix($german),
                    "descr" => $this->config->displayDescription()
                        ? $this->previewDescription($german)
                        : "",
                    "pm" => $kZahlungsart,
                    "iso" => $row->cISOSprache,
                ],
            );
        }
    }

    /**
     * Cashback suffix appended to the method title, e.g. " - Bis zu 5% Rabatt".
     * Empty when no cashback is configured. Also used by the admin preview.
     */
    public function previewTitleSuffix(bool $german): string
    {
        $cashback = $this->config->getCashback();
        $maxPct = \max(
            (float) ($cashback["first_purchase_amount"] ?? 0),
            (float) ($cashback["standard_amount"] ?? 0),
        );
        if ($maxPct <= 0) {
            return "";
        }
        $pct = $this->formatPercent($maxPct, $german);

        return $german
            ? \sprintf(" - Bis zu %s%% Rabatt", $pct)
            : \sprintf(" - Up to %s%% Discount", $pct);
    }

    /**
     * Description text for the current cashback data, regardless of the
     * display settings. Also used by the admin preview.
     */
    public function previewDescription(bool $german): string
    {
        $cashback = $this->config->getCashback();

        return $this->buildDescription(
            (float) ($cashback["first_purchase_amount"] ?? 0),
            (float) ($cashback["standard_amount"] ?? 0),
            $german,
        );
    }

    private function buildDescription(
        float $first,
        float $standard,
        bool $german,
    ): string {
        if ($first > 0 && $standard > 0) {
            return $german
                ? \sprintf(
                    "Sichere dir %s%% Rabatt auf deine erste und %s%% auf jede weitere Zahlung mit FLIZpay.",
                    $this->formatPercent($first, true),
                    $this->formatPercent($standard, true),
                )
                : \sprintf(
                    "Get %s%% off your first payment and %s%% off every payment after that with FLIZpay.",
                    $this->formatPercent($first, false),
                    $this->formatPercent($standard, false),
                );
        }
        if ($first > 0) {
            return $german
                ? \sprintf(
                    "Sichere dir %s%% Rabatt auf deine erste Zahlung mit FLIZpay.",
                    $this->formatPercent($first, true),
                )
                : \sprintf(
                    "Get %s%% off your first payment with FLIZpay.",
                    $this->formatPercent($first, false),
                );
        }
        if ($standard > 0) {
            return $german
                ? \sprintf(
                    "Sichere dir %s%% Rabatt mit FLIZpay.",
                    $this->formatPercent($standard, true),
                )
                : \sprintf(
                    "Get %s%% off with FLIZpay.",
                    $this->formatPercent($standard, false),
                );
        }

        return $german
            ? "Sichere Zahlungen in direkter Zusammenarbeit mit deiner Bank."
            : "Secure payments in direct collaboration with your bank.";
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

    private function formatPercent(float $value, bool $german): string
    {
        $formatted = \rtrim(
            \rtrim(\number_format($value, 2, ".", ""), "0"),
            ".",
        );

        return $german ? \str_replace(".", ",", $formatted) : $formatted;
    }
}
