<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Router;

/**
 * A document reaches a person through a route, so that person can read it (WHIT-902).
 *
 * Reading a document is gated on `documents:read`, which a member with the default
 * role doesn't hold. Routing has never asked for it: being a recipient IS the
 * authorization (migration 112), and {@see DocumentVisibilityPolicy} already says a
 * recipient can see the record. The route's gate just ran first, so a person could be
 * told to review a document and then be refused it.
 *
 * WHY THE ROUTES KEEP THEIR PERMISSION. Dropping the gate would have made these routes
 * "ungated" to everything else that reads it: an MCP tool is offered and narrowed by
 * its route's permission, so an agent with `documents:read` switched off would gain
 * them. Keeping it leaves agents exactly as they were, because tools/call enforces the
 * permission itself and never comes through this pass. This is only for the HTTP gate.
 *
 * It asks one question and it can only say yes. Being refused here falls through to
 * the same 403 as before, for an id that exists and one that doesn't, so nothing is
 * revealed; a creator or a resource-role holder with no `documents:read` stays refused
 * too. The handler then runs its usual {@see DocumentVisibilityPolicy::canView()}.
 *
 * A past recipient counts. A closed item is still something that reached them, and
 * withdrawing a document keeps the record readable and closes open items (migration
 * 201), so they read a document marked withdrawn and can act on nothing.
 */
final class RecipientDocumentAccess
{
    /** The routes a recipient needs to open what reached them, and nothing that writes. */
    private const ROUTES = [
        '/api/documents/{id:\d+}',
        '/api/documents/{id:\d+}/content',
        '/api/documents/{id:\d+}/artifacts/{artifactId:\d+}/content',
    ];

    public static function allowOn(Router $router, RouteRecipientRepository $recipients): void
    {
        $isRecipient = static fn (int $profileId, int $tenantId, array $params): bool => $recipients->hasAnyForProfile(
            $tenantId,
            (int) ($params['id'] ?? 0),
            $profileId,
        );
        foreach (self::ROUTES as $path) {
            $router->allowRecordAccess('GET', $path, $isRecipient);
        }
    }
}
