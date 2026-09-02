<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use JTL\DB\DbInterface;
use Plugin\flizpay\src\FlizPlugin;

class CheckoutPresentationService
{
    public function __construct(private ?DbInterface $db = null)
    {
        $this->db ??= FlizPlugin::getDB();
    }

    public function apply(): void
    {
        if (!\function_exists("pq")) {
            return;
        }

        $methodId = FlizPlugin::getPaymentMethodId();
        if ($methodId <= 0) {
            return;
        }

        $row = $this->db->getSingleObject(
            'SELECT cName FROM tzahlungsartsprache
                WHERE kZahlungsart = :method AND cISOSprache = :language',
            [
                "method" => $methodId,
                "language" => (string) ($_SESSION["cISOSprache"] ?? "ger"),
            ],
        );
        $title = \trim((string) ($row->cName ?? ""));
        if ($title === "") {
            return;
        }

        $input = \pq('input[name="Zahlungsart"][value="' . $methodId . '"]');
        if ($input->length === 0) {
            return;
        }

        $label = $input->siblings('label[for="payment' . $methodId . '"]');
        if ($label->length === 0) {
            $label = \pq('label[for="payment' . $methodId . '"]');
        }
        if (
            $label->length === 0 ||
            $label->find(".flizpay-payment-copy")->length > 0 ||
            $label->children("img")->length === 0
        ) {
            return;
        }

        $escapedTitle = \htmlspecialchars(
            $title,
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            "UTF-8",
        );
        $copy =
            '<span class="flizpay-payment-copy"><strong class="flizpay-payment-title">' .
            $escapedTitle .
            "</strong></span>";
        $label->prepend($copy);

        $note = $label->children(".checkout-payment-method-note");
        if ($note->length > 0) {
            $label->find(".flizpay-payment-copy")->append($note);
        }
        $label->addClass("flizpay-payment-label");

        if (\pq("head link[data-flizpay-checkout]")->length === 0) {
            $url =
                FlizPlugin::getPlugin()->getPaths()->getBaseURL() .
                "frontend/css/checkout.css";
            \pq("head")->append(
                '<link rel="stylesheet" href="' .
                    \htmlspecialchars(
                        $url,
                        \ENT_QUOTES | \ENT_SUBSTITUTE,
                        "UTF-8",
                    ) .
                    '" data-flizpay-checkout>',
            );
        }
    }
}
