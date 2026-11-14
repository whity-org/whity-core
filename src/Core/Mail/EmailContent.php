<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * The per-message content an {@see EmailLayout} renders into the branded shell
 * (EM.1, `docs/design/handoff/emails/`). Structured (not raw HTML) so the layout
 * controls escaping: a caller passes plain strings and cannot inject markup.
 *
 * The blocks, in the order they are drawn: heading, paragraphs, a one-time code,
 * detail rows (When / Device / Near), a checklist, an agent's proposal (the dashed
 * box: not happened yet), a grey note, the one main button, a soft line under it,
 * and the footer notice ("Security emails can't be turned off. Sent to …").
 *
 * Every field after `$footnote` is optional and new in 0.2.29; the first six keep
 * their old meaning so existing senders render unchanged.
 */
final class EmailContent
{
    /**
     * @param string                    $heading    The message headline.
     * @param list<string>              $paragraphs Body paragraphs, in order (plain text).
     * @param string|null               $ctaLabel   Button label, or null for no button.
     * @param string|null               $ctaUrl     Button URL (required when $ctaLabel is set).
     * @param string|null               $callout    The grey note, or null.
     * @param string|null               $footnote   Soft line under the button, or null.
     * @param string|null               $preheader  The inbox preview line.
     * @param string|null               $code       A one-time code, drawn large and left to right.
     * @param list<array{0: string, 1: string}> $rows Label and value pairs.
     * @param list<string>              $checklist  Ticked lines (what agents did, first steps).
     * @param array{meta: string, title: string, detail: string}|null $proposal
     *                                              An agent's proposal: who for whom, what, the detail.
     * @param string|null               $calloutLead Bold start of the grey note ("Wasn't you?").
     * @param string                    $ctaTone    'brand' or 'danger' ("It wasn't me").
     * @param string|null               $notice     The footer line: why this was sent.
     * @param string                    $language   'en' or 'ar': direction, font and the layout's own words.
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $paragraphs = [],
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $callout = null,
        public readonly ?string $footnote = null,
        public readonly ?string $preheader = null,
        public readonly ?string $code = null,
        public readonly array $rows = [],
        public readonly array $checklist = [],
        public readonly ?array $proposal = null,
        public readonly ?string $calloutLead = null,
        public readonly string $ctaTone = 'brand',
        public readonly ?string $notice = null,
        public readonly string $language = 'en',
        /** Draw the rows above the paragraphs (`new-sign-in`: the facts, then what to do). */
        public readonly bool $rowsFirst = false,
        /**
         * How a row's value is drawn, by row index: 'strong' (600, what was
         * booked, the new time) or 'struck' (the old time). Others are plain.
         *
         * @var array<int, 'strong'|'struck'>
         */
        public readonly array $rowStyles = [],
        /**
         * A soft line under the button with one link inside it ("Need to change
         * it? Move or cancel before it starts."). Plain strings; the layout escapes them.
         *
         * @var array{before: string, label: string, url: string, after: string}|null
         */
        public readonly ?array $linkLine = null,
        /** A smaller soft line after that ("A calendar file is attached …"). */
        public readonly ?string $smallNote = null,
    ) {
    }

    /** Whether a usable call-to-action button is present. */
    public function hasCta(): bool
    {
        return $this->ctaLabel !== null && $this->ctaLabel !== ''
            && $this->ctaUrl !== null && $this->ctaUrl !== '';
    }

    public function isArabic(): bool
    {
        return $this->language === 'ar';
    }
}
