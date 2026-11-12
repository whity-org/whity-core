<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

/**
 * When "Chat" is in someone's menu: they work here (a member of the workspace,
 * not a customer). Team chat needs no permission; being staff and being in a
 * chat is the whole check, as for the Inbox.
 */
final class ChatNavConditions
{
    public function __construct(private readonly ChatRepository $chats)
    {
    }

    public function isStaff(int $profileId, int $tenantId): bool
    {
        return $this->chats->isStaff($tenantId, $profileId);
    }
}
