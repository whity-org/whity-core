<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * What the host tells a plugin's public form handler (SDK 1.67, WHIT-906).
 *
 * A route declared `'public' => 'form'` runs only after the host has resolved
 * the tenant from the key in the path and passed its limits, Origin check and
 * bot check. {@see self::from()} reads the result. It never comes from anything
 * the caller sent, so use {@see self::$tenantId} and don't read a tenant from the
 * body, a header, the query string or the Host.
 *
 * The form's content is still yours to validate, and the handler answers
 * strangers: return a 4xx on bad input rather than throwing.
 */
final class PublicForm
{
    /** The request attribute the host sets. Read it through {@see self::from()}. */
    public const ATTRIBUTE = 'whity.public_form';

    /**
     * The header the page sends the bot-check widget's token in. The host reads it
     * and removes it before your handler runs.
     */
    public const BOT_TOKEN_HEADER = 'X-Whity-Bot-Token';

    public function __construct(
        public readonly int $tenantId,
        public readonly string $plugin,
        public readonly string $form,
        public readonly string $origin,
    ) {
    }

    /** The gate's result for this request, or null if the host set none. */
    public static function from(Request $request): ?self
    {
        $value = $request->getAttribute(self::ATTRIBUTE);

        return $value instanceof self ? $value : null;
    }
}
