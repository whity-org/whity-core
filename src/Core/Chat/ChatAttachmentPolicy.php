<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

/**
 * What a chat accepts as a file (UC.10): photos, videos, voice and everyday
 * documents, sniffed from their first bytes rather than trusted from the
 * browser, so a file named `.jpg` that is something else is refused.
 *
 * Types that a browser would run (HTML, SVG, scripts) are never accepted:
 * files are served back with their stored type and `nosniff`, but refusing
 * them keeps a chat from hosting anything that executes.
 */
final class ChatAttachmentPolicy
{
    /** The largest file a chat takes. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** The longest voice message, in milliseconds (5 minutes). */
    public const MAX_VOICE_MS = 5 * 60 * 1000;

    /** At most this many files on one message. */
    public const MAX_PER_MESSAGE = 10;

    /** @var array<string, array{kind: string, ext: string}> */
    private const ACCEPTED = [
        'image/jpeg' => ['kind' => 'image', 'ext' => 'jpg'],
        'image/png' => ['kind' => 'image', 'ext' => 'png'],
        'image/gif' => ['kind' => 'image', 'ext' => 'gif'],
        'image/webp' => ['kind' => 'image', 'ext' => 'webp'],
        'video/mp4' => ['kind' => 'video', 'ext' => 'mp4'],
        'video/webm' => ['kind' => 'video', 'ext' => 'webm'],
        'video/quicktime' => ['kind' => 'video', 'ext' => 'mov'],
        'audio/webm' => ['kind' => 'voice', 'ext' => 'weba'],
        'audio/ogg' => ['kind' => 'voice', 'ext' => 'ogg'],
        'audio/mp4' => ['kind' => 'voice', 'ext' => 'm4a'],
        'audio/mpeg' => ['kind' => 'voice', 'ext' => 'mp3'],
        'application/pdf' => ['kind' => 'file', 'ext' => 'pdf'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['kind' => 'file', 'ext' => 'docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['kind' => 'file', 'ext' => 'xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['kind' => 'file', 'ext' => 'pptx'],
        'text/plain' => ['kind' => 'file', 'ext' => 'txt'],
        'text/csv' => ['kind' => 'file', 'ext' => 'csv'],
        'application/zip' => ['kind' => 'file', 'ext' => 'zip'],
    ];

    private function __construct()
    {
    }

    /**
     * The type to store and its kind, or why the file is refused.
     *
     * @return array{content_type: string, kind: string, ext: string}
     *
     * @throws ChatException
     */
    public static function check(string $bytes, ?string $declared, ?string $filename): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new ChatException('That file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ChatException('That file is too large. Files can be up to 25 MB.');
        }
        $type = self::sniff($bytes, strtolower(trim((string) $declared)), strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)));
        if ($type === null || !isset(self::ACCEPTED[$type])) {
            throw new ChatException('Chat takes photos, videos, voice messages, PDF, Word, Excel, PowerPoint, text, CSV and zip files.');
        }

        return ['content_type' => $type] + self::ACCEPTED[$type];
    }

    /** Whether a stored type shows in the browser as itself (photos, videos, voice, PDF), or downloads. */
    public static function showsInline(string $contentType): bool
    {
        return str_starts_with($contentType, 'image/') || str_starts_with($contentType, 'video/')
            || str_starts_with($contentType, 'audio/') || $contentType === 'application/pdf';
    }

    /**
     * The type the bytes are. Containers that several types share (zip for
     * Office files, ISO media for mp4/m4a/mov, Matroska for webm audio and
     * video) are told apart by what the browser and the file name say, once
     * the bytes have shown which family it is.
     */
    private static function sniff(string $bytes, string $declared, string $ext): ?string
    {
        $head = substr($bytes, 0, 64);
        if (str_starts_with($head, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, 'OggS')) {
            return 'audio/ogg';
        }
        if (str_starts_with($head, 'ID3') || (strlen($head) > 1 && $head[0] === "\xff" && (ord($head[1]) & 0xe0) === 0xe0)) {
            return 'audio/mpeg';
        }
        if (str_starts_with($head, "\x1a\x45\xdf\xa3")) {
            // Matroska/WebM: audio when the browser recorded audio (a voice message).
            return str_starts_with($declared, 'audio/') ? 'audio/webm' : 'video/webm';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if ($brand === 'qt  ') {
                return 'video/quicktime';
            }
            if (str_starts_with($declared, 'audio/') || $brand === 'M4A ' || $ext === 'm4a') {
                return 'audio/mp4';
            }

            return 'video/mp4';
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            return match ($ext) {
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                default => 'application/zip',
            };
        }
        // Plain text: valid UTF-8 with no control bytes, and not markup a browser would run.
        if (in_array($ext, ['txt', 'csv'], true) && mb_check_encoding($bytes, 'UTF-8') && preg_match('/[\x00-\x08\x0e-\x1f]/', $bytes) !== 1
            && preg_match('/<\s*(script|html|svg|iframe)/i', substr($bytes, 0, 4096)) !== 1) {
            return $ext === 'csv' ? 'text/csv' : 'text/plain';
        }

        return null;
    }
}
