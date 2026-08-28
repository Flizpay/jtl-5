<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Api;

use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\ConfigService;

/**
 * Typed operations on the FLIZpay API. Response validation mirrors the
 * WooCommerce plugin so both plugins accept exactly the same backend behavior.
 */
class FlizPayService
{
    private FlizPayClient $client;

    public function __construct(?string $apiKey = null)
    {
        $this->client = new FlizPayClient($apiKey ?? (new ConfigService())->getApiKey());
    }

    /**
     * @return array{transactionId:string, reference:string, redirectUrl:string}|null
     */
    public function createTransaction(array $payload, string $idempotencyKey): ?array
    {
        $res = $this->client->request('POST', '/transactions', $payload, ['Idempotency-Key' => $idempotencyKey]);
        $tx  = $res['data'];
        if ($res['status'] < 200 || $res['status'] >= 300 || !\is_array($tx)) {
            FlizPlugin::log(
                'createTransaction failed',
                \LOGLEVEL_ERROR,
                ['http' => $res['status'], 'error' => $res['error'], 'jsonError' => $res['jsonError']]
            );

            return null;
        }
        foreach (['transactionId', 'reference', 'redirectUrl'] as $field) {
            if (empty($tx[$field]) || !\is_string($tx[$field])) {
                FlizPlugin::log('createTransaction: incomplete response', \LOGLEVEL_ERROR, ['missing' => $field]);

                return null;
            }
        }

        return [
            'transactionId' => $tx['transactionId'],
            'reference'     => $tx['reference'],
            'redirectUrl'   => $tx['redirectUrl'],
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
        $res = $this->client->request('GET', '/business/generate-webhook-key');
        if ($res['status'] === 0) {
            return ['ok' => false, 'transport' => true, 'key' => null];
        }
        $key = $res['data']['webhookKey'] ?? null;
        $ok  = \is_string($key) && \strlen($key) >= 32 && $res['status'] >= 200 && $res['status'] < 300;

        return ['ok' => $ok, 'transport' => false, 'key' => $ok ? $key : null];
    }

    /**
     * Registers the shop's webhook URL; the API echoes the stored value back
     * and the echo must match exactly.
     *
     * @return array{ok: bool, transport: bool}
     */
    public function registerWebhookUrl(string $webhookUrl): array
    {
        $res = $this->client->request('POST', '/business/edit', ['webhookUrl' => $webhookUrl]);
        if ($res['status'] === 0) {
            return ['ok' => false, 'transport' => true];
        }
        $echo = $res['data']['webhookUrl'] ?? null;

        return [
            'ok'        => $res['status'] >= 200 && $res['status'] < 300
                && \is_string($echo)
                && \strcmp($echo, $webhookUrl) === 0,
            'transport' => false,
        ];
    }

    /**
     * @return array{first_purchase_amount: float, standard_amount: float}|null
     *         null = no active cashback (or unreadable response)
     */
    public function fetchCashback(): ?array
    {
        $res       = $this->client->request('GET', '/business/cashback');
        $cashbacks = $res['data']['cashbacks'] ?? null;
        if ($res['status'] < 200 || $res['status'] >= 300 || !\is_array($cashbacks)) {
            return null;
        }
        foreach ($cashbacks as $cashback) {
            if (!\is_array($cashback) || empty($cashback['active'])) {
                continue;
            }
            $first    = (float)($cashback['firstPurchaseAmount'] ?? 0);
            $standard = (float)($cashback['amount'] ?? 0);
            if ($first > 0 || $standard > 0) {
                return ['first_purchase_amount' => $first, 'standard_amount' => $standard];
            }
        }

        return null;
    }

    /**
     * @return bool success
     */
    public function editBusiness(array $fields): bool
    {
        $res = $this->client->request('POST', '/business/edit', $fields);

        return $res['status'] >= 200 && $res['status'] < 300;
    }

    public function reportLifecycle(bool $isActive): bool
    {
        $fields = ['isActive' => $isActive];
        if ($isActive) {
            $fields['pluginVersion'] = FlizPlugin::getVersion();
        }

        return $this->editBusiness($fields);
    }

    public function reportVersion(): bool
    {
        return $this->editBusiness(['pluginVersion' => FlizPlugin::getVersion()]);
    }

    public function deregisterWebhook(): bool
    {
        return $this->editBusiness(['webhookUrl' => '']);
    }
}
