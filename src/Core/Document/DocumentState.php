<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\Document\Routing\RouteVerdict;

/**
 * Where a document is (WHIT-684): derived on every read, never stored. Migration
 * 108 keeps a status column out of `documents` because routing's trail is the
 * answer, and a stored copy is the one that drifts.
 *
 * In order, the first that holds:
 *
 *   withdrawn   `withdrawn_at` is set (migration 201). The record stays readable.
 *   superseded  Another document says it replaces this one (`supersedes_id`).
 *   in_review   A route has an open item: `closed_by_event_id IS NULL`.
 *   draft       Nobody has the document and it isn't out yet: its newest route
 *               ended rejected, or it waits for a final approval to be numbered.
 *   issued      Everything else, which is every document nobody routes.
 *
 * "Ended rejected" reads the newest verdict on the newest route. A step's deciding
 * vote is always the last verdict of that step, because a decision closes the rest
 * of its cohort, so with nothing open the newest verdict is how the route ended.
 *
 * WHIT-679 (editable bodies, in design) has an open question about how draft-ness
 * is represented. This doesn't answer it and doesn't get in its way: having no
 * artifact is not what makes a document a draft here, because a document with no
 * PDF on an instance with no render service is a complete, issued one.
 */
final class DocumentState
{
    public const DRAFT = 'draft';
    public const IN_REVIEW = 'in_review';
    public const ISSUED = 'issued';
    public const SUPERSEDED = 'superseded';
    public const WITHDRAWN = 'withdrawn';

    public const ALL = [self::DRAFT, self::IN_REVIEW, self::ISSUED, self::SUPERSEDED, self::WITHDRAWN];

    private function __construct()
    {
    }

    /** A revision as people say it: 1 is A, 26 is Z, 27 is AA. */
    public static function revisionLetter(int $revision): string
    {
        $letters = '';
        for ($n = max($revision, 1); $n > 0; $n = (int) (($n - 1) / 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }

        return $letters;
    }

    /**
     * @param bool        $withdrawn     `withdrawn_at` is set.
     * @param bool        $superseded    A later revision replaced it.
     * @param bool        $routingOpen   A route on it has an open item.
     * @param string|null $latestVerdict The newest verdict on its newest route.
     * @param bool        $awaitsNumber  It has a numbering rule and no number yet.
     */
    public static function derive(bool $withdrawn, bool $superseded, bool $routingOpen, ?string $latestVerdict, bool $awaitsNumber): string
    {
        return match (true) {
            $withdrawn => self::WITHDRAWN,
            $superseded => self::SUPERSEDED,
            $routingOpen => self::IN_REVIEW,
            $latestVerdict === RouteVerdict::REJECTED, $awaitsNumber => self::DRAFT,
            default => self::ISSUED,
        };
    }
}
