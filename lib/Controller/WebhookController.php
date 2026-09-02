<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Controller;

use JTL\Smarty\JTLSmarty;
use Laminas\Diactoros\Response\JsonResponse;
use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\CashbackService;
use Plugin\flizpay\lib\Service\ConfigService;
use Plugin\flizpay\lib\Service\SettlementService;
use Plugin\flizpay\lib\Util\SignatureVerifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /flizpay/webhook
 */
class WebhookController
{
    public static function handle(
        ServerRequestInterface $request,
        array $args,
        JTLSmarty $smarty,
    ): ResponseInterface {
        $config = new ConfigService();
        $rawBody = (string) $request->getBody();
        if (\str_starts_with($rawBody, "\xEF\xBB\xBF")) {
            $rawBody = \substr($rawBody, 3);
        }

        $signature = $request->getHeaderLine("X-Fliz-Signature");

        try {
            $decoded = \json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!\is_array($decoded)) {
            FlizPlugin::log("webhook: invalid JSON", \LOGLEVEL_ERROR, [
                "bytes" => \strlen($rawBody),
                "hasSignature" => $signature !== "",
            ]);

            return self::error("Invalid JSON", 422);
        }

        $type = \array_key_exists("test", $decoded)
            ? "test"
            : (\array_key_exists("updateCashbackInfo", $decoded)
                ? "cashback"
                : "payment");
        FlizPlugin::debug("webhook received", [
            "type" => $type,
            "bytes" => \strlen($rawBody),
            "hasSignature" => $signature !== "",
        ]);

        $webhookKey = $config->getWebhookKey();
        if (
            !SignatureVerifier::verify(
                $rawBody,
                $decoded,
                $signature !== "" ? $signature : null,
                $webhookKey,
            )
        ) {
            FlizPlugin::log("webhook: authentication failed", \LOGLEVEL_ERROR, [
                "reason" => SignatureVerifier::failureReason(
                    $signature !== "" ? $signature : null,
                    $webhookKey,
                ),
                "type" => $type,
            ]);

            return self::error("Authentication failed", 401);
        }

        $config->set(ConfigService::KEY_LAST_WEBHOOK_AT, \date("Y-m-d H:i:s"));

        // connectivity handshake
        if ($type === "test") {
            $config->setWebhookAlive(true);
            FlizPlugin::log(
                "webhook: connectivity test received — FLIZpay is live",
                \LOGLEVEL_NOTICE,
            );

            return self::success(["alive" => true]);
        }

        // live cashback update pushed from the FLIZ merchant app
        if ($type === "cashback") {
            if (
                !isset($decoded["firstPurchaseAmount"], $decoded["amount"]) ||
                !\is_numeric($decoded["firstPurchaseAmount"]) ||
                !\is_numeric($decoded["amount"])
            ) {
                FlizPlugin::debug("webhook: cashback update rejected", [
                    "http" => 400,
                ]);

                return self::error("Missing cashback information", 400);
            }

            $cashbackService = new CashbackService(null, $config);
            $cashbackService->update([
                "first_purchase_amount" =>
                    (float) $decoded["firstPurchaseAmount"],
                "standard_amount" => (float) $decoded["amount"],
            ]);
            FlizPlugin::debug("webhook: cashback updated", [
                "firstPurchaseAmount" =>
                    (float) $decoded["firstPurchaseAmount"],
                "amount" => (float) $decoded["amount"],
            ]);

            return self::success("Cashback information updated");
        }

        // payment event
        try {
            $settlementService = new SettlementService();
            $result = $settlementService->settle($decoded, "webhook");
        } catch (\Throwable $e) {
            FlizPlugin::log("webhook: settlement crashed", \LOGLEVEL_ERROR, [
                "error" => $e->getMessage(),
            ]);

            return self::error("Temporary processing error", 500);
        }

        if ($result["success"]) {
            FlizPlugin::debug("webhook: answered", [
                "http" => 200,
                "result" => $result["result"],
            ]);

            return self::success("Order updated successfully");
        }
        if (
            $result["result"] === "booking_failed" ||
            $result["result"] === "internal_error"
        ) {
            // Transient: FLIZpay must retry because webhooks are the only
            // settlement source.
            FlizPlugin::debug("webhook: answered", [
                "http" => 500,
                "result" => $result["result"],
            ]);

            return self::error("Temporary processing error", 500);
        }
        FlizPlugin::debug("webhook: answered", [
            "http" => 200,
            "result" => $result["result"],
            "accepted" => false,
        ]);

        return self::success([
            "accepted" => false,
            "reason" => $result["result"],
        ]);
    }

    private static function success(mixed $data): ResponseInterface
    {
        return new JsonResponse(["success" => true, "data" => $data], 200);
    }

    private static function error(
        string $message,
        int $status,
    ): ResponseInterface {
        return new JsonResponse(
            ["success" => false, "data" => $message],
            $status,
        );
    }
}
