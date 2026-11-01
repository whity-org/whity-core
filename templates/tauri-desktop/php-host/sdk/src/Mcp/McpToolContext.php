<?php

declare(strict_types=1);

namespace Whity\Sdk\Mcp;

/**
 * Who an authored MCP tool is acting for (SDK 1.46, #1419).
 *
 * The second argument the host passes to a {@see \Whity\Sdk\PluginMcpToolsInterface}
 * handler: `fn(array $arguments, McpToolContext $context): mixed`.
 *
 * WHY THIS EXISTS
 * ---------------
 * A route handler learns its tenant from the `Request` it is given. An authored
 * tool had no request, no principal and no context argument, so the only way
 * for it to scope a query was to reach into core's internal tenant state — a
 * class with no contract and no compatibility promise — or not to scope at all,
 * which in a multi-tenant plugin is a cross-tenant read. This is the MCP path's
 * equivalent of the request's JWT claims.
 *
 * WHICH TENANT
 * ------------
 * `tenantId` is the tenant the host has ALREADY authorized this call in: the
 * one the tool's `requiredRole` / `requiredPermission` gate was checked against.
 * A tool must scope every read and write to it. It is never a value the model
 * supplied, so an argument naming another tenant cannot widen what the tool
 * sees.
 *
 * AN OBJECT RATHER THAN MORE ARGUMENTS
 * ------------------------------------
 * So that adding a fact later (the principal kind, the granted scopes) is a new
 * property and not a signature change for every handler already written.
 *
 * WHO IS CALLING (SDK 1.59, WHIT-837)
 * -----------------------------------
 * `actorKind` says whether a person or an AI agent acting for them made the
 * call. For an agent, `agentId` is stable for that agent and person (across
 * token refreshes) and `agentName` is what to show ("Whity Builder"), so a
 * tool can record "agent X for person Y". `subject` is the person's Whity ID
 * `sub` when the call came in with a Whity ID token, for services that key on
 * Whity ID rather than on this instance's profile ids. The new values are
 * optional and default to "a person", so a host that predates them still
 * builds a valid context.
 * Passed explicitly, not read from ambient state, so it cannot be read at the
 * wrong moment or for the wrong call.
 *
 * Immutable value object.
 */
final class McpToolContext
{
    public const ACTOR_PERSON = 'person';
    public const ACTOR_AGENT  = 'agent';
    public const ACTOR_SYSTEM = 'system';

    /**
     * @param int         $tenantId      The tenant this call is authorized in. Scope every
     *                                   query to it.
     * @param int         $profileId     The calling person's profile id: the owner of the
     *                                   MCP token, or of the session, the call came in on.
     *                                   For an agent, the person it acts for.
     * @param string      $actorKind     `person`, `agent` (an AI agent acting for the
     *                                   person) or `system` (SDK 1.59).
     * @param string      $principalKind How the caller signed in: `session`, `user` (an
     *                                   MCP token), `oauth` (Whity ID) or `ai-agent` (SDK 1.59).
     * @param string|null $agentId       For an agent: a stable id for this agent and person.
     *                                   Null for a person (SDK 1.59).
     * @param string|null $agentName     For an agent: its display name, such as "Whity
     *                                   Builder". Null for a person (SDK 1.59).
     * @param string|null $subject       The person's Whity ID `sub` when the call came in
     *                                   with a Whity ID token; null otherwise (SDK 1.59).
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $profileId,
        public readonly string $actorKind = self::ACTOR_PERSON,
        public readonly string $principalKind = 'session',
        public readonly ?string $agentId = null,
        public readonly ?string $agentName = null,
        public readonly ?string $subject = null,
    ) {
    }

    /** Whether an AI agent made the call, acting for {@see self::$profileId}. */
    public function isAgent(): bool
    {
        return $this->actorKind === self::ACTOR_AGENT;
    }
}
