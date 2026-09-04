<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Api;

use Plugin\flizpay\src\FlizPlugin;
use Plugin\flizpay\src\Service\ConfigService;

class FlizPayService
{
    private FlizPayClient $client;
    private ConfigService $config;

    public function __construct(?string $apiKey = null)
    {
        $this->config = new ConfigService();
        $this->client = new FlizPayClient(
            $apiKey ?? $this->config->getApiKey(),
        );
    }

    /**
     * @return array{transactionId:string, reference:string, redirectUrl:string}|null
     */
    public function createTransaction(
        array $payload,
        string $idempotencyKey,
    ): ?array {
        $res = $this->client->request("POST", "/transactions", $payload, [
            "Idempotency-Key" => $idempotencyKey,
        ]);
        $tx = $res["data"];
        if ($res["status"] < 200 || $res["status"] >= 300 || !\is_array($tx)) {
            FlizPlugin::log("createTransaction failed", \LOGLEVEL_ERROR, [
                "http" => $res["status"],
                "error" => $res["error"],
                "jsonError" => $res["jsonError"],
            ]);

            return null;
        }
        foreach (["transactionId", "reference", "redirectUrl"] as $field) {
            if (empty($tx[$field]) || !\is_string($tx[$field])) {
                FlizPlugin::log(
                    "createTransaction: incomplete response",
                    \LOGLEVEL_ERROR,
                    ["missing" => $field],
                );

                return null;
            }
        }

        return [
            "transactionId" => $tx["transactionId"],
            "reference" => $tx["reference"],
            "redirectUrl" => $tx["redirectUrl"],
        ];
    }

    /**
     * `transport` distinguishes "we could not reach FLIZpay" from "FLIZpay
     * rejected us" — the caller must not discard a merchant's API key over a
     * network blip.
     *
     * @return array{ok: bool, transport: bool, key: ?string}
     */
    public function generateWebhookKey(): array
    {
        $res = $this->client->request("GET", "/business/generate-webhook-key");
        if ($res["status"] === 0) {
            return ["ok" => false, "transport" => true, "key" => null];
        }
        $key = $res["data"]["webhookKey"] ?? null;
        $ok =
            \is_string($key) &&
            \strlen($key) >= 32 &&
            $res["status"] >= 200 &&
            $res["status"] < 300;
        if (!$ok) {
            FlizPlugin::debug("generateWebhookKey rejected", [
                "http" => $res["status"],
                "keyLen" => \is_string($key) ? \strlen($key) : null,
            ]);
        }

        return ["ok" => $ok, "transport" => false, "key" => $ok ? $key : null];
    }

    /**
     * Registers the shop's webhook URL; the API echoes the stored value back
     * and the echo must match exactly.
     *
     * @return array{ok: bool, transport: bool}
     */
    public function registerWebhookUrl(string $webhookUrl): array
    {
        $res = $this->client->request("POST", "/business/edit", [
            "webhookUrl" => $webhookUrl,
        ]);
        if ($res["status"] === 0) {
            return ["ok" => false, "transport" => true];
        }
        $echo = $res["data"]["webhookUrl"] ?? null;
        $ok =
            $res["status"] >= 200 &&
            $res["status"] < 300 &&
            \is_string($echo) &&
            \strcmp($echo, $webhookUrl) === 0;
        if (!$ok) {
            FlizPlugin::debug("registerWebhookUrl rejected", [
                "http" => $res["status"],
                "echoMatch" =>
                    \is_string($echo) && \strcmp($echo, $webhookUrl) === 0,
                "url" => $webhookUrl,
            ]);
        }

        return ["ok" => $ok, "transport" => false];
    }

    /**
     * @return array{first_purchase_amount: float, standard_amount: float}|null
     *         null = no active cashback (or unreadable response)
     */
    public function fetchCashback(): ?array
    {
        $res = $this->client->request("GET", "/business/cashback");
        if (
            $res["status"] < 200 ||
            $res["status"] >= 300 ||
            !\is_array($res["data"])
        ) {
            FlizPlugin::debug("fetchCashback: unreadable response", [
                "http" => $res["status"],
            ]);

            return null;
        }

        return self::normalizeCashback($res["data"]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{first_purchase_amount: float, standard_amount: float}|null
     */
    public static function normalizeCashback(array $data): ?array
    {
        $cashback = $data["cashback"] ?? null;
        if (\is_array($cashback)) {
            return self::normalizeCashbackEntry($cashback);
        }

        // Older API versions returned a list of campaigns.
        $cashbacks = $data["cashbacks"] ?? null;
        if (!\is_array($cashbacks)) {
            return null;
        }
        foreach ($cashbacks as $cashback) {
            if (!\is_array($cashback) || empty($cashback["active"])) {
                continue;
            }
            $normalized = self::normalizeCashbackEntry($cashback);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cashback
     * @return array{first_purchase_amount: float, standard_amount: float}|null
     */
    private static function normalizeCashbackEntry(array $cashback): ?array
    {
        $first = $cashback["firstPurchaseAmount"] ?? null;
        $standard = $cashback["amount"] ?? null;
        if (!\is_numeric($first) || !\is_numeric($standard)) {
            return null;
        }

        $first = (float) $first;
        $standard = (float) $standard;
        if (
            !\is_finite($first) ||
            !\is_finite($standard) ||
            $first < 0 ||
            $standard < 0
        ) {
            return null;
        }

        return [
            "first_purchase_amount" => $first,
            "standard_amount" => $standard,
        ];
    }

    /**
     * @return bool success
     */
    public function editBusiness(array $fields): bool
    {
        $res = $this->client->request("POST", "/business/edit", $fields);
        $ok = $res["status"] >= 200 && $res["status"] < 300;
        if (!$ok) {
            FlizPlugin::debug("editBusiness rejected", [
                "http" => $res["status"],
                "fields" => \implode(",", \array_keys($fields)),
            ]);
        }

        return $ok;
    }

    public function reportLifecycle(bool $isActive): bool
    {
        $fields = ["isActive" => $isActive];
        if ($isActive) {
            $fields["pluginVersion"] = FlizPlugin::getVersion();
        }

        return $this->editBusiness($fields);
    }

    public function reportVersion(): bool
    {
        return $this->editBusiness([
            "pluginVersion" => FlizPlugin::getVersion(),
        ]);
    }

    public function deregisterWebhook(): bool
    {
        return $this->editBusiness(["webhookUrl" => ""]);
    }
}
