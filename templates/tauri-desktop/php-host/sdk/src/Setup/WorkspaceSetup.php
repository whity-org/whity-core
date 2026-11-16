<?php

declare(strict_types=1);

namespace Whity\Sdk\Setup;

/**
 * Set up a workspace through core's own API, as the person who asked for it
 * (SDK 1.108, WHIT-1129).
 *
 * A host service: resolve it with `\Whity\app(\Whity\Sdk\Setup\WorkspaceSetup::class)`.
 *
 * Made for packs such as Appointments: a plugin that prepares a workspace for
 * one kind of business when the workspace turns it on. Call it from
 * `PluginWorkspaceLifecycleInterface::onWorkspaceEnabled()` or from one of the
 * plugin's own routes, while a person is acting in that workspace.
 *
 * Each call runs one core route exactly as an API request from that person
 * would: the same validation, the same audit, and only what that person's own
 * permissions allow. Nothing more is possible through here than the person
 * could do by hand. Only the routes in {@see self::ROUTES} are reachable:
 * calendar settings, services, staff and hours; workspace settings (name,
 * contact details, colour); forms; since 1.109, the people in the workspace
 * and inviting staff, for a guided setup
 * ({@see \Whity\Sdk\PluginOnboardingInterface}); and since 1.110, listing,
 * creating and checking in bookings, and listing and creating contacts.
 *
 * Without a workspace or a person in scope (a job, the CLI) every call answers
 * 403 and changes nothing.
 */
interface WorkspaceSetup
{
    /**
     * The routes a setup may call, as METHOD and path pattern.
     *
     * @var list<string>
     */
    public const ROUTES = [
        'GET /api/calendar/settings',
        'PUT /api/calendar/settings',
        'GET /api/calendar/services',
        'POST /api/calendar/services',
        'PATCH /api/calendar/services/{id}',
        'GET /api/calendar/staff',
        'PUT /api/calendar/staff/{profileId}',
        'GET /api/calendar/staff/{profileId}/hours',
        'PUT /api/calendar/staff/{profileId}/hours',
        'GET /api/settings',
        'PATCH /api/settings',
        'GET /api/forms',
        'POST /api/forms',
        'PUT /api/forms/{id}/fields',
        'POST /api/forms/{id}/publish',
        'POST /api/forms/{id}/public-link',
        // 1.109 (WHIT-1148): staff for a guided setup.
        'GET /api/users',
        'GET /api/invitations',
        'POST /api/invitations',
        'GET /api/branding',
        // 1.110 (WHIT-1154): a salon's walk-ins become bookings, its customers
        // contacts, and its commission is read from bookings.
        'GET /api/calendar/bookings',
        'POST /api/calendar/bookings',
        'POST /api/calendar/bookings/{id}/check-in',
        'GET /api/contacts',
        'POST /api/contacts',
    ];

    /**
     * @param string               $method GET, POST, PUT or PATCH
     * @param string               $path   e.g. `/api/calendar/services` or `/api/calendar/staff/4/hours`
     * @param array<string, mixed> $body   the JSON body, or query parameters for a GET
     * @return array{status: int, body: mixed} the route's status and decoded JSON body
     */
    public function call(string $method, string $path, array $body = []): array;
}
