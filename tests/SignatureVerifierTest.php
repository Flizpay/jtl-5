<?php

declare(strict_types=1);

use Plugin\flizpay\src\Util\SignatureVerifier;

/**
 * Covers webhook authentication, including the WooCommerce plugin's
 * re-serialized-payload signing variant and the fail-closed guarantees.
 */
class SignatureVerifierTest extends TestCase
{
    private const KEY = 'a-webhook-key-that-is-long-enough-for-hmac';

    private function sign(string $body, string $key = self::KEY): string
    {
        return \hash_hmac('sha256', $body, $key);
    }

    public function testValidRawBodySignatureIsAccepted(): void
    {
        $body    = '{"transactionId":"tx_1","status":"completed"}';
        $decoded = \json_decode($body, true);

        $this->assertTrue(
            SignatureVerifier::verify($body, $decoded, $this->sign($body), self::KEY),
            'signature over the raw body is accepted'
        );
    }

    public function testReSerializedSignatureIsAccepted(): void
    {
        // The WooCommerce plugin signs json_encode(json_decode($body)) rather
        // than the raw body; both forms must verify until the backend confirms.
        $body      = "{\n  \"transactionId\" : \"tx_1\",\n  \"status\" : \"completed\"\n}";
        $decoded   = \json_decode($body, true);
        $reEncoded = \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $this->assertFalse(
            $body === $reEncoded,
            'the fixture really does differ between raw and re-encoded form'
        );
        $this->assertTrue(
            SignatureVerifier::verify($body, $decoded, $this->sign($reEncoded), self::KEY),
            'signature over the re-encoded payload is accepted'
        );
    }

    public function testUnicodeAndSlashPayloadVerifiesInBothForms(): void
    {
        $decoded   = ['url' => 'https://checkout.flizpay.de/x', 'name' => 'Möbel & Zubehör'];
        $rawBody   = \json_encode($decoded);                                     // escapes / and unicode
        $reEncoded = \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $this->assertFalse($rawBody === $reEncoded, 'raw and re-encoded differ for unicode/slash payloads');
        $this->assertTrue(
            SignatureVerifier::verify($rawBody, $decoded, $this->sign($rawBody), self::KEY),
            'raw-body signing verifies'
        );
        $this->assertTrue(
            SignatureVerifier::verify($rawBody, $decoded, $this->sign($reEncoded), self::KEY),
            're-encoded signing verifies'
        );
    }

    public function testUppercaseHexSignatureIsAccepted(): void
    {
        $body    = '{"a":1}';
        $decoded = \json_decode($body, true);

        $this->assertTrue(
            SignatureVerifier::verify($body, $decoded, \strtoupper($this->sign($body)), self::KEY),
            'hex casing does not matter'
        );
    }

    public function testForgedSignatureIsRejected(): void
    {
        $body    = '{"transactionId":"tx_1","status":"completed"}';
        $decoded = \json_decode($body, true);

        $this->assertFalse(
            SignatureVerifier::verify($body, $decoded, $this->sign($body, 'the-wrong-key-but-still-long-enough!!'), self::KEY),
            'signature from a different key is rejected'
        );
        $this->assertFalse(
            SignatureVerifier::verify($body, $decoded, \str_repeat('0', 64), self::KEY),
            'garbage signature is rejected'
        );
    }

    public function testTamperedBodyIsRejected(): void
    {
        $original = '{"transactionId":"tx_1","amount":10.00}';
        $tampered = '{"transactionId":"tx_1","amount":10000.00}';

        $this->assertFalse(
            SignatureVerifier::verify($tampered, \json_decode($tampered, true), $this->sign($original), self::KEY),
            'a modified body no longer matches its signature'
        );
    }

    public function testMissingSignatureHeaderFailsClosed(): void
    {
        $body    = '{"a":1}';
        $decoded = \json_decode($body, true);

        $this->assertFalse(SignatureVerifier::verify($body, $decoded, null, self::KEY), 'no header means rejected');
        $this->assertFalse(SignatureVerifier::verify($body, $decoded, '', self::KEY), 'empty header means rejected');
    }

    public function testMissingOrShortKeyFailsClosed(): void
    {
        $body    = '{"a":1}';
        $decoded = \json_decode($body, true);

        $this->assertFalse(
            SignatureVerifier::verify($body, $decoded, $this->sign($body), null),
            'unconfigured key means rejected'
        );
        $this->assertFalse(
            SignatureVerifier::verify($body, $decoded, $this->sign($body, 'short'), 'short'),
            'implausibly short key means rejected'
        );
    }

    public function testFailureReasonNeverDependsOnSecretValues(): void
    {
        $this->assertSame('no_key', SignatureVerifier::failureReason('abc', null));
        $this->assertSame('no_key', SignatureVerifier::failureReason('abc', ''));
        $this->assertSame('short_key', SignatureVerifier::failureReason('abc', 'short'));
        $this->assertSame('no_header', SignatureVerifier::failureReason(null, self::KEY));
        $this->assertSame('no_header', SignatureVerifier::failureReason('', self::KEY));
        $this->assertSame('mismatch', SignatureVerifier::failureReason('deadbeef', self::KEY));
    }
}
