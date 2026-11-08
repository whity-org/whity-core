<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Request;

/** The SDK Request's context with no host behind it (WHIT-1015). */
final class RequestContextTest extends TestCase
{
    public function testARequestTheHostHasNotStampedClaimsNothing(): void
    {
        $request = new Request('GET', '/api/acme/things');

        self::assertNull($request->getWorkspaceId());
        self::assertSame(Request::DEFAULT_LANGUAGE, $request->getLanguage());
        self::assertNull($request->getActorKind());
        self::assertFalse($request->isAgent());
    }

    public function testAPluginsTestCanStandInForTheHost(): void
    {
        $request = new Request('GET', '/api/acme/things');
        $request->setAttribute(Request::ATTR_WORKSPACE_ID, 7);
        $request->setAttribute(Request::ATTR_LANGUAGE, 'ar');
        $request->setAttribute(Request::ATTR_ACTOR_KIND, Request::ACTOR_AGENT);

        self::assertSame(7, $request->getWorkspaceId());
        self::assertSame('ar', $request->getLanguage());
        self::assertTrue($request->isAgent());
    }

    public function testAnUnknownActorKindIsNoActor(): void
    {
        $request = new Request('GET', '/api/acme/things');
        $request->setAttribute(Request::ATTR_ACTOR_KIND, 'root');

        self::assertNull($request->getActorKind());
    }

    public function testNonPositiveWorkspaceIsNoWorkspace(): void
    {
        $request = new Request('GET', '/api/acme/things');
        $request->setAttribute(Request::ATTR_WORKSPACE_ID, 0);

        self::assertNull($request->getWorkspaceId());
    }
}
