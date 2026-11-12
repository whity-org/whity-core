<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * One email a plugin sends as a workspace (SDK 1.99, WHIT-978): what it
 * says, never how it looks. The host draws it in the workspace's brand and
 * the EM.1 layout (`docs/design/handoff/emails/`): a heading, short
 * paragraphs, label-value rows (an invoice's number, total and due date), at
 * most one button, a grey note and the footer line saying why it was sent.
 *
 * Plain text everywhere: the host escapes it. A plugin cannot add HTML,
 * images or a second button, so every email a workspace sends looks like the
 * workspace and obeys the one-button rule.
 *
 * Immutable value object.
 */
final class WorkspaceEmail
{
    /**
     * @param string                            $heading    The headline.
     * @param list<string>                      $paragraphs Body paragraphs, in order.
     * @param list<array{0: string, 1: string}> $rows       Label and value pairs, such as ['Total', 'JOD 8.250'].
     * @param string|null                       $buttonLabel The one button, or null.
     * @param string|null                       $buttonUrl   Where it goes (https), required with a label.
     * @param string|null                       $note        The grey note, or null.
     * @param string|null                       $preheader   The inbox preview line.
     * @param string|null                       $notice      Why this was sent, in the footer.
     * @param string|null                       $language    'en' or 'ar'; null follows the workspace.
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $paragraphs = [],
        public readonly array $rows = [],
        public readonly ?string $buttonLabel = null,
        public readonly ?string $buttonUrl = null,
        public readonly ?string $note = null,
        public readonly ?string $preheader = null,
        public readonly ?string $notice = null,
        public readonly ?string $language = null,
    ) {
        if (trim($heading) === '') {
            throw new \InvalidArgumentException('An email needs a heading');
        }
        if (($buttonLabel === null) !== ($buttonUrl === null)) {
            throw new \InvalidArgumentException('A button needs both a label and a link');
        }
        if ($buttonUrl !== null && !str_starts_with($buttonUrl, 'https://') && !str_starts_with($buttonUrl, 'http://localhost')) {
            throw new \InvalidArgumentException('A button links to an https address');
        }
        if ($language !== null && !in_array($language, ['en', 'ar'], true)) {
            throw new \InvalidArgumentException('An email is in English (en) or Arabic (ar)');
        }
        // Checked at run time: the row type is PHPDoc only, and a plugin may pass anything.
        /** @var list<mixed> $given */
        $given = $rows;
        foreach ($given as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 2 || !is_string($row[0]) || !is_string($row[1])) {
                throw new \InvalidArgumentException('Each row is a [label, value] pair of strings');
            }
        }
    }
}
