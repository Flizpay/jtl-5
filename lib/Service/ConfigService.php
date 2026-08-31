<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use JTL\DB\DbInterface;
use JTL\Shop;
use Plugin\flizpay\lib\FlizPlugin;

/**
 * Merchant settings (tplugineinstellungen, declared in info.xml) and the
 * plugin's runtime key-value store (xplugin_flizpay_config).
 *
 * Merchant settings are read straight from the DB — the plugin-config object
 * cache must never serve a stale API key to the webhook handler that runs
 * seconds after a settings save.
 */
class ConfigService
{
    public const KEY_WEBHOOK_KEY      = 'webhook_key';
    public const KEY_WEBHOOK_URL      = 'webhook_url';
    public const KEY_WEBHOOK_ALIVE    = 'webhook_alive';
    public const KEY_CASHBACK         = 'cashback';
    public const KEY_REPORTED_VERSION = 'reported_plugin_version';
    public const KEY_LAST_WEBHOOK_AT  = 'last_webhook_at';
    public const KEY_API_KEY_HASH     = 'api_key_hash';
    public const KEY_HANDSHAKE_AT     = 'handshake_at';
    public const KEY_METHOD_IMAGE     = 'method_image';

    private DbInterface $db;

    public function __construct(?DbInterface $db = null)
    {
        $this->db = $db ?? FlizPlugin::getDB();
    }

    // ------------------------------------------------------------------
    // Runtime key-value store
    // ------------------------------------------------------------------

    public function get(string $key): ?string
    {
        $row = $this->db->getSingleObject(
            'SELECT cValue FROM xplugin_flizpay_config WHERE cKey = :k',
            ['k' => $key]
        );

        return $row->cValue ?? null;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->queryPrepared(
            'INSERT INTO xplugin_flizpay_config (cKey, cValue, dUpdated)
                VALUES (:k, :v, NOW())
                ON DUPLICATE KEY UPDATE cValue = :v, dUpdated = NOW()',
            ['k' => $key, 'v' => $value]
        );
    }

    public function delete(string $key): void
    {
        $this->db->delete('xplugin_flizpay_config', 'cKey', $key);
    }

    public function isWebhookAlive(): bool
    {
        return $this->get(self::KEY_WEBHOOK_ALIVE) === 'yes';
    }

    public function setWebhookAlive(bool $alive): void
    {
        $this->set(self::KEY_WEBHOOK_ALIVE, $alive ? 'yes' : 'no');
    }

    public function getWebhookKey(): string
    {
        return (string)($this->get(self::KEY_WEBHOOK_KEY) ?? '');
    }

    /**
     * @return array{first_purchase_amount: float, standard_amount: float}|null
     */
    public function getCashback(): ?array
    {
        $raw = $this->get(self::KEY_CASHBACK);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return null;
        }

        return [
            'first_purchase_amount' => (float)($data['first_purchase_amount'] ?? 0),
            'standard_amount'       => (float)($data['standard_amount'] ?? 0),
        ];
    }

    public function setCashback(?array $cashback): void
    {
        $this->set(
            self::KEY_CASHBACK,
            $cashback === null
                ? ''
                : (string)\json_encode([
                    'first_purchase_amount' => (float)($cashback['first_purchase_amount'] ?? 0),
                    'standard_amount'       => (float)($cashback['standard_amount'] ?? 0),
                ])
        );
    }

    // ------------------------------------------------------------------
    // Merchant settings (info.xml Settingslink, stored in tplugineinstellungen)
    // ------------------------------------------------------------------

    public function getMerchantSetting(string $valueName): ?string
    {
        $row = $this->db->getSingleObject(
            'SELECT cWert FROM tplugineinstellungen WHERE kPlugin = :pid AND cName = :name',
            ['pid' => FlizPlugin::getKPlugin(), 'name' => $valueName]
        );

        return $row->cWert ?? null;
    }

    public function getApiKey(): string
    {
        return \trim((string)($this->getMerchantSetting('flizpay_apiKey') ?? ''));
    }

    public function holdFromWawi(): bool
    {
        return ($this->getMerchantSetting('flizpay_holdFromWawi') ?? 'Y') !== 'N';
    }

    public function displayLogo(): bool
    {
        return ($this->getMerchantSetting('flizpay_displayLogo') ?? 'Y') !== 'N';
    }

    public function displayHeadline(): bool
    {
        return ($this->getMerchantSetting('flizpay_displayHeadline') ?? 'Y') !== 'N';
    }

    public function displayDescription(): bool
    {
        return ($this->getMerchantSetting('flizpay_displayDescription') ?? 'Y') !== 'N';
    }

    /**
     * The method is ready for checkout once the API key is set, a webhook key
     * exists and FLIZpay's inbound test webhook has confirmed reachability.
     */
    public function isConnected(): bool
    {
        return $this->getApiKey() !== ''
            && \strlen($this->getWebhookKey()) >= 32
            && $this->isWebhookAlive();
    }

    /**
     * Removes a non-working API key so misconfiguration is immediately visible
     * in the backend (WooCommerce-plugin behavior).
     */
    public function wipeApiKey(): void
    {
        $kPlugin = FlizPlugin::getKPlugin();
        $this->db->queryPrepared(
            "UPDATE tplugineinstellungen SET cWert = '' WHERE kPlugin = :pid AND cName = 'flizpay_apiKey'",
            ['pid' => $kPlugin]
        );
        $this->set(self::KEY_API_KEY_HASH, '');
        try {
            Shop::Container()->getCache()->flushTags([
                \CACHING_GROUP_PLUGIN,
                \CACHING_GROUP_PLUGIN . '_' . $kPlugin,
            ]);
        } catch (\Throwable) {
        }
    }
}
