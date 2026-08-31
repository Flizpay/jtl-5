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
 *
 * Response protocol (identical to the WooCommerce plugin, so the FLIZpay
 * backend sees the same behavior on both platforms):
 *   422  invalid JSON
 *   401  missing/invalid signature (fail closed)
 *   400  cashback update without the required fields
 *   200  everything else — rejected-but-parseable events answer
 *        {"accepted": false, "reason": "<code>"} so FLIZpay does not retry them
 *   500  only for transient processing crashes (retry may succeed)
 */
class WebhookController
{
    public static function handle(ServerRequestInterface $request, array $args, JTLSmarty $smarty): ResponseInterface
    {
        $config  = new ConfigService();
        $rawBody = (string)$request->getBody();
        if (\str_starts_with($rawBody, "\xEF\xBB\xBF")) {
            $rawBody = \substr($rawBody, 3);
        }

        try {
            $decoded = \json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error('Invalid JSON', 422);
        }
        if (!\is_array($decoded)) {
            return self::error('Invalid JSON', 422);
        }

        $signature = $request->getHeaderLine('X-Fliz-Signature');
        if (!SignatureVerifier::verify($rawBody, $decoded, $signature !== '' ? $signature : null, $config->getWebhookKey())) {
            FlizPlugin::log('webhook: authentication failed', \LOGLEVEL_ERROR);

            return self::error('Authentication failed', 401);
        }

        $config->set(ConfigService::KEY_LAST_WEBHOOK_AT, \date('Y-m-d H:i:s'));

        // connectivity handshake
        if (\array_key_exists('test', $decoded)) {
            $config->setWebhookAlive(true);
            FlizPlugin::log('webhook: connectivity test received — FLIZpay is live', \LOGLEVEL_NOTICE);

            return self::success(['alive' => true]);
        }

        // live cashback update pushed from the FLIZ merchant app
        if (\array_key_exists('updateCashbackInfo', $decoded)) {
            if (!isset($decoded['firstPurchaseAmount'], $decoded['amount'])
                || !\is_numeric($decoded['firstPurchaseAmount'])
                || !\is_numeric($decoded['amount'])
            ) {
                return self::error('Missing cashback information', 400);
            }
            (new CashbackService(null, $config))->update([
                'first_purchase_amount' => (float)$decoded['firstPurchaseAmount'],
                'standard_amount'       => (float)$decoded['amount'],
            ]);

            return self::success('Cashback information updated');
        }

        // payment event
        try {
            $result = (new SettlementService())->settle($decoded, 'webhook');
        } catch (\Throwable $e) {
            FlizPlugin::log('webhook: settlement crashed', \LOGLEVEL_ERROR, ['error' => $e->getMessage()]);

            return self::error('Temporary processing error', 500);
        }

        if ($result['success']) {
            return self::success('Order updated successfully');
        }
        if ($result['result'] === 'booking_failed' || $result['result'] === 'internal_error') {
            // Transient: FLIZpay must retry because webhooks are the only
            // settlement source.
            return self::error('Temporary processing error', 500);
        }

        return self::success(['accepted' => false, 'reason' => $result['result']]);
    }

    private static function success(mixed $data): ResponseInterface
    {
        return new JsonResponse(['success' => true, 'data' => $data], 200);
    }

    private static function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'data' => $message], $status);
    }
}
