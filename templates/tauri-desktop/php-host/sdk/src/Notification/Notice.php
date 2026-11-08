<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * What a plugin says to a person, in English and Arabic (WHIT-1030). The host
 * picks the language each recipient has chosen and falls back to English.
 *
 * Plain text only. `data` is structured context for a client to open the right
 * screen; it is stored with the notification, so keep it small and free of
 * anything the recipient should not see.
 */
final class Notice
{
    /**
     * @param array{en: string, ar: string} $subject At most 255 characters in each language.
     * @param array{en: string, ar: string} $body
     * @param array<string, mixed>          $data
     */
    public function __construct(
        public readonly array $subject,
        public readonly array $body,
        public readonly array $data = [],
    ) {
    }
}
