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
use Plugin\flizpay\lib\Service\TransactionRepository;

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
        "flizpay_displayHeadline" => "Y",
        "flizpay_displayDescription" => "Y",
    ];

    private ConfigService $config;

    private TransactionRepository $repository;

    public function __construct(
        private readonly DbInterface $db,
        private readonly ?PluginInterface $plugin,
    ) {
        $this->config = new ConfigService($this->db);
        $this->repository = new TransactionRepository($this->db);
    }

    public function render(JTLSmarty $smarty): string
    {
        $messages = $this->handleAction();

        $handshakeAt = $this->config->get(ConfigService::KEY_HANDSHAKE_AT);
        $awaitingTest =
            !$this->config->isWebhookAlive() &&
            $this->config->getApiKey() !== "" &&
            $handshakeAt !== null &&
            \time() - \strtotime($handshakeAt) < self::HANDSHAKE_WAIT_SECONDS;

        return $smarty
            ->assign("flizMessages", $messages)
            ->assign("flizTokenInput", Form::getTokenInput())
            ->assign("flizConnected", $this->config->isConnected())
            ->assign("flizApiKey", $this->config->getApiKey())
            ->assign("flizApiKeySet", $this->config->getApiKey() !== "")
            ->assign(
                "flizWebhookKeySet",
                \strlen($this->config->getWebhookKey()) >= 32,
            )
            ->assign("flizWebhookAlive", $this->config->isWebhookAlive())
            ->assign("flizDisplayLogo", $this->config->displayLogo())
            ->assign("flizDisplayHeadline", $this->config->displayHeadline())
            ->assign(
                "flizDisplayDescription",
                $this->config->displayDescription(),
            )
            ->assign("flizAwaitingTest", $awaitingTest)
            ->assign("flizOpenPayments", $this->repository->listOpenForAdmin())
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
            } else {
                // The remaining settings are Y/N selects.
                $value = (string) $value === "N" ? "N" : "Y";
            }
            $this->db->queryPrepared(
                'INSERT INTO tplugineinstellungen (kPlugin, cName, cWert)
                    VALUES (:pid, :name, :val)
                    ON DUPLICATE KEY UPDATE cWert = :val',
                [
                    "pid" => FlizPlugin::getKPlugin(),
                    "name" => $name,
                    "val" => $value,
                ],
            );
        }
        $this->flushPluginCache();

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

    private function getTemplatePath(): string
    {
        $base =
            $this->plugin !== null
                ? $this->plugin->getPaths()->getAdminPath()
                : \dirname(__DIR__, 2) . "/adminmenu/";

        return \rtrim($base, "/") . "/templates/settings.tpl";
    }
}
