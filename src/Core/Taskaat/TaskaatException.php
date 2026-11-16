<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

/**
 * A Taskaat connection or call failed in a way a person can act on. The message is plain words
 * for the person; the status is the HTTP status the API answers with. A state the screens show
 * in their own words carries a stable code, so they can label it in the reader's language.
 */
final class TaskaatException extends \RuntimeException
{
    /** Taskaat does not have the record tools yet (501): the screens show a translated note. */
    public const UPDATE_NEEDED = 'taskaat_update_needed';

    public function __construct(
        public readonly string $clientMessage,
        public readonly int $status = 502,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($clientMessage);
    }
}
