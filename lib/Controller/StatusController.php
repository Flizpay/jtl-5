<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Controller;

use JTL\Smarty\JTLSmarty;
use Laminas\Diactoros\Response\JsonResponse;
use Plugin\flizpay\lib\Service\OrderService;
use Plugin\flizpay\lib\Service\TransactionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /flizpay/status?ph=<order hash>
 *
 * Cheap JSON state for the return-page poller. Reads only the local DB (no
 * FLIZpay API call — only the signed webhook writes payment state).
 * A paid order additionally reports where to go next. Success is defined as
 * "the order is paid", not a single status string (fixes the WooCommerce
 * plugin's only-'processing'-counts bug).
 */
class StatusController
{
    public static function handle(
        ServerRequestInterface $request,
        array $args,
        JTLSmarty $smarty,
    ): ResponseInterface {
        $ph = \trim((string) ($request->getQueryParams()["ph"] ?? ""));
        $kBestellung = ReturnController::resolveOrderId($ph);
        $headers = ["Cache-Control" => "no-store"];
        if ($kBestellung === 0) {
            return new JsonResponse(["state" => "unknown"], 404, $headers);
        }

        $repository = new TransactionRepository();
        $orderService = new OrderService(null, $repository);
        $orderData = $orderService->getOrderData($kBestellung);
        if ($orderData === null || !$orderService->isFlizPayOrder($orderData)) {
            return new JsonResponse(["state" => "unknown"], 404, $headers);
        }
        $state = ReturnController::paymentState(
            $kBestellung,
            $repository,
            $orderService,
        );
        $payload = ["state" => $state];
        if ($state === "completed") {
            $payload["redirectUrl"] = ReturnController::successTarget(
                $kBestellung,
                $orderService,
            );
        } elseif ($state === "failed") {
            $payload["statusUrl"] = ReturnController::orderStatusUrl(
                $kBestellung,
                $orderService,
            );
        }

        return new JsonResponse($payload, 200, $headers);
    }
}
