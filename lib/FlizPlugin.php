<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib;

use JTL\DB\DbInterface;
use JTL\Plugin\Helper as PluginHelper;
use JTL\Plugin\PluginInterface;
use JTL\Shop;

/**
 * Static access to plugin identity, settings and logging.
 *
 * The payment method's cModulId is installation-specific
 * ('kPlugin_<numeric kPlugin>_flizpay'), so every reference to it must be
 * resolved at runtime through this class.
 */
final class FlizPlugin
{
    public const PLUGIN_ID = "flizpay";

    /** info.xml <PaymentMethod><Method><Name> — cModulId derives from it */
    public const METHOD_NAME = "FLIZpay";

    /** Fallback when the plugin meta cannot be read (e.g. during uninstall) */
    public const VERSION = "1.0.0";

    private static ?int $kPlugin = null;

    private static ?int $kZahlungsart = null;

    public static function getDB(): DbInterface
    {
        return Shop::Container()->getDB();
    }

    public static function getPlugin(): ?PluginInterface
    {
        return PluginHelper::getPluginById(self::PLUGIN_ID);
    }

    public static function getKPlugin(): int
    {
        if (self::$kPlugin === null) {
            self::$kPlugin = PluginHelper::getIDByPluginID(self::PLUGIN_ID);
        }

        return self::$kPlugin;
    }

    /**
     * The payment method's cModulId, e.g. 'kPlugin_7_flizpay'.
     */
    public static function getModuleId(): string
    {
        return PluginHelper::getModuleIDByPluginID(
            self::getKPlugin(),
            self::METHOD_NAME,
        );
    }

    /**
     * kZahlungsart of the FLIZpay payment method (0 when not installed).
     */
    public static function getPaymentMethodId(): int
    {
        if (self::$kZahlungsart === null) {
            $row = self::getDB()->select(
                "tzahlungsart",
                "cModulId",
                self::getModuleId(),
            );
            self::$kZahlungsart = (int) ($row->kZahlungsart ?? 0);
        }

        return self::$kZahlungsart;
    }

    public static function getVersion(): string
    {
        try {
            $version = self::getPlugin()?->getMeta()?->getVersion();
        } catch (\Throwable) {
            $version = null;
        }

        return $version ?: self::VERSION;
    }

    /**
     * Payment-method log (tzahlungslog, visible in the backend) plus shop log
     * for warnings/errors.
     */
    public static function log(
        string $message,
        int $level = \LOGLEVEL_NOTICE,
        array $context = [],
    ): void {
        if ($context !== []) {
            $message .=
                " | " .
                \json_encode(
                    $context,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                );
        }
        try {
            \JTL\Checkout\ZahlungsLog::add(
                self::getModuleId(),
                $message,
                null,
                $level,
            );
        } catch (\Throwable) {
            // never let logging break payment processing
        }
        try {
            $logger = Shop::Container()->getLogService();
            if ($level === \LOGLEVEL_ERROR) {
                $logger->error("FLIZpay: " . $message);
            } elseif ($level === \LOGLEVEL_NOTICE) {
                $logger->notice("FLIZpay: " . $message);
            }
        } catch (\Throwable) {
        }
    }
}
