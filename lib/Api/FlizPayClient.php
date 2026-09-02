<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Api;

use Plugin\flizpay\lib\FlizPlugin;
use Plugin\flizpay\lib\Service\Logger;

/**
 * Minimal curl client for the FLIZpay API.
 *
 * Responses may arrive bare ({...}) or wrapped ({"data": {...}}) — the wrapper
 * is stripped transparently. Transport and JSON failures are reported via the
 * result array, never as exceptions.
 */
class FlizPayClient
{
    public const BASE_URL = "https://olegs-macbook-pro-1.tail9450f2.ts.net:4440";

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    public function __construct(private readonly string $apiKey) {}

    /**
     * @param array<string,string> $extraHeaders
     * @return array{status:int, data:?array, error:?string, jsonError:bool}
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $extraHeaders = [],
    ): array {
        $method = \strtoupper($method);
        $headers = [
            "Content-type: application/json",
            "x-api-key: " . $this->apiKey,
            "X-FLIZpay-Plugin-Version: " . FlizPlugin::getVersion(),
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ": " . $value;
        }

        $ch = \curl_init(self::BASE_URL . $path);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => self::TIMEOUT,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            \curl_setopt(
                $ch,
                \CURLOPT_POSTFIELDS,
                \json_encode(
                    $body,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ),
            );
        }

        $started = \hrtime(true);
        $raw = \curl_exec($ch);
        $elapsedMs = (int) \round((\hrtime(true) - $started) / 1e6);
        $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($ch);
        \curl_close($ch);

        if ($raw === false) {
            $result = [
                "status" => 0,
                "data" => null,
                "error" => $curlError ?: "connection failed",
                "jsonError" => false,
            ];
            // Always recorded: an unreachable API is silent for most callers.
            Logger::error("http transport failure", [
                "method" => $method,
                "path" => $path,
                "error" => $result["error"],
                "ms" => $elapsedMs,
            ]);
        } else {
            $decoded = \json_decode((string) $raw, true);
            if (!\is_array($decoded)) {
                $result = [
                    "status" => $status,
                    "data" => null,
                    "error" => null,
                    "jsonError" => true,
                ];
            } else {
                // unwrap the optional {"data": {...}} envelope
                $data =
                    isset($decoded["data"]) && \is_array($decoded["data"])
                        ? $decoded["data"]
                        : $decoded;
                $result = [
                    "status" => $status,
                    "data" => $data,
                    "error" => null,
                    "jsonError" => false,
                ];
            }
        }

        Logger::debug("http " . $method . " " . $path, [
            "http" => $result["status"],
            "ms" => $elapsedMs,
            "bytes" => $raw === false ? 0 : \strlen((string) $raw),
            "jsonError" => $result["jsonError"],
            "error" => $result["error"],
        ]);

        return $result;
    }
}
