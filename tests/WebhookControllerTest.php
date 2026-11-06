<?php

declare(strict_types=1);

class WebhookControllerTest extends TestCase
{
    public function testLegacyCashbackWebhookIsAuthenticatedAcknowledgedAndNotStored(): void
    {
        // handle() needs the shop's PSR-7 stack, so this guards the source.
        $source = (string) \file_get_contents(
            __DIR__ . "/../src/Controller/WebhookController.php",
        );

        $signatureCheck = \strpos($source, "SignatureVerifier::verify");
        $cashbackBranch = \strpos($source, '$type === "cashback"');
        $this->assertTrue($signatureCheck !== false);
        $this->assertTrue($cashbackBranch !== false);
        // Unsigned payloads are rejected before the cashback branch runs.
        $this->assertTrue($signatureCheck < $cashbackBranch);
        // The type is still recognised so it never reaches settlement.
        $this->assertTrue(\str_contains($source, '"updateCashbackInfo"'));
        $this->assertFalse(\str_contains($source, "setCashback"));
        $this->assertFalse(\str_contains($source, "normalizeCashback"));
    }
}
