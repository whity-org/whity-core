<?php

declare(strict_types=1);

namespace Whity\Core\Messaging\Email;

use Whity\Sdk\Messaging\InboundMessage;

/**
 * Reads one RFC 822 email into an {@see InboundMessage} (ADR 0016 §4).
 *
 * Deliberately small: headers (unfolded, RFC 2047 words decoded), the sender,
 * the threading ids, and the first text/plain and text/html parts, decoded from
 * quoted-printable or base64 and converted to UTF-8. Everything else is counted
 * as an attachment that was not kept. Nothing in the mail is executed or
 * fetched; the HTML part is stored and only ever shown sanitised.
 */
final class InboundEmailParser
{
    public const MAX_RAW_BYTES = 10 * 1024 * 1024;
    public const MAX_BODY_CHARS = 20000;
    private const MAX_DEPTH = 5;

    /** Null when the text is not a usable email (no sender, too large). */
    public static function parse(string $raw): ?InboundMessage
    {
        if ($raw === '' || strlen($raw) > self::MAX_RAW_BYTES) {
            return null;
        }
        $raw = str_replace("\r\n", "\n", $raw);
        [$headers, $body] = self::split($raw);

        [$fromAddress, $fromName] = self::address($headers['from'] ?? '');
        if ($fromAddress === null) {
            return null;
        }

        $text = null;
        $html = null;
        $attachments = 0;
        self::walk($headers, $body, $text, $html, $attachments, 0);

        if ($text === null && $html !== null) {
            $text = self::textFromHtml($html);
        }
        $text = trim((string) $text);
        if ($text === '' && $attachments === 0) {
            $text = '(empty message)';
        }

        $messageId = self::ids($headers['message-id'] ?? '')[0] ?? null;
        $inReplyTo = self::ids($headers['in-reply-to'] ?? '')[0] ?? null;

        return new InboundMessage(
            from: $fromAddress,
            fromName: $fromName,
            body: mb_substr($text, 0, self::MAX_BODY_CHARS),
            externalId: $messageId,
            subject: isset($headers['subject']) ? mb_substr(trim($headers['subject']), 0, 255) : null,
            htmlBody: $html !== null ? mb_substr($html, 0, self::MAX_BODY_CHARS * 5) : null,
            inReplyTo: $inReplyTo,
            references: self::ids($headers['references'] ?? ''),
            receivedAt: null,
            attachmentsNotShown: $attachments,
        );
    }

    /**
     * Strip `Re:`, `Fwd:` and their Arabic forms from the front of a subject,
     * however many there are.
     */
    public static function baseSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        while (preg_match('/^(re|fw|fwd|aw|sv|رد|إعادة توجيه|توجيه)\s*(\[\d+\])?\s*[:：]\s*/iu', $subject, $m) === 1) {
            $subject = trim(mb_substr($subject, mb_strlen($m[0])));
        }

        return $subject;
    }

    /**
     * @return array{0: array<string, string>, 1: string} lower-cased header name => decoded value, and the body
     */
    private static function split(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);

        $head = (string) preg_replace("/\n[ \t]+/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if (isset($headers[$name])) {
                continue; // the first of a repeated header wins
            }
            $headers[$name] = self::decodeWords(trim(substr($line, $colon + 1)));
        }

        return [$headers, $body];
    }

    /** @param array<string, string> $headers */
    private static function walk(array $headers, string $body, ?string &$text, ?string &$html, int &$attachments, int $depth): void
    {
        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain');
        $disposition = strtolower((string) ($headers['content-disposition'] ?? ''));

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                self::walk($partHeaders, $partBody, $text, $html, $attachments, $depth + 1);
            }

            return;
        }

        if (str_starts_with($disposition, 'attachment')) {
            $attachments++;

            return;
        }

        if ($type === 'text/plain' && $text === null) {
            $text = self::decodeBody($body, $headers['content-transfer-encoding'] ?? '', $params['charset'] ?? 'utf-8');

            return;
        }
        if ($type === 'text/html' && $html === null) {
            $html = self::decodeBody($body, $headers['content-transfer-encoding'] ?? '', $params['charset'] ?? 'utf-8');

            return;
        }

        $attachments++;
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $chunks = explode("\n" . $delimiter, "\n" . $body);
        array_shift($chunks); // preamble
        $parts = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // closing delimiter
            }
            $parts[] = ltrim(substr($chunk, (int) strpos($chunk . "\n", "\n") + 1), '');
        }

        return $parts;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $value): array
    {
        $pieces = explode(';', $value);
        $type = strtolower(trim((string) array_shift($pieces)));
        $params = [];
        foreach ($pieces as $piece) {
            $eq = strpos($piece, '=');
            if ($eq === false) {
                continue;
            }
            $params[strtolower(trim(substr($piece, 0, $eq)))] = trim(substr($piece, $eq + 1), " \t\"");
        }

        return [$type === '' ? 'text/plain' : $type, $params];
    }

    private static function decodeBody(string $body, string $encoding, string $charset): string
    {
        $encoding = strtolower(trim($encoding));
        if ($encoding === 'base64') {
            $body = (string) base64_decode((string) preg_replace('/\s+/', '', $body), false);
        } elseif ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        return self::toUtf8($body, $charset);
    }

    private static function toUtf8(string $value, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            try {
                $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
                if (is_string($converted)) {
                    $value = $converted;
                }
            } catch (\ValueError) {
                // An unknown charset: keep the bytes and scrub them below.
            }
        }

        return mb_scrub($value, 'UTF-8');
    }

    private static function decodeWords(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @mb_decode_mimeheader($value);

        return mb_scrub($decoded, 'UTF-8');
    }

    /** @return array{0: ?string, 1: ?string} address and display name */
    private static function address(string $value): array
    {
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m) === 1) {
            $name = trim(str_replace($m[0], '', $value), " \t\"'");

            return [strtolower($m[1]), $name !== '' ? mb_substr($name, 0, 255) : null];
        }
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return [strtolower($value), null];
        }

        return [null, null];
    }

    /** @return list<string> each `<id@host>` in the header, in order */
    private static function ids(string $value): array
    {
        preg_match_all('/<[^<>\s]+@[^<>\s]+>/', $value, $m);

        return array_values(array_unique($m[0]));
    }

    private static function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/li|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
