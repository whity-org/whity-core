<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

/**
 * Who is reading or writing in team chat: a person, or an agent working for a
 * person (CH.2).
 *
 * An agent is one workspace agent (`agents.id`) acting for one person, the
 * pair an agent assignment binds. In a chat it is the member with that agent
 * and that person as "works for"; it sees nothing else.
 */
final class ChatViewer
{
    private function __construct(
        public readonly int $profileId,
        public readonly ?int $agentId,
        public readonly string $agentName,
    ) {
    }

    public static function person(int $profileId): self
    {
        return new self($profileId, null, '');
    }

    /** @param int $worksFor the person the agent acts for (its assignment's person) */
    public static function agent(int $agentId, int $worksFor, string $name): self
    {
        return new self($worksFor, $agentId, $name);
    }

    public function isAgent(): bool
    {
        return $this->agentId !== null;
    }
}
