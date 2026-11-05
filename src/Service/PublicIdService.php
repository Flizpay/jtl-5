<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use Plugin\flizpay\src\Api\FlizPayService;
use Plugin\flizpay\src\FlizPlugin;

/**
 * Owns the shop's public id, the only FLIZpay identifier the browser receives.
 *
 * Reading and acquiring are deliberately separate: rendering a storefront page
 * must never block on a FLIZpay request, so only admin-side callers (settings
 * save, handshake, settings tab, plugin update) acquire an id.
 */
class PublicIdService
{
    private const RETRY_BACKOFF_SECONDS = 3600;

    private ConfigService $config;

    /** @var \Closure(string): ?string fetches the public id for an API key */
    private \Closure $fetcher;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param (\Closure(string): ?string)|null $fetcher
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        ?ConfigService $config = null,
        ?\Closure $fetcher = null,
        ?\Closure $clock = null,
    ) {
        $this->config = $config ?? new ConfigService();
        $this->fetcher =
            $fetcher ??
            static fn(string $apiKey): ?string => (new FlizPayService(
                $apiKey,
            ))->fetchPublicId();
        $this->clock = $clock ?? static fn(): int => \time();
    }

    /**
     * The stored public id, without ever contacting FLIZpay.
     *
     * Empty means the component cannot render, so callers keep the plain
     * payment method label.
     */
    public function resolve(): string
    {
        if ($this->config->getApiKey() === "") {
            return "";
        }

        return $this->config->getPublicId();
    }

    /**
     * Fetches and stores the public id when one is missing.
     *
     * @param bool $throttled skip the request shortly after a failed attempt
     */
    public function ensure(bool $throttled = false): string
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === "") {
            return "";
        }

        $publicId = $this->config->getPublicId();
        if ($publicId !== "") {
            return $publicId;
        }

        $now = ($this->clock)();
        if ($throttled) {
            $retryAt = (int) ($this->config->get(
                ConfigService::KEY_PUBLIC_ID_RETRY_AT,
            ) ?? 0);
            if ($retryAt > 0 && $now - $retryAt < self::RETRY_BACKOFF_SECONDS) {
                return "";
            }
        }

        try {
            $publicId = ($this->fetcher)($apiKey);
        } catch (\Throwable $e) {
            FlizPlugin::log("public id fetch failed", \LOGLEVEL_ERROR, [
                "error" => $e->getMessage(),
            ]);
            $publicId = null;
        }

        $publicId = \trim((string) $publicId);
        if ($publicId === "") {
            $this->config->set(
                ConfigService::KEY_PUBLIC_ID_RETRY_AT,
                (string) $now,
            );

            return "";
        }

        $this->config->set(ConfigService::KEY_PUBLIC_ID, $publicId);
        $this->config->delete(ConfigService::KEY_PUBLIC_ID_RETRY_AT);

        return $publicId;
    }
}
