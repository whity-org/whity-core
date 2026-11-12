<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * One email a plugin's {@see EmailTemplate} builds (WHIT-959, EM.1). Plain text
 * only: the host lays it out in the same branded shell as core's own emails
 * (the workspace's logo, name and colour; 17 px text; one main button), escapes
 * every string, and writes the plain-text alternative.
 *
 * - `button` is the ONE main button. Its link must be http(s) or mailto, or the
 *   button is left out. An approval email's button only opens the workspace: the
 *   decision is made there, never from the email.
 * - `rows` are label and value pairs (an invoice's total, due date, number). The
 *   values keep left-to-right order in Arabic, so codes and amounts read right.
 *   Write amounts in normal units with the currency code (`JOD 49.000`).
 * - `code` is a short code shown large (a sign-in code), left to right.
 * - `note` is a highlighted box; `notePending` draws it dashed in the brand
 *   colour, meaning "not happened yet" (an agent's proposal).
 * - `footnote` is the quieter line after the button (when a link expires).
 * - `preheader` is the preview line inbox lists show next to the subject.
 */
final class EmailMessage
{
    /**
     * @param string                $subject     The subject line.
     * @param string                $heading     The headline at the top of the email.
     * @param list<string>          $paragraphs  Body paragraphs, in order.
     * @param string|null           $buttonLabel The main button's label, or null for none.
     * @param string|null           $buttonUrl   Where the main button goes.
     * @param list<array{0: string, 1: string}> $rows Label and value pairs.
     * @param string|null           $code        A short code shown large, or null.
     * @param string|null           $note        A highlighted box, or null.
     * @param bool                  $notePending Draw the note dashed: not happened yet.
     * @param string|null           $footnote    The quieter line after the button.
     * @param string|null           $preheader   The inbox preview line.
     * @param list<EmailAttachment> $attachments Files sent with it, such as an invoice PDF.
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $heading,
        public readonly array $paragraphs = [],
        public readonly ?string $buttonLabel = null,
        public readonly ?string $buttonUrl = null,
        public readonly array $rows = [],
        public readonly ?string $code = null,
        public readonly ?string $note = null,
        public readonly bool $notePending = false,
        public readonly ?string $footnote = null,
        public readonly ?string $preheader = null,
        public readonly array $attachments = [],
    ) {
    }
}
