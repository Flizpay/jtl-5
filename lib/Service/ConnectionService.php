<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use JTL\Shop;
use Plugin\flizpay\lib\Api\FlizPayService;
use Plugin\flizpay\lib\FlizPlugin;

/**
 * The onboarding handshake:
 *
 *   1. register the shop's webhook URL at FLIZpay (echo-verified)
 *   2. fetch the HMAC webhook key
 *   3. fetch the cashback configuration
 *
 * Any failure wipes the API key so misconfiguration is immediately visible.
 * FLIZpay then POSTs a {"test": ...} webhook out-of-band; receiving it flips
 * the webhook-alive flag and only then does the method become selectable.
 */
class ConnectionService
{
    /** How long a completed handshake may wait for the inbound test webhook */
    private const HANDSHAKE_GRACE_SECONDS = 600;

    private ConfigService $config;

    private CashbackService $cashback;

    public function __construct(
        ?ConfigService $config = null,
        ?CashbackService $cashback = null,
    ) {
        $this->config = $config ?? new ConfigService();
        $this->cashback = $cashback ?? new CashbackService(null, $this->config);
    }

    public static function getWebhookUrl(): string
    {
        $url = \rtrim(Shop::getURL(), "/") . "/flizpay/webhook";
        if (\stripos($url, "http") !== 0) {
            $url = "https://" . \ltrim($url, "/");
        }

        return $url;
    }

    /**
     * Called from the admin Settings tab with the freshly saved API key.
     *
     * @return array{success: bool, ran: bool, message: string}
     */
    public function onSettingsSaved(string $apiKey): array
    {
        $apiKey = \trim($apiKey);
        if ($apiKey === "") {
            FlizPlugin::debug("settings saved: no API key, handshake skipped");
            $this->config->setWebhookAlive(false);
            $this->config->set(ConfigService::KEY_API_KEY_HASH, "");

            return [
                "success" => false,
                "ran" => false,
                "message" => \d__(
                    "flizpay",
                    "No API key is configured. FLIZpay is disabled at checkout.",
                ),
            ];
        }

        $keyHash = \hash("sha256", $apiKey);
        $urlUnchanged =
            $this->config->get(ConfigService::KEY_WEBHOOK_URL) ===
            self::getWebhookUrl();
        if (
            $urlUnchanged &&
            $this->config->get(ConfigService::KEY_API_KEY_HASH) === $keyHash
        ) {
            if ($this->config->isConnected()) {
                FlizPlugin::debug(
                    "settings saved: unchanged and connected, handshake skipped",
                );

                return ["success" => true, "ran" => false, "message" => ""];
            }
            // Saving other settings while the test webhook is still on its way
            // must not mint a new webhook key — that would invalidate the
            // signature of the notification already in flight.
            $handshakeAt = $this->config->get(ConfigService::KEY_HANDSHAKE_AT);
            if (
                $handshakeAt !== null &&
                \time() - (int) \strtotime($handshakeAt) <
                    self::HANDSHAKE_GRACE_SECONDS
            ) {
                FlizPlugin::debug(
                    "settings saved: test webhook pending, handshake skipped",
                    [
                        "handshakeAt" => $handshakeAt,
                    ],
                );

                return [
                    "success" => true,
                    "ran" => false,
                    "message" => \d__(
                        "flizpay",
                        "FLIZpay has already been configured. The test notification is still pending. Progress is shown below the API key.",
                    ),
                ];
            }
        }

        return $this->runHandshake($apiKey, $keyHash);
    }

    /**
     * @return array{success: bool, ran: bool, message: string}
     */
    public function runHandshake(string $apiKey, ?string $keyHash = null): array
    {
        $this->config->setWebhookAlive(false);
        $api = new FlizPayService($apiKey);
        $webhookUrl = self::getWebhookUrl();
        FlizPlugin::debug("handshake started", ["url" => $webhookUrl]);

        $registration = $api->registerWebhookUrl($webhookUrl);
        if (!$registration["ok"]) {
            return $this->handshakeFailed(
                \d__("flizpay", "The webhook URL could not be registered."),
                $registration["transport"],
                ["url" => $webhookUrl],
            );
        }

        $keyResult = $api->generateWebhookKey();
        if (!$keyResult["ok"]) {
            return $this->handshakeFailed(
                \d__("flizpay", "A webhook key could not be generated."),
                $keyResult["transport"],
            );
        }

        $this->config->set(ConfigService::KEY_WEBHOOK_KEY, $keyResult["key"]);
        $this->config->set(ConfigService::KEY_WEBHOOK_URL, $webhookUrl);
        $this->config->set(
            ConfigService::KEY_API_KEY_HASH,
            $keyHash ?? \hash("sha256", $apiKey),
        );
        $this->config->set(
            ConfigService::KEY_HANDSHAKE_AT,
            \date("Y-m-d H:i:s"),
        );

        // Cashback is optional — a missing configuration must not fail the handshake.
        $this->cashback->update($api->fetchCashback());

        FlizPlugin::log(
            "handshake completed, waiting for test webhook",
            \LOGLEVEL_NOTICE,
            ["url" => $webhookUrl],
        );

        return [
            "success" => true,
            "ran" => true,
            "message" => \sprintf(
                \d__(
                    "flizpay",
                    "FLIZpay connection established. FLIZpay is now sending a test notification to %s. Its status is shown below the API key. The payment method appears at checkout only after a successful test.",
                ),
                $webhookUrl,
            ),
        ];
    }

    /**
     * A rejected key is wiped so the misconfiguration is obvious; an
     * unreachable API is not — the key is probably fine and the merchant would
     * otherwise have to dig it out again after every network hiccup.
     *
     * @return array{success: bool, ran: bool, message: string}
     */
    private function handshakeFailed(
        string $reason,
        bool $transport,
        array $context = [],
    ): array {
        FlizPlugin::log(
            "handshake failed: " . $reason,
            \LOGLEVEL_ERROR,
            $context + ["transport" => $transport],
        );

        if ($transport) {
            return [
                "success" => false,
                "ran" => true,
                "message" => \sprintf(
                    \d__(
                        "flizpay",
                        "FLIZpay is currently unavailable: %s The API key remains saved. Please save the settings again later.",
                    ),
                    $reason,
                ),
            ];
        }

        $this->config->wipeApiKey();

        return [
            "success" => false,
            "ran" => true,
            "message" => \sprintf(
                \d__(
                    "flizpay",
                    "FLIZpay connection failed: %s The API key was removed. Please check it and save it again.",
                ),
                $reason,
            ),
        ];
    }
}
