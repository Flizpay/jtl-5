<?php

declare(strict_types=1);

namespace Plugin\flizpay\src\Service;

/**
 * Everything the hosted <fliz-checkout> component needs that the shop already
 * knows, plus the markup shared by the storefront and the admin preview.
 *
 * Carries no credentials and no locally stored discount rules: the component
 * owns the offer text. An empty public id means it cannot render, so callers
 * keep the plain payment method label.
 */
final class CheckoutWidget
{
    public const SCRIPT_URL = "https://app.flizpay.de/web-components/v1/flizpay.js";

    /** Optional constant (e.g. in includes/config.JTL-Shop.ini.php) pointing at a staging/local bundle. */
    public const SCRIPT_URL_OVERRIDE = "FLIZPAY_WIDGET_SCRIPT_URL";

    /** Fallback label shown until the hosted script upgrades the element. */
    public const FALLBACK_TITLE = "FLIZpay";

    public function __construct(
        public readonly string $publicId,
        public readonly string $locale,
        public readonly ?int $amount,
        public readonly bool $showHeadline,
        public readonly bool $showDescription,
        public readonly bool $showLogo,
    ) {}

    public static function fromConfig(
        ConfigService $config,
        string $publicId,
        string $locale,
        ?int $amount,
    ): self {
        return new self(
            $publicId,
            $locale,
            $amount,
            $config->displayHeadline(),
            $config->displayDescription(),
            $config->displayLogo(),
        );
    }

    /**
     * Whether the component can render at all.
     */
    public function isRenderable(): bool
    {
        return $this->publicId !== "";
    }

    /**
     * One <fliz-checkout> surface ("title" or "description").
     */
    public function elementHtml(string $surface, string $fallback = ""): string
    {
        $attributes = [
            "data-public-id" => $this->publicId,
            "data-surface" => $surface,
            "data-locale" => $this->locale,
        ];
        if ($this->amount !== null) {
            $attributes["data-amount"] = (string) $this->amount;
        }

        $html = "<fliz-checkout";
        foreach ($attributes as $name => $value) {
            $html .= " " . $name . '="' . self::escape($value) . '"';
        }

        return $html . ">" . self::escape($fallback) . "</fliz-checkout>";
    }

    /**
     * The hosted bundle. It sets no cookies or storage; the attributes keep
     * consent managers from blocking it.
     */
    public static function scriptTagHtml(): string
    {
        return '<script async src="' .
            self::escape(self::scriptUrl()) .
            '" data-cookieconsent="ignore" data-borlabs-cookie-script-blocker-ignore data-flizpay-widget></script>';
    }

    public static function scriptUrl(): string
    {
        if (\defined(self::SCRIPT_URL_OVERRIDE)) {
            $override = \constant(self::SCRIPT_URL_OVERRIDE);
            if (\is_string($override) && \trim($override) !== "") {
                return \trim($override);
            }
        }

        return self::SCRIPT_URL;
    }

    /**
     * Gross total in integer EUR cents. The component formats cents as euros,
     * so other currencies send no amount and get the offer text without a
     * sample saving.
     */
    public static function amountInCents(float $gross, ?string $currency): ?int
    {
        if (\strtoupper((string) $currency) !== "EUR" || !\is_finite($gross)) {
            return null;
        }
        $cents = (int) \round($gross * 100);

        return $cents >= 0 ? $cents : null;
    }

    /**
     * Maps a JTL language code (ISO 639-2, e.g. "ger") or an ISO 639-1 code to
     * the BCP 47 locale the component expects.
     */
    public static function locale(string $iso): string
    {
        $iso = \strtolower(\trim($iso));

        return match ($iso) {
            "ger", "deu", "de" => "de-DE",
            "eng", "en" => "en-GB",
            default => self::fallbackLocale($iso),
        };
    }

    /**
     * Other shop languages: the ISO 639-1 code, which the component accepts as
     * a bare language. Unknown values fall back to German.
     */
    private static function fallbackLocale(string $iso): string
    {
        if (\strlen($iso) === 3 && \class_exists(\JTL\Helpers\Text::class)) {
            $iso = (string) \JTL\Helpers\Text::convertISO2ISO639($iso);
        }

        return \strlen($iso) === 2 ? $iso : "de-DE";
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
