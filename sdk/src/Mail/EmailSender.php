<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * Sends a plugin's emails (WHIT-959). Take it from {@see PluginEmailSender}.
 *
 * `$key` is a BARE key from one of the plugin's declared
 * {@see EmailTemplate}s; anything else throws {@see \InvalidArgumentException},
 * as does an address that is not one. The email carries the brand of the
 * workspace the request or job is running in (the instance's brand when there
 * is none). `$locale` is `en` or `ar`; anything else is sent in English.
 */
interface EmailSender
{
    /**
     * Build and send now. A transport failure throws {@see \RuntimeException};
     * email is best-effort, so catch it where a failed send must not undo work.
     *
     * @param array<string, mixed> $data Passed to {@see EmailTemplate::build()}.
     */
    public function send(string $key, string $to, array $data = [], string $locale = 'en'): void;

    /**
     * Build without sending, to show what would go out.
     *
     * @param array<string, mixed> $data
     * @return array{subject: string, text: string, html: string}
     */
    public function preview(string $key, array $data = [], string $locale = 'en'): array;
}
