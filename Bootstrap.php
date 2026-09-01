<?php

declare(strict_types=1);

namespace Plugin\flizpay;

use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\BootstrapperInterface;
use JTL\Router\Router;
use JTL\Smarty\JTLSmarty;
use Plugin\flizpay\lib\Api\FlizPayService;
use Plugin\flizpay\lib\Controller\ReturnController;
use Plugin\flizpay\lib\Controller\StatusController;
use Plugin\flizpay\lib\Controller\WebhookController;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\CashbackService;
use Plugin\flizpay\lib\Service\ConfigService;

class Bootstrap extends Bootstrapper implements BootstrapperInterface
{
    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);

        // Webhook, customer return and status polling endpoints.
        $dispatcher->listen(
            "shop.hook." . \HOOK_ROUTER_PRE_DISPATCH,
            static function (array $args): void {
                /** @var Router $router */
                $router = $args["router"] ?? null;
                if ($router === null) {
                    return;
                }
                $router->addRoute(
                    "/flizpay/webhook",
                    [WebhookController::class, "handle"],
                    "flizpayWebhook",
                    ["POST"],
                );
                $router->addRoute(
                    "/flizpay/return",
                    [ReturnController::class, "handle"],
                    "flizpayReturn",
                    ["GET"],
                );
                $router->addRoute(
                    "/flizpay/status",
                    [StatusController::class, "handle"],
                    "flizpayStatus",
                    ["GET"],
                );
            },
        );

        // Hold new FLIZpay orders back from JTL-Wawi until payment settles, so
        // unpaid orders never reach Wawi and a cashback discount can still be
        // written into the order. Released in OrderService::releaseWawiHold().
        $dispatcher->listen(
            "shop.hook." . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB,
            static function (array $args): void {
                $order = $args["oBestellung"] ?? null;
                if (!\is_object($order)) {
                    return;
                }
                $methodID = FlizPlugin::getPaymentMethodId();
                if (
                    $methodID > 0 &&
                    (int) ($order->kZahlungsart ?? 0) === $methodID
                ) {
                    $order->cAbgeholt = "Y";
                }
            },
        );

        // Settings are saved by the custom admin tab (lib/Admin/SettingsTab),
        // which also runs the onboarding handshake — no HOOK_PLUGIN_SAVE_OPTIONS.
    }

    public function installed()
    {
        parent::installed();
        (new CashbackService($this->getDB()))->syncPresentation();
    }

    public function enabled()
    {
        parent::enabled();
        $this->reportLifecycle(true);
    }

    public function disabled()
    {
        parent::disabled();
        $this->reportLifecycle(false);
    }

    public function updated($oldVersion, $newVersion)
    {
        parent::updated($oldVersion, $newVersion);
        $config = new ConfigService($this->getDB());
        if ($config->getApiKey() === "") {
            return;
        }
        // Report the running plugin version on update.
        if ((new FlizPayService($config->getApiKey()))->reportVersion()) {
            $config->set(
                ConfigService::KEY_REPORTED_VERSION,
                FlizPlugin::getVersion(),
            );
        }
    }

    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);

        $config = new ConfigService($this->getDB());
        $apiKey = $config->getApiKey();
        if ($apiKey !== "") {
            // Best effort: stop FLIZpay from sending webhooks to a shop that no
            // longer has the plugin installed.
            $api = new FlizPayService($apiKey);
            $api->reportLifecycle(false);
            $api->deregisterWebhook();
        }
    }

    public function renderAdminMenuTab(
        string $tabName,
        int $menuID,
        JTLSmarty $smarty,
    ): string {
        return (new lib\Admin\SettingsTab(
            $this->getDB(),
            $this->getPlugin(),
        ))->render($smarty);
    }

    private function reportLifecycle(bool $isActive): void
    {
        $config = new ConfigService($this->getDB());
        $apiKey = $config->getApiKey();
        if ($apiKey === "") {
            return;
        }
        try {
            (new FlizPayService($apiKey))->reportLifecycle($isActive);
        } catch (\Throwable $e) {
            FlizPlugin::log("lifecycle report failed", \LOGLEVEL_ERROR, [
                "error" => $e->getMessage(),
            ]);
        }
    }
}
