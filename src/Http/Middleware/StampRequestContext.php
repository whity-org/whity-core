<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\RequestContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Tells a route handler its workspace, language and caller (WHIT-1015).
 *
 * Registered after tenant isolation and {@see ResolveLanguage}, because it
 * reports what those decided.
 *
 * NOBODY SIGNED IN IS NOT THE SYSTEM. {@see AuditContext::getActor()} answers
 * "system" when it has no person, which is right for an audit row and wrong
 * here: on a public form that would tell a plugin an anonymous visitor is the
 * host. So an unauthenticated request has no actor kind at all.
 *
 * It never fails a request, for the same reason {@see ResolveLanguage} doesn't.
 */
final class StampRequestContext
{
    public function __construct(private readonly ?LanguageRegistry $languages = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $workspace = TenantContext::getTenantId();

        RequestContext::stamp(
            $request,
            $workspace !== null && $workspace > 0 ? $workspace : null,
            $this->language(),
            is_object($request->user) ? $this->actorKind() : null,
        );

        return $next($request);
    }

    private function actorKind(): string
    {
        $kind = AuditContext::getActor()->kind;
        return in_array($kind, [Actor::PERSON, Actor::AGENT], true) ? $kind : Actor::SYSTEM;
    }

    private function language(): string
    {
        try {
            return $this->languages?->getCurrentLanguageCode() ?? LanguageRegistry::SOURCE_LANGUAGE;
        } catch (\Throwable) {
            return LanguageRegistry::SOURCE_LANGUAGE;
        }
    }
}
