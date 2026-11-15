<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * A subject that names people in its answers (WHIT-1018): a form submission's
 * people questions, such as a supervisor. A step can leave those people out
 * ({@see RouteStepExclusions}).
 */
interface RouteSubjectPeopleInterface
{
    /**
     * The profiles the subject names in these questions, as it reads now.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    public function peopleNamedIn(int $tenantId, int $subjectId, array $keys): array;
}
