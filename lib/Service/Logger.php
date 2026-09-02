<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Service;

use Plugin\flizpay\lib\FlizPlugin;
use JTL\Checkout\ZahlungsLog;

/**
 * Plugin log with a merchant-controlled debug switch.
 *
 * Sinks:
 *   - jtllogs/flizpay.log (JTL's web-blocked log directory): every level,
 *     debug entries only while the "Debug logging" setting is on
 *   - tzahlungslog (payment-method log in the backend): notice and error only
 *
 * Context contract: scalars only — IDs, status codes, reasons, timings. API
 * keys, signatures, request/response bodies and customer data must never be
 * passed.
 */
final class Logger
{
    public const FILE_NAME = "flizpay.log";

    private const MAX_STRING = 256;

    /** @var null|callable(int, string, array<string, scalar|null>, string): void */
    private static $sink = null;

    private static ?bool $debugEnabled = null;

    public static function error(string $message, array $context = []): void
    {
        self::write(\LOGLEVEL_ERROR, $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::write(\LOGLEVEL_NOTICE, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write(\LOGLEVEL_DEBUG, $message, $context);
    }

    public static function write(
        int $level,
        string $message,
        array $context = [],
    ): void {
        if ($level === \LOGLEVEL_DEBUG && !self::isDebugEnabled()) {
            return;
        }
        $context = self::sanitize($context);
        $line =
            $context === []
                ? $message
                : $message .
                    " " .
                    \json_encode(
                        $context,
                        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                    );
        $data = self::correlation($context);

        if (self::$sink !== null) {
            (self::$sink)($level, $message, $context, $data);

            return;
        }

        try {
            \file_put_contents(
                self::getFilePath(),
                \sprintf(
                    "[%s] flizpay.%s: %s",
                    \date("Y-m-d H:i:s"),
                    self::levelName($level),
                    $line,
                ) . \PHP_EOL,
                \FILE_APPEND | \LOCK_EX,
            );
        } catch (\Throwable) {
            // never let logging break payment processing
        }

        if ($level === \LOGLEVEL_DEBUG) {
            return;
        }
        try {
            ZahlungsLog::add(FlizPlugin::getModuleId(), $line, $data, $level);
        } catch (\Throwable) {
        }
    }

    /**
     * Resolved once per request; the setting is read from the DB on first use.
     */
    public static function isDebugEnabled(): bool
    {
        if (self::$debugEnabled === null) {
            try {
                $config = new ConfigService();
                self::$debugEnabled = $config->debugMode();
            } catch (\Throwable) {
                self::$debugEnabled = false;
            }
        }

        return self::$debugEnabled;
    }

    /**
     * null resets the memo (after the setting was saved, and in tests).
     */
    public static function setDebugEnabled(?bool $enabled): void
    {
        self::$debugEnabled = $enabled;
    }

    /**
     * Test hook: replaces both sinks.
     *
     * @param null|callable(int, string, array<string, scalar|null>, string): void $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function getFilePath(): string
    {
        $dir = \defined("PFAD_LOGFILES")
            ? \PFAD_LOGFILES
            : \PFAD_ROOT . "jtllogs/";

        return \rtrim($dir, "/") . "/" . self::FILE_NAME;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if ($value !== null && !\is_scalar($value)) {
                continue;
            }
            if (\is_string($value) && \strlen($value) > self::MAX_STRING) {
                $value = \substr($value, 0, self::MAX_STRING) . "…";
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * Short "order:12 tx:abc" token for tzahlungslog.cLogData, which the
     * backend log view searches.
     *
     * @param array<string, scalar|null> $context
     */
    private static function correlation(array $context): string
    {
        $parts = [];
        foreach (["order", "tx", "attempt"] as $key) {
            if (isset($context[$key]) && $context[$key] !== "") {
                $parts[] = $key . ":" . $context[$key];
            }
        }

        return \implode(" ", $parts);
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            \LOGLEVEL_ERROR => "ERROR",
            \LOGLEVEL_DEBUG => "DEBUG",
            default => "NOTICE",
        };
    }
}
