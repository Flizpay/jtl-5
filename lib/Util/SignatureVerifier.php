<?php

declare(strict_types=1);

namespace Plugin\flizpay\lib\Util;

/**
 * Verifies the X-Fliz-Signature webhook header (HMAC-SHA256, lowercase hex).
 *
 * The WooCommerce plugin verifies the HMAC over a RE-SERIALIZATION of the
 * decoded payload (json_encode with JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
 * not over the raw body. Until the FLIZpay backend team confirms raw-body
 * signing, both forms are accepted: raw body first, re-encoded form as
 * fallback. Verification fails closed — no header or no configured key means
 * the request is rejected.
 */
final class SignatureVerifier
{
    public static function verify(
        string $rawBody,
        array $decodedPayload,
        ?string $headerSignature,
        ?string $webhookKey,
    ): bool {
        if (!\is_string($webhookKey) || \strlen($webhookKey) < 32) {
            return false;
        }
        if (!\is_string($headerSignature) || $headerSignature === "") {
            return false;
        }
        $headerSignature = \strtolower(\trim($headerSignature));

        $rawMac = \hash_hmac("sha256", $rawBody, $webhookKey);
        if (\hash_equals($rawMac, $headerSignature)) {
            return true;
        }

        $reEncoded = \json_encode(
            $decodedPayload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
        if (!\is_string($reEncoded)) {
            return false;
        }
        $reEncodedMac = \hash_hmac("sha256", $reEncoded, $webhookKey);

        return \hash_equals($reEncodedMac, $headerSignature);
    }

    /**
     * Loggable reason for a failed verification. Derived from presence and
     * length only — never from the key or signature values.
     */
    public static function failureReason(
        ?string $headerSignature,
        ?string $webhookKey,
    ): string {
        if (!\is_string($webhookKey) || $webhookKey === "") {
            return "no_key";
        }
        if (\strlen($webhookKey) < 32) {
            return "short_key";
        }
        if (!\is_string($headerSignature) || $headerSignature === "") {
            return "no_header";
        }

        return "mismatch";
    }
}
