<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What a route's finalise step needs from its subject (WHIT-921, #1725).
 *
 * Implemented by a subject provider whose subjects can end in a final
 * document; a subject type that does not implement it cannot have a finalise
 * step. Kept apart from {@see RouteSubjectProviderInterface} so a provider that
 * only travels (a plugin record, #1728) owes nothing it does not use.
 */
interface RouteSubjectFinaliseInterface
{
    /**
     * The template a finalise step renders with when the step names none, or
     * null when the subject has no default. A form submission answers with its
     * form's "Final document template"; a document with its own template.
     */
    public function finalTemplateId(int $tenantId, int $subjectId): ?int;

    /**
     * The merge values the final document prints, taken from the APPROVED
     * version (what {@see RouteSubjectProviderInterface::snapshot()} recorded,
     * with its number and SHA-256), never from the subject as it is now.
     *
     * @param array{version: int, content_hash: string, data: mixed} $version
     * @param string $language The template's language, for choice labels.
     * @param array<string, mixed> $template The template version being printed.
     * @return array<string, string|null> Field path => printed value.
     */
    public function finalValues(int $tenantId, int $subjectId, array $version, string $language, array $template = []): array;

    /**
     * The record the final document is about, as `type:id` for the issuing
     * events, or null when it is the document itself.
     */
    public function finalRecord(int $tenantId, int $subjectId): ?string;

    /**
     * Record that the finalise step made this document for a subject that had
     * none. Called once, after the document exists.
     */
    public function attachDocument(int $tenantId, int $subjectId, int $documentId): void;
}
