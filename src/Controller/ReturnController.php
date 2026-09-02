<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Controller;

use JTL\Shop;
use JTL\Session\Frontend;
use JTL\Smarty\JTLSmarty;
use Laminas\Diactoros\Response\RedirectResponse;
use Plugin\flizpay\src\FlizPlugin;
use Plugin\flizpay\src\Service\OrderService;
use Plugin\flizpay\src\Service\TransactionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /flizpay/return?ph=<order hash>
 *
 * The FLIZpay successUrl target. Works sessionless (the customer may return in
 * a different browser after paying in the FLIZpay app). It reads only local
 * state: paid customers go to the confirmation page, otherwise an interstitial
 * polls /flizpay/status until the signed webhook lands.
 */
class ReturnController
{
    public static function handle(
        ServerRequestInterface $request,
        array $args,
        JTLSmarty $smarty,
    ): ResponseInterface {
        $ph = \trim((string) ($request->getQueryParams()["ph"] ?? ""));
        $kBestellung = self::resolveOrderId($ph);
        if ($kBestellung === 0) {
            return new RedirectResponse(Shop::getURL() . "/");
        }

        $repository = new TransactionRepository();
        $orderService = new OrderService(null, $repository);
        $orderData = $orderService->getOrderData($kBestellung);
        if ($orderData === null || !$orderService->isFlizPayOrder($orderData)) {
            return new RedirectResponse(Shop::getURL() . "/");
        }

        $state = self::paymentState($kBestellung, $repository, $orderService);
        if ($state === "completed") {
            self::cleanUpPurchasedCart(
                (int) $orderData->kWarenkorb,
                (string) $orderData->cSession,
            );

            return new RedirectResponse(
                self::successTarget($kBestellung, $orderService),
            );
        }

        return self::renderInterstitial(
            $smarty,
            $ph,
            $kBestellung,
            $state,
            $orderService,
        );
    }

    public static function resolveOrderId(string $ph): int
    {
        if ($ph === "" || \strlen($ph) > 191) {
            return 0;
        }
        $row = FlizPlugin::getDB()->getSingleObject(
            "SELECT kBestellung FROM tbestellid WHERE cId = :ph",
            ["ph" => $ph],
        );

        return (int) ($row->kBestellung ?? 0);
    }

    /**
     * @return string 'completed' | 'failed' | 'pending'
     */
    public static function paymentState(
        int $kBestellung,
        TransactionRepository $repository,
        OrderService $orderService,
    ): string {
        $orderData = $orderService->getOrderData($kBestellung);
        if ($orderData !== null && $orderData->dBezahltDatum !== null) {
            return "completed";
        }
        // the latest transaction, not the current-attempt one: settling a
        // failure advances the order's attempt counter, so the transaction that
        // just failed is no longer the current attempt
        $tx = $repository->getLatestTransaction($kBestellung);
        if (
            $tx !== null &&
            \in_array((string) $tx->cStatus, ["failed", "canceled"], true)
        ) {
            return "failed";
        }

        return "pending";
    }

    public static function shouldCleanUpCart(
        int $orderCartId,
        int $sessionCartId,
        string $orderSessionId,
        string $sessionId,
    ): bool {
        if ($orderCartId <= 0) {
            return false;
        }
        if ($sessionCartId > 0) {
            return $orderCartId === $sessionCartId;
        }

        return $orderSessionId !== "" && $orderSessionId === $sessionId;
    }

    public static function cleanUpPurchasedCart(
        int $orderCartId,
        string $orderSessionId,
    ): void {
        try {
            $sessionCartId = (int) (Frontend::getCart()->kWarenkorb ?? 0);
            $sessionId = \session_id();
            $cleanUp = self::shouldCleanUpCart(
                $orderCartId,
                $sessionCartId,
                $orderSessionId,
                $sessionId,
            );
            FlizPlugin::debug("return cart cleanup", [
                "orderCart" => $orderCartId,
                "sessionCart" => $sessionCartId,
                "sameSession" =>
                    $orderSessionId !== "" && $orderSessionId === $sessionId,
                "cleaned" => $cleanUp,
            ]);
            if ($cleanUp) {
                Frontend::getInstance()->cleanUp();
            }
        } catch (\Throwable $e) {
            FlizPlugin::log("return cart cleanup failed", \LOGLEVEL_ERROR, [
                "cart" => $orderCartId,
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirmation target for a paid order — the payment method's own
     * getReturnURL (Abschlussseite when configured, order-status page
     * otherwise; both work sessionless).
     */
    public static function successTarget(
        int $kBestellung,
        ?OrderService $orderService = null,
    ): string {
        try {
            $hash =
                FlizPlugin::getDB()->getSingleObject(
                    "SELECT cId FROM tbestellid WHERE kBestellung = :oid",
                    ["oid" => $kBestellung],
                )->cId ?? "";
            $url = self::completionUrl(
                Shop::Container()
                    ->getLinkService()
                    ->getStaticRoute("bestellabschluss.php"),
                (string) $hash,
            );
            if ($url !== null) {
                return $url;
            }
        } catch (\Throwable $e) {
            FlizPlugin::log("successTarget failed", \LOGLEVEL_ERROR, [
                "order" => $kBestellung,
                "error" => $e->getMessage(),
            ]);
        }

        return self::orderStatusUrl($kBestellung, $orderService);
    }

    public static function completionUrl(string $route, string $hash): ?string
    {
        if ($route === "" || $hash === "") {
            return null;
        }

        return $route . "?i=" . \rawurlencode($hash);
    }

    /**
     * Order-status page (carries the "pay again" button for retries).
     */
    public static function orderStatusUrl(
        int $kBestellung,
        ?OrderService $orderService = null,
    ): string {
        try {
            $orderService ??= new OrderService();
            $order = $orderService->loadOrder($kBestellung);
            $url = $order->BestellstatusURL ?? "";
            if (\is_string($url) && $url !== "") {
                return $url;
            }
        } catch (\Throwable) {
        }

        try {
            return Shop::Container()
                ->getLinkService()
                ->getStaticRoute("jtl.php") . "?bestellungen=1";
        } catch (\Throwable) {
            return Shop::getURL() . "/";
        }
    }

    private static function renderInterstitial(
        JTLSmarty $smarty,
        string $ph,
        int $kBestellung,
        string $state,
        OrderService $orderService,
    ): ResponseInterface {
        $template =
            \dirname(__DIR__, 2) . "/frontend/template/return_polling.tpl";
        return $smarty
            ->assign("flizState", $state)
            ->assign(
                "flizPollUrl",
                Shop::getURL() . "/flizpay/status?ph=" . \rawurlencode($ph),
            )
            ->assign(
                "flizStatusUrl",
                self::orderStatusUrl($kBestellung, $orderService),
            )
            ->assign("flizLang", [
                "processingHeading" => \d__(
                    "flizpay",
                    "Confirming your payment ...",
                ),
                "processingText" => \d__(
                    "flizpay",
                    "You will be redirected automatically once your payment is confirmed.",
                ),
                "processingSlow" => \d__(
                    "flizpay",
                    "Confirmation is taking longer than usual. You can check the status in your order overview at any time.",
                ),
                "failedHeading" => \d__("flizpay", "Payment not completed"),
                "failedText" => \d__(
                    "flizpay",
                    "Your FLIZpay payment was not completed. Nothing was charged.",
                ),
                "toOrderStatus" => \d__("flizpay", "Go to order status"),
            ])
            ->getResponse($template);
    }
}
