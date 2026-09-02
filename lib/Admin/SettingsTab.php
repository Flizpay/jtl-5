<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Admin;

use JTL\DB\DbInterface;
use JTL\Helpers\Form;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\CashbackService;
use Plugin\flizpay\lib\Service\ConfigService;
use Plugin\flizpay\lib\Service\ConnectionService;
use Plugin\flizpay\lib\Service\Logger;

/**
 * The "Settings" tab owns the whole settings lifecycle: it saves the merchant
 * settings into tplugineinstellungen itself
 *
 * While a fresh handshake is waiting for FLIZpay's test webhook the template
 * reloads every few seconds, so the merchant sees the connection turn green
 * without touching anything.
 */
class SettingsTab
{
    private const HANDSHAKE_WAIT_SECONDS = 90;

    /** ValueName => default of every merchant setting owned by this form. */
    private const SETTINGS = [
        "flizpay_apiKey" => "",
        "flizpay_displayLogo" => "Y",
        "flizpay_displayDescription" => "Y",
        "flizpay_debugMode" => "N",
    ];

    private ConfigService $config;

    public function __construct(
        private readonly DbInterface $db,
        private readonly ?PluginInterface $plugin,
    ) {
        $this->config = new ConfigService($this->db);
    }

    public function render(JTLSmarty $smarty): string
    {
        $messages = $this->handleAction();
        $cashbackService = new CashbackService($this->db, $this->config);

        $apiKey = $this->config->getApiKey();
        $handshakeAt = $this->config->get(ConfigService::KEY_HANDSHAKE_AT);
        $awaitingTest =
            !$this->config->isWebhookAlive() &&
            $apiKey !== "" &&
            $handshakeAt !== null &&
            \time() - \strtotime($handshakeAt) < self::HANDSHAKE_WAIT_SECONDS;

        return $smarty
            ->assign("flizMessages", $messages)
            ->assign("flizTokenInput", Form::getTokenInput())
            ->assign("flizConnected", $this->config->isConnected())
            ->assign("flizApiKeyMask", self::maskApiKey($apiKey))
            ->assign("flizApiKeySet", $apiKey !== "")
            ->assign(
                "flizWebhookKeySet",
                \strlen($this->config->getWebhookKey()) >= 32,
            )
            ->assign("flizWebhookAlive", $this->config->isWebhookAlive())
            ->assign("flizDisplayLogo", $this->config->displayLogo())
            ->assign(
                "flizDisplayDescription",
                $this->config->displayDescription(),
            )
            ->assign("flizDebugMode", $this->config->debugMode())
            ->assign("flizLogFile", Logger::getFilePath())
            ->assign("flizPaymentLogUrl", $this->getPaymentLogUrl())
            ->assign("flizLogoUrl", $this->getLogoUrl())
            ->assign("flizAdminCssUrl", $this->getAdminCssUrl())
            ->assign(
                "flizPreviewTitleSuffix",
                $cashbackService->previewTitleSuffix($this->isGermanAdmin()),
            )
            ->assign(
                "flizPreviewSubtitle",
                $cashbackService->previewDescription($this->isGermanAdmin()),
            )
            ->assign("flizAwaitingTest", $awaitingTest)
            ->fetch($this->getTemplatePath());
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    private function handleAction(): array
    {
        $action = (string) ($_POST["flizAction"] ?? "");
        if ($action === "") {
            return [];
        }
        if (!Form::validateToken()) {
            return [
                [
                    "type" => "danger",
                    "text" => \d__(
                        "flizpay",
                        "Security check failed. Please reload the page.",
                    ),
                ],
            ];
        }
        if ($action !== "save") {
            return [];
        }

        try {
            return $this->saveSettings();
        } catch (\Throwable $e) {
            return [
                [
                    "type" => "danger",
                    "text" => \sprintf(
                        \d__("flizpay", "Action failed: %s"),
                        $e->getMessage(),
                    ),
                ],
            ];
        }
    }

    /**
     * Persists the posted settings and replicates the former
     * HOOK_PLUGIN_SAVE_OPTIONS flow (presentation sync + handshake).
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function saveSettings(): array
    {
        foreach (self::SETTINGS as $name => $default) {
            $value = $_POST[$name] ?? $default;
            if ($name === "flizpay_apiKey") {
                $value = \trim((string) $value);
                $stored = $this->config->getApiKey();
                if ($value === self::maskApiKey($stored)) {
                    $value = $stored;
                }
            } else {
                // The remaining settings are Y/N selects.
                $value = (string) $value === "N" ? "N" : "Y";
            }
            // tplugineinstellungen has no unique key on (kPlugin, cName),
            // so an upsert would silently pile up duplicate rows.
            $this->db->queryPrepared(
                'DELETE FROM tplugineinstellungen WHERE kPlugin = :pid AND cName = :name',
                ["pid" => FlizPlugin::getKPlugin(), "name" => $name],
            );
            $this->db->queryPrepared(
                'INSERT INTO tplugineinstellungen (kPlugin, cName, cWert)
                    VALUES (:pid, :name, :val)',
                [
                    "pid" => FlizPlugin::getKPlugin(),
                    "name" => $name,
                    "val" => $value,
                ],
            );
        }
        // Drop settings owned by older plugin versions (e.g. the removed
        // headline toggle) so no stale rows linger.
        $names = \array_keys(self::SETTINGS);
        $this->db->queryPrepared(
            "DELETE FROM tplugineinstellungen
                WHERE kPlugin = :pid
                  AND cName LIKE 'flizpay\\_%'
                  AND cName NOT IN (" .
                \implode(",", \array_map(static fn($i) => ":n$i", \array_keys($names))) .
            ")",
            \array_merge(
                ["pid" => FlizPlugin::getKPlugin()],
                \array_combine(
                    \array_map(static fn($i) => "n$i", \array_keys($names)),
                    $names,
                ),
            ),
        );
        $this->flushPluginCache();
        // The debug flag is memoised per request; re-read it so the
        // handshake below already logs at the freshly saved verbosity.
        Logger::setDebugEnabled(null);

        $messages = [
            ["type" => "success", "text" => \d__("flizpay", "Settings saved.")],
        ];
        $cashbackService = new CashbackService($this->db, $this->config);
        $cashbackService->syncPresentation();

        $connectionService = new ConnectionService($this->config);
        $result = $connectionService->onSettingsSaved(
            $this->config->getApiKey(),
        );
        if ($result["message"] !== "") {
            $messages[] = [
                "type" => $result["success"] ? "info" : "danger",
                "text" => $result["message"],
            ];
        }

        return $messages;
    }

    /**
     * Masked value for the API key field, e.g. "••••…••••3e18". The mask is
     * as long as the stored key; only the last four characters are revealed
     * so the merchant can tell which key is stored without the full key
     * ever reaching the browser.
     */
    private static function maskApiKey(string $apiKey): string
    {
        if ($apiKey === "") {
            return "";
        }
        $suffix = \strlen($apiKey) > 8 ? \substr($apiKey, -4) : "";

        return \str_repeat("\u{2022}", \strlen($apiKey) - \strlen($suffix)) .
            $suffix;
    }

    private function flushPluginCache(): void
    {
        try {
            Shop::Container()
                ->getCache()
                ->flushTags([
                    \CACHING_GROUP_PLUGIN,
                    \CACHING_GROUP_PLUGIN . "_" . FlizPlugin::getKPlugin(),
                ]);
        } catch (\Throwable) {
        }
    }

    /**
     * The preview mirrors what CashbackService writes for the shop languages;
     * the variant shown in the admin follows the backend user's language.
     */
    private function isGermanAdmin(): bool
    {
        $language = (string) ($_SESSION["AdminAccount"]->language ?? "de-DE");

        return \str_starts_with(\strtolower($language), "de");
    }

    /**
     * URL of the checkout logo badge for the admin preview.
     */
    private function getLogoUrl(): string
    {
        if ($this->plugin !== null) {
            return $this->plugin->getPaths()->getBaseURL() .
                "paymentmethod/flizpay-logo.svg";
        }

        return "";
    }

    /**
     * URL of the admin stylesheet, versioned so browsers pick up changes
     * after a plugin update.
     */
    private function getAdminCssUrl(): string
    {
        if ($this->plugin === null) {
            return "";
        }
        try {
            $version = (string) $this->plugin->getMeta()->getVersion();
        } catch (\Throwable) {
            $version = "";
        }

        return $this->plugin->getPaths()->getBaseURL() .
            "adminmenu/css/flizpay-admin.css" .
            ($version !== "" ? "?v=" . \rawurlencode($version) : "");
    }

    /**
     * Backend log view of the FLIZpay payment method (notice/error entries).
     * The controller requires the CSRF token as a query parameter.
     */
    private function getPaymentLogUrl(): string
    {
        $methodId = FlizPlugin::getPaymentMethodId();
        $token = (string) ($_SESSION["jtl_token"] ?? "");
        if ($methodId <= 0 || $token === "") {
            return "";
        }
        $route = \defined('JTL\Router\Route::PAYMENT_METHODS')
            ? \JTL\Router\Route::PAYMENT_METHODS
            : "paymentmethods";

        return \rtrim(Shop::getAdminURL(), "/") .
            "/" .
            $route .
            "?a=log&kZahlungsart=" .
            $methodId .
            "&token=" .
            \rawurlencode($token);
    }

    private function getTemplatePath(): string
    {
        $base =
            $this->plugin !== null
                ? $this->plugin->getPaths()->getAdminPath()
                : \dirname(__DIR__, 2) . "/adminmenu/";

        return \rtrim($base, "/") . "/templates/settings.tpl";
    }
}
