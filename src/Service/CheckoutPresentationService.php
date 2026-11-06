<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

use JTL\DB\DbInterface;
use JTL\Session\Frontend;
use Plugin\flizpay\src\FlizPlugin;

/**
 * Renders the FLIZpay payment row on the checkout shipping/payment step.
 *
 * The headline and description come from the hosted <fliz-checkout> web
 * component; the shop only passes its public id, the language and the amount
 * being paid. The page is server-rendered (shipping changes re-render it via
 * $.evo.loadContent, which runs this output filter again), so the amount is
 * always current without client-side plumbing.
 *
 * Without a public id the plain payment method name stays, so a shop that is
 * not connected never shows an empty element.
 */
class CheckoutPresentationService
{
    private ConfigService $config;

    public function __construct(
        private ?DbInterface $db = null,
        ?ConfigService $config = null,
    ) {
        $this->db ??= FlizPlugin::getDB();
        $this->config = $config ?? new ConfigService($this->db);
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
            $label->find(".flizpay-payment-copy")->length > 0
        ) {
            return;
        }

        $title = $this->plainTitle($methodId);
        $widget = $this->buildWidget();
        $renderable = $widget->isRenderable();
        $showHeadline = $renderable && $widget->showHeadline;
        $showDescription = $renderable && $widget->showDescription;

        $titleHtml = $showHeadline
            ? $widget->elementHtml("title", CheckoutWidget::FALLBACK_TITLE)
            : self::escape($title);
        $copy =
            '<span class="flizpay-payment-copy"><strong class="flizpay-payment-title">' .
            $titleHtml .
            "</strong></span>";

        $label->children(".content")->remove();
        $label->prepend($copy);
        $copyNode = $label->find(".flizpay-payment-copy");

        if ($showDescription) {
            $copyNode->append(
                '<span class="flizpay-payment-description" data-flizpay-for="payment' .
                    $methodId .
                    '"' .
                    ($this->isChecked($input) ? "" : " hidden") .
                    ">" .
                    $widget->elementHtml("description") .
                    "</span>",
            );
        }

        $note = $label->children(".checkout-payment-method-note");
        if ($note->length > 0) {
            $copyNode->append($note);
        }
        $label->addClass("flizpay-payment-label");

        if ($showHeadline || $showDescription) {
            $label->after(self::loaderScriptHtml());
        }

        $this->appendStylesheet();
    }

    /**
     * Inline loader: adds the hosted bundle once per page and keeps the
     * description visible only while FLIZpay is the selected method.
     */
    public static function loaderScriptHtml(): string
    {
        $src = \json_encode(
            CheckoutWidget::scriptUrl(),
            \JSON_UNESCAPED_SLASHES |
                \JSON_HEX_TAG |
                \JSON_HEX_AMP |
                \JSON_HEX_APOS |
                \JSON_HEX_QUOT,
        );

        return <<<HTML
        <script data-flizpay-loader>
        (function () {
            if (window.flizpayCheckoutLoader) { return; }
            window.flizpayCheckoutLoader = true;
            if (!document.querySelector("script[data-flizpay-widget]")) {
                var s = document.createElement("script");
                s.async = true;
                s.src = {$src};
                s.setAttribute("data-flizpay-widget", "");
                s.setAttribute("data-cookieconsent", "ignore");
                s.setAttribute("data-borlabs-cookie-script-blocker-ignore", "");
                (document.head || document.documentElement).appendChild(s);
            }
            document.addEventListener("change", function (e) {
                if (!e.target || e.target.name !== "Zahlungsart") { return; }
                document.querySelectorAll(".flizpay-payment-description[data-flizpay-for]").forEach(function (el) {
                    var input = document.getElementById(el.getAttribute("data-flizpay-for"));
                    el.hidden = !(input && input.checked);
                });
            });
        })();
        </script>
        HTML;
    }

    private function buildWidget(): CheckoutWidget
    {
        return CheckoutWidget::fromConfig(
            $this->config,
            (new PublicIdService($this->config))->resolve(),
            CheckoutWidget::locale(
                (string) ($_SESSION["cISOSprache"] ?? "ger"),
            ),
            $this->resolveAmount(),
        );
    }

    /**
     * Cart gross total (incl. the selected shipping and surcharges) in the
     * customer's currency, as EUR cents; null for any other currency.
     */
    private function resolveAmount(): ?int
    {
        try {
            // Already converted into the session currency by the cart.
            $gross = (float) Frontend::getCart()->gibGesamtsummeWaren(true);

            return CheckoutWidget::amountInCents(
                $gross,
                Frontend::getCurrency()->getCode(),
            );
        } catch (\Throwable $e) {
            FlizPlugin::debug("checkout amount unavailable", [
                "error" => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function plainTitle(int $methodId): string
    {
        $row = $this->db->getSingleObject(
            'SELECT cName FROM tzahlungsartsprache
                WHERE kZahlungsart = :method AND cISOSprache = :language',
            [
                "method" => $methodId,
                "language" => (string) ($_SESSION["cISOSprache"] ?? "ger"),
            ],
        );
        $title = \trim((string) ($row->cName ?? ""));

        return $title !== "" ? $title : CheckoutWidget::FALLBACK_TITLE;
    }

    private function isChecked(object $input): bool
    {
        $checked = $input->attr("checked");

        return $checked !== null && $checked !== false;
    }

    private function appendStylesheet(): void
    {
        $head = \pq("head");
        if (
            $head->length === 0 ||
            \pq("head link[data-flizpay-checkout]")->length > 0
        ) {
            return;
        }
        $plugin = FlizPlugin::getPlugin();
        if ($plugin === null) {
            return;
        }
        $url = $plugin->getPaths()->getBaseURL() . "frontend/css/checkout.css";
        $head->append(
            '<link rel="stylesheet" href="' .
                self::escape($url) .
                '" data-flizpay-checkout>',
        );
    }

    private static function escape(string $value): string
    {
        return \htmlspecialchars(
            $value,
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            "UTF-8",
        );
    }
}
