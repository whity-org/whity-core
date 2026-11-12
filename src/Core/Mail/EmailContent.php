<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * The per-message content an {@see EmailLayout} renders into the branded shell
 * (WC-email, EM.1). Structured (not raw HTML) so the layout controls escaping —
 * a caller passes plain strings and cannot inject markup.
 *
 * Covers the transactional messages: a heading, one or more body paragraphs, the
 * ONE main button, an optional highlighted callout (e.g. "X invited you to Y";
 * dashed when it is a proposal that has not happened yet), an optional quieter
 * footnote (e.g. link expiry), label/value rows (an invoice's total), a short
 * code shown large, the inbox preview line, and the language.
 */
final class EmailContent
{
    /**
     * @param string       $heading        The message headline.
     * @param list<string> $paragraphs     Body paragraphs, in order (plain text).
     * @param string|null  $ctaLabel       Main button label, or null for no button.
     * @param string|null  $ctaUrl         Main button URL (required when $ctaLabel is set).
     * @param string|null  $callout        Highlighted callout line, or null.
     * @param string|null  $footnote       Quieter line under the button, or null.
     * @param list<array{0: string, 1: string}> $rows Label and value pairs; values stay left to right.
     * @param string|null  $code           A short code shown large, left to right, or null.
     * @param string|null  $preheader      The inbox preview line, or null.
     * @param string       $locale         `en` or `ar`; Arabic is laid out right to left.
     * @param bool         $calloutPending Draw the callout dashed in the brand colour: not happened yet.
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $paragraphs = [],
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $callout = null,
        public readonly ?string $footnote = null,
        public readonly array $rows = [],
        public readonly ?string $code = null,
        public readonly ?string $preheader = null,
        public readonly string $locale = 'en',
        public readonly bool $calloutPending = false,
    ) {
    }

    /** Whether a usable call-to-action button is present. */
    public function hasCta(): bool
    {
        return $this->ctaLabel !== null && $this->ctaLabel !== ''
            && $this->ctaUrl !== null && $this->ctaUrl !== '';
    }

    /** Whether the message is in Arabic, laid out right to left. */
    public function isRtl(): bool
    {
        return self::normaliseLocale($this->locale) === 'ar';
    }

    /** `ar` for any Arabic locale (`ar`, `ar-JO`, `ar_SA`), else `en`. */
    public static function normaliseLocale(string $locale): string
    {
        return preg_match('/^ar([-_]|$)/i', trim($locale)) === 1 ? 'ar' : 'en';
    }
}
