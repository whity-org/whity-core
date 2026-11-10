<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Sdk\Http\Request;

/**
 * Stamps what a plugin's route handler reads from the SDK Request: workspace,
 * language and who is calling (WHIT-1015).
 *
 * The two ways a route is reached both end here, so they cannot disagree: the
 * kernel, through {@see Middleware\StampRequestContext}, and an MCP tool or
 * resource call, which runs the handler directly on a request of its own.
 *
 * The values live in attributes on the request instance, never in statics, so
 * a persistent worker cannot carry them into the next request.
 */
final class RequestContext
{
    public static function stamp(Request $request, ?int $workspaceId, string $language, ?string $actorKind): void
    {
        $request->setAttribute(Request::ATTR_WORKSPACE_ID, $workspaceId);
        $request->setAttribute(Request::ATTR_LANGUAGE, $language);
        $request->setAttribute(Request::ATTR_ACTOR_KIND, $actorKind);
    }

    /**
     * The language of a person, for a call that did not pass through
     * {@see Middleware\ResolveLanguage}. A language is never worth failing a
     * call over, so anything going wrong answers in the source language.
     */
    public static function languageOf(?RequestLanguageResolver $resolver, int $profileId): string
    {
        if ($resolver === null) {
            return LanguageRegistry::SOURCE_LANGUAGE;
        }
        try {
            return $resolver->resolve($profileId);
        } catch (\Throwable) {
            return LanguageRegistry::SOURCE_LANGUAGE;
        }
    }
}
