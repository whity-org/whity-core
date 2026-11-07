<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What a route travels on (WHIT-918, #1722).
 *
 * A route names a subject by TYPE and ID (migration 224). The provider for a type
 * is the one place that knows what such a subject is. Today there is one type,
 * `document`; form submissions (#1724) and plugin records (#1728) follow, and
 * this contract grows with them: editing and versions arrive with #1723, final
 * values with #1725. It moves to the SDK in #1728, once two core types have
 * proved its shape.
 *
 * A provider reads; it never decides who acts on a route. Recipients still come
 * only from routing rules, filtered to the route's tenant by the engine.
 */
interface RouteSubjectProviderInterface
{
    /** The type a route stores in `subject_type`, e.g. `document`. */
    public function type(): string;

    /**
     * The subject's title for the route and its inbox items, or null when there
     * is no such subject in this tenant. Null for another tenant's subject too,
     * so a caller cannot tell the two apart.
     */
    public function title(int $tenantId, int $subjectId): ?string;

    /**
     * The document this subject is recorded as, when it has one. A document is
     * its own; a subject without a document yet answers null.
     */
    public function documentId(int $tenantId, int $subjectId): ?int;
}
