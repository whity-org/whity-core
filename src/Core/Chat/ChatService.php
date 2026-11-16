<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Db\DbBool;
use Whity\Core\Store\SharedStoreInterface;

/**
 * Team chat (CH.1, CH.2): groups and direct chats between staff, with agents as
 * clearly marked members.
 *
 * The rules this class keeps, whoever calls it (the web app or an agent's MCP
 * tool):
 *
 * - Only members see a chat. A chat the caller is not in answers "not found".
 * - An agent is a member for one person ("works for") and sees only the chats
 *   it is a member of for that person, and in each only the messages after its
 *   read-from mark: from when it joined unless the person who added it chose
 *   the last 7 days or everything.
 * - Adding an agent writes a system line saying who added it, who it works for,
 *   from when it reads and what it may do. Everyone in the chat sees it.
 * - An agent posts only when its "post" action is not off. On Ask first its
 *   line is held, shown dashed, until the person it works for approves it.
 * - An agent's proposal (an Ask-first tool call, `agent_proposals`) can be put
 *   in the chat; only the person it waits for can approve it, through
 *   `/api/me/agent-proposals/{id}/approve` as everywhere else.
 * - Agents never add people or agents, start chats, or change a chat.
 *
 * Chat lines are `note` / `noted` rows on the Messages tables, so no Messages
 * delivery, hold or cancel ever touches them.
 */
final class ChatService
{
    public const MAX_BODY = 4000;
    public const MAX_TITLE = 120;
    public const PAGE = 50;

    public const MODES = ['off', 'ask', 'allowed'];
    public const READS = ['now', 'week', 'all'];
    public const LINK_TYPES = ['board', 'meeting', 'record'];

    /** What an agent may do when the person adding it says nothing: post and reply. */
    public const DEFAULT_ACTIONS = ['post' => 'allowed'];

    public function __construct(
        private readonly ChatRepository $repo,
        // WHIT-1077: chat lines as web push. Null: no pushes (tests, MCP-only).
        private readonly ?ChatPushNotifier $push = null,
        // UC.11: who is typing, a few seconds at a time. Null: nobody shows as typing.
        private readonly ?SharedStoreInterface $store = null,
    ) {
    }

    /**
     * The person is looking at this chat in a focused window, or stopped
     * (WHIT-1077): no pushes for it while they are. Members only.
     */
    public function focus(int $tenantId, ChatViewer $viewer, int $chatId, bool $focused): void
    {
        $this->personOnly($viewer);
        $this->open($tenantId, $viewer, $chatId);
        $this->push?->focus($tenantId, $viewer->profileId, $chatId, $focused);
    }

    public function repository(): ChatRepository
    {
        return $this->repo;
    }

    /**
     * The caller for an MCP call: the agent behind `agent:<assignmentId>`, or
     * null when the principal is not a workspace agent (it then sees no chats).
     */
    public function agentViewer(int $tenantId, string $agentPrincipalId): ?ChatViewer
    {
        $assignmentId = AgentAssignmentRepository::idFromPrincipal($agentPrincipalId);
        if ($assignmentId === null) {
            return null;
        }
        $assignment = $this->repo->assignment($tenantId, $assignmentId);

        return $assignment === null ? null : ChatViewer::agent($assignment['agent_id'], $assignment['profile_id'], $assignment['name']);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> the viewer's chats, newest activity first */
    public function list(int $tenantId, ChatViewer $viewer): array
    {
        $rows = $viewer->isAgent()
            ? $this->repo->chatsForAgent($tenantId, (int) $viewer->agentId, $viewer->profileId)
            : $this->repo->chatsForPerson($tenantId, $viewer->profileId);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $members = $this->repo->membersOf($tenantId, $ids);
        $floors = [];
        foreach ($rows as $r) {
            $floors[(int) $r['id']] = (int) $r['reads_after_message_id'];
        }
        $last = $this->repo->lastMessages($tenantId, $floors);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $mark = max((int) $r['last_read_message_id'], (int) $r['reads_after_message_id']);
            $chat = $this->presentChat($r, $members[$id] ?? [], $viewer);
            $chat['unread'] = $this->repo->unreadCount($tenantId, $id, $mark, $viewer->profileId, $viewer->isAgent());
            $chat['last_message'] = isset($last[$id]) ? $this->presentMessages($tenantId, [$last[$id]], $viewer)[0] : null;
            if ($viewer->isAgent()) {
                $chat['mentions_me'] = $this->mentionsSince($tenantId, $id, $mark, (int) $viewer->agentId);
            }
            $out[] = $chat;
        }

        return $out;
    }

    /**
     * One chat with its members and its latest page of messages.
     *
     * @return array<string, mixed>
     */
    public function show(int $tenantId, ChatViewer $viewer, int $chatId): array
    {
        [$chat, $members, $me] = $this->open($tenantId, $viewer, $chatId);
        $floor = (int) $me['reads_after_message_id'];
        $messages = $this->repo->messages($tenantId, $chatId, $floor, null, null, self::PAGE);

        return $this->presentChat($chat, $members, $viewer) + [
            'members' => array_map(fn (array $m): array => $this->presentMember($m), $members),
            'messages' => $this->presentMessages($tenantId, $messages, $viewer),
            'has_earlier' => $messages !== [] && count($messages) === self::PAGE,
            'my_member_id' => (int) $me['id'],
            'last_read_message_id' => (int) $me['last_read_message_id'],
        ];
    }

    /**
     * Messages after `$after`, or the page before `$before`.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(int $tenantId, ChatViewer $viewer, int $chatId, ?int $after, ?int $before, int $limit): array
    {
        [, , $me] = $this->open($tenantId, $viewer, $chatId);
        $rows = $this->repo->messages($tenantId, $chatId, (int) $me['reads_after_message_id'], $after, $before, max(1, min($limit, 100)));

        return $this->presentMessages($tenantId, $rows, $viewer);
    }

    /**
     * Throws "not found" unless the viewer is in this team chat (for pinning it, UC.1).
     *
     * @throws ChatException
     */
    public function assertMember(int $tenantId, ChatViewer $viewer, int $chatId): void
    {
        $this->open($tenantId, $viewer, $chatId);
    }

    public function markRead(int $tenantId, ChatViewer $viewer, int $chatId, int $messageId): void
    {
        [, , $me] = $this->open($tenantId, $viewer, $chatId);
        $this->repo->markRead($tenantId, (int) $me['id'], min($messageId, $this->repo->maxMessageId($tenantId, $chatId)));
    }

    /**
     * People and agents of the workspace, for adding to a chat and for ＠.
     *
     * @return array{people: list<array<string, mixed>>, agents: list<array<string, mixed>>}
     */
    public function directory(int $tenantId, string $q): array
    {
        $q = trim(mb_substr($q, 0, 60));

        return [
            'people' => array_map(static fn (array $p): array => ['type' => 'person', 'id' => $p['id'], 'name' => $p['name']], $this->repo->staff($tenantId, $q, 20)),
            'agents' => array_map(static fn (array $a): array => [
                'type' => 'agent',
                'id' => (int) $a['id'],
                'name' => (string) $a['name'],
                'name_ar' => (string) $a['name_ar'],
                'role' => (string) $a['role'],
                'role_ar' => (string) $a['role_ar'],
            ], $this->repo->agents($tenantId, $q, 20)),
        ];
    }

    // ── Starting and changing chats ──────────────────────────────────────────

    /**
     * A new group chat with the creator and the people named.
     *
     * @param list<int> $people
     * @param array<string, mixed>|null $link
     */
    public function createGroup(int $tenantId, ChatViewer $viewer, string $title, array $people, ?array $link): int
    {
        $this->personOnly($viewer);
        $title = self::title($title);
        $link = self::link($link);
        $people = array_values(array_unique(array_filter(array_map('intval', $people), static fn (int $id): bool => $id > 0 && $id !== $viewer->profileId)));
        foreach ($people as $pid) {
            $this->staffOrFail($tenantId, $pid);
        }

        $id = $this->repo->createChat($tenantId, 'group', $title, null, $link, $viewer->profileId);
        $this->repo->addPerson($tenantId, $id, $viewer->profileId, null);
        foreach ($people as $pid) {
            $this->repo->addPerson($tenantId, $id, $pid, $viewer->profileId);
        }
        $by = $this->who($viewer->profileId);
        $this->system($tenantId, $id, $viewer->profileId, ['event' => 'created', 'by' => $by, 'title' => $title], "{$by['name']} started {$title}.");

        return $id;
    }

    /** The direct chat between the viewer and another person, started if new. */
    public function directWithPerson(int $tenantId, ChatViewer $viewer, int $profileId): int
    {
        $this->personOnly($viewer);
        if ($profileId === $viewer->profileId) {
            throw new ChatException('Choose someone other than yourself.');
        }
        $this->staffOrFail($tenantId, $profileId);
        $pair = [$viewer->profileId, $profileId];
        sort($pair);
        $key = 'p:' . $pair[0] . '|p:' . $pair[1];

        $existing = $this->repo->findDirect($tenantId, $key);
        if ($existing !== null) {
            $this->rejoin($tenantId, $existing, $viewer->profileId);

            return $existing;
        }
        $id = $this->repo->createChat($tenantId, 'direct', '', $key, null, $viewer->profileId);
        $this->repo->addPerson($tenantId, $id, $viewer->profileId, null);
        $this->repo->addPerson($tenantId, $id, $profileId, $viewer->profileId);

        return $id;
    }

    /** The viewer's direct chat with one of their agents, started if new. */
    public function directWithAgent(int $tenantId, ChatViewer $viewer, int $agentId): int
    {
        $this->personOnly($viewer);
        $agent = $this->agentOrFail($tenantId, $agentId);
        if (!$this->repo->worksFor($tenantId, $agentId, $viewer->profileId)) {
            throw new ChatException("{$agent['name']} doesn't work for you yet. Set it up in Agents first.");
        }
        $key = 'a:' . $agentId . '|p:' . $viewer->profileId;
        $existing = $this->repo->findDirect($tenantId, $key);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->repo->createChat($tenantId, 'direct', '', $key, null, $viewer->profileId);
        $this->repo->addPerson($tenantId, $id, $viewer->profileId, null);
        $this->repo->addAgent($tenantId, $id, $agentId, $viewer->profileId, 'all', 0, self::DEFAULT_ACTIONS, $viewer->profileId);

        return $id;
    }

    /**
     * Rename a group or change what the chat is linked to.
     *
     * @param array<string, mixed>|null $link
     */
    public function update(int $tenantId, ChatViewer $viewer, int $chatId, ?string $title, bool $setLink, ?array $link): void
    {
        $this->personOnly($viewer);
        [$chat] = $this->open($tenantId, $viewer, $chatId);
        $by = $this->who($viewer->profileId);
        if ($title !== null) {
            if ((string) $chat['type'] !== 'group') {
                throw new ChatException('Only a group chat has a name.');
            }
            $title = self::title($title);
            $this->repo->rename($tenantId, $chatId, $title);
            $this->system($tenantId, $chatId, $viewer->profileId, ['event' => 'renamed', 'by' => $by, 'title' => $title], "{$by['name']} named the chat {$title}.");
        }
        if ($setLink) {
            $link = self::link($link);
            $this->repo->setLink($tenantId, $chatId, $link);
            $this->system(
                $tenantId,
                $chatId,
                $viewer->profileId,
                ['event' => $link === null ? 'unlinked' : 'linked', 'by' => $by, 'link' => $link],
                $link === null ? "{$by['name']} removed the link." : "{$by['name']} linked the chat to the {$link['type']} {$link['label']}.",
            );
        }
    }

    // ── Members ──────────────────────────────────────────────────────────────

    public function addPerson(int $tenantId, ChatViewer $viewer, int $chatId, int $profileId): int
    {
        $this->personOnly($viewer);
        [$chat, $members] = $this->open($tenantId, $viewer, $chatId);
        $this->staffOrFail($tenantId, $profileId);
        foreach ($members as $m) {
            if ($m['profile_id'] !== null && (int) $m['profile_id'] === $profileId) {
                throw new ChatException('They are already in this chat.');
            }
        }
        if ((string) $chat['type'] === 'direct') {
            $this->repo->makeGroup($tenantId, $chatId);
        }
        $memberId = $this->repo->addPerson($tenantId, $chatId, $profileId, $viewer->profileId);
        $by = $this->who($viewer->profileId);
        $person = $this->who($profileId);
        $this->system($tenantId, $chatId, $viewer->profileId, ['event' => 'person_added', 'by' => $by, 'person' => $person], "{$by['name']} added {$person['name']}.");

        return $memberId;
    }

    /**
     * Add an agent: who it works for here, what it can read, what it may do (CH.2).
     *
     * @param array<string, mixed> $actions action => off | ask | allowed
     */
    public function addAgent(int $tenantId, ChatViewer $viewer, int $chatId, int $agentId, int $worksFor, string $reads, array $actions): int
    {
        $this->personOnly($viewer);
        [, $members] = $this->open($tenantId, $viewer, $chatId);
        $agent = $this->agentOrFail($tenantId, $agentId);
        if (!in_array($reads, self::READS, true)) {
            throw new ChatException('Choose what it can read: from now on, the last 7 days or everything.');
        }
        $actions = self::actions($actions);

        $forIsMember = false;
        foreach ($members as $m) {
            if ($m['agent_id'] !== null && (int) $m['agent_id'] === $agentId) {
                throw new ChatException("{$agent['name']} is already in this chat.");
            }
            if ($m['profile_id'] !== null && (int) $m['profile_id'] === $worksFor) {
                $forIsMember = true;
            }
        }
        if (!$forIsMember) {
            throw new ChatException('An agent works for someone in the chat. Add them first.');
        }
        if (!$this->repo->worksFor($tenantId, $agentId, $worksFor)) {
            $name = $this->who($worksFor)['name'];
            throw new ChatException("{$agent['name']} doesn't work for {$name} yet. {$name} can set it up in Agents.");
        }

        $floor = match ($reads) {
            'now' => $this->repo->maxMessageId($tenantId, $chatId),
            'week' => $this->repo->floorSince($tenantId, $chatId, gmdate('Y-m-d H:i:s', time() - 7 * 86400)),
            default => 0,
        };
        $memberId = $this->repo->addAgent($tenantId, $chatId, $agentId, $worksFor, $reads, $floor, $actions, $viewer->profileId);

        $by = $this->who($viewer->profileId);
        $for = $this->who($worksFor);
        $readsText = ['now' => 'from now on', 'week' => 'from the last 7 days', 'all' => 'everything in it'][$reads];
        $mayPost = ($actions['post'] ?? 'allowed') !== 'off';
        $this->system($tenantId, $chatId, $viewer->profileId, [
            'event' => 'agent_added',
            'by' => $by,
            'agent' => ['id' => $agentId, 'name' => (string) $agent['name'], 'name_ar' => (string) $agent['name_ar']],
            'works_for' => $for,
            'reads' => $reads,
            'actions' => $actions,
        ], "{$by['name']} added {$agent['name']}. It works for {$for['name']} here, reads this chat {$readsText}"
            . ($mayPost ? ' and can post.' : ' and cannot post.'));

        return $memberId;
    }

    /** Remove an agent (any person in the chat), leave (yourself), or remove a person (who started the chat). */
    public function removeMember(int $tenantId, ChatViewer $viewer, int $chatId, int $memberId): void
    {
        $this->personOnly($viewer);
        [$chat, $members] = $this->open($tenantId, $viewer, $chatId);
        $target = null;
        foreach ($members as $m) {
            if ((int) $m['id'] === $memberId) {
                $target = $m;
            }
        }
        if ($target === null) {
            throw new ChatException('They are not in this chat.', 404);
        }
        $by = $this->who($viewer->profileId);
        if ($target['agent_id'] !== null) {
            $this->repo->removeMember($tenantId, $memberId);
            $this->system($tenantId, $chatId, $viewer->profileId, [
                'event' => 'agent_removed', 'by' => $by,
                'agent' => ['id' => (int) $target['agent_id'], 'name' => (string) $target['agent_name']],
            ], "{$by['name']} removed {$target['agent_name']}.");

            return;
        }
        $pid = (int) $target['profile_id'];
        if ($pid === $viewer->profileId) {
            $this->repo->removeMember($tenantId, $memberId);
            $this->system($tenantId, $chatId, $viewer->profileId, ['event' => 'left', 'by' => $by], "{$by['name']} left.");

            return;
        }
        if ((string) $chat['type'] !== 'group' || (int) ($chat['created_by_profile_id'] ?? 0) !== $viewer->profileId) {
            throw new ChatException('Only the person who started this chat can remove people.', 403);
        }
        $this->repo->removeMember($tenantId, $memberId);
        $person = $this->who($pid);
        $this->system($tenantId, $chatId, $viewer->profileId, ['event' => 'person_removed', 'by' => $by, 'person' => $person], "{$by['name']} removed {$person['name']}.");
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * Post a line. `$mentions` are the people and agents ＠-mentioned (members
     * only; anything else is dropped). `$style` `line` is a status or hand-off
     * line ("handed the announcement to …"), shown small, agents only.
     *
     * @param list<mixed> $mentions
     * @return array<string, mixed> the posted message
     */
    public function post(
        int $tenantId,
        ChatViewer $viewer,
        int $chatId,
        string $body,
        array $mentions = [],
        string $style = 'message',
        ?int $replyTo = null,
        ?int $threadRoot = null,
        bool $alsoToChat = false,
    ): array {
        [$chat, $members, $me] = $this->open($tenantId, $viewer, $chatId);
        // UC.6: a reply quotes the message it answers; a thread reply sits under its root.
        $root = $threadRoot !== null ? $this->target($tenantId, $viewer, $chatId, $threadRoot, 'reply in a thread to') : null;
        if ($root !== null && $root['thread_root_id'] !== null) {
            $root = $this->target($tenantId, $viewer, $chatId, (int) $root['thread_root_id'], 'reply in a thread to');
        }
        if ($root !== null && $root['deleted_at'] !== null) {
            throw new ChatException('This message was deleted.');
        }
        $quoted = $replyTo !== null ? $this->target($tenantId, $viewer, $chatId, $replyTo, 'reply to') : null;
        $body = trim($body);
        if ($body === '') {
            throw new ChatException('The message is empty.');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new ChatException('The message is too long (' . self::MAX_BODY . ' characters at most).');
        }

        $meta = [];
        $kept = self::mentions($mentions, $members, (string) $chat['type'] === 'group');
        if ($kept !== []) {
            $meta['mentions'] = $kept;
        }
        $status = 'noted';
        if ($viewer->isAgent()) {
            $meta['agent'] = ['id' => (int) $viewer->agentId, 'name' => $viewer->agentName];
            if ($style === 'line') {
                $meta['style'] = 'line';
            }
            $mode = self::mode($me, 'post');
            if ($mode === 'off') {
                throw new ChatException("{$viewer->agentName} may not post in this chat.", 403);
            }
            if ($mode === 'ask') {
                $meta['held'] = ['by' => $this->who($viewer->profileId)];
            }
        }

        $id = $this->repo->insertMessage(
            $tenantId,
            $chatId,
            $viewer->profileId,
            $viewer->isAgent(),
            $viewer->isAgent() ? 'chat.agent' : 'chat.person',
            $body,
            $meta,
            $status,
            $quoted !== null ? (int) $quoted['id'] : null,
            $root !== null ? (int) $root['id'] : null,
            $alsoToChat,
        );
        if ($root !== null) {
            // The root's "n replies" changed: open chats fetch it again.
            $this->repo->touch($tenantId, (int) $root['id']);
        }
        if (!$viewer->isAgent()) {
            $this->repo->markRead($tenantId, (int) $me['id'], $id);
        }
        // A held line reaches people only once it is approved (decideHeld).
        if (!isset($meta['held'])) {
            $this->pushLine($tenantId, $chatId, $id, $viewer->isAgent() ? null : $viewer->profileId, $viewer->isAgent() ? $viewer->agentName : $this->who($viewer->profileId)['name'], $body, $kept);
        }

        return $this->presented($tenantId, $id, $viewer);
    }

    /**
     * An agent puts one of its waiting proposals in the chat: a dashed card
     * that only the person it waits for can approve.
     *
     * @return array<string, mixed>
     */
    public function shareProposal(int $tenantId, ChatViewer $viewer, int $chatId, int $proposalId, string $agentPrincipalId, string $body): array
    {
        if (!$viewer->isAgent()) {
            throw new ChatException('Only an agent shares its proposals in a chat.', 403);
        }
        [, , $me] = $this->open($tenantId, $viewer, $chatId);
        if (self::mode($me, 'post') === 'off') {
            throw new ChatException("{$viewer->agentName} may not post in this chat.", 403);
        }
        $proposal = $this->repo->proposals($tenantId, [$proposalId])[$proposalId] ?? null;
        if ($proposal === null
            || (string) $proposal['agent_principal_id'] !== $agentPrincipalId
            || (int) $proposal['on_behalf_of_profile_id'] !== $viewer->profileId) {
            throw new ChatException('Proposal not found', 404);
        }
        $body = trim($body) !== '' ? trim($body) : (string) $proposal['summary'];
        $id = $this->repo->insertMessage($tenantId, $chatId, $viewer->profileId, true, 'chat.proposal', mb_substr($body, 0, self::MAX_BODY), [
            'agent' => ['id' => (int) $viewer->agentId, 'name' => $viewer->agentName],
            'proposal_id' => $proposalId,
        ]);

        return $this->presented($tenantId, $id, $viewer);
    }

    /**
     * Approve or decline an agent's held line. Only the person the agent works
     * for decides.
     *
     * @return array<string, mixed>
     */
    public function decideHeld(int $tenantId, ChatViewer $viewer, int $messageId, bool $approve): array
    {
        $this->personOnly($viewer);
        $message = $this->repo->message($tenantId, $messageId);
        if ($message === null) {
            throw new ChatException('Message not found', 404);
        }
        $this->open($tenantId, $viewer, (int) $message['conversation_id']);
        $meta = $message['chat_meta'];
        $held = $meta['held'] ?? null;
        if (!is_array($held) || (string) $message['status'] !== 'noted') {
            throw new ChatException('This message is not waiting for anyone.', 409);
        }
        if ((int) ($held['by']['id'] ?? 0) !== $viewer->profileId) {
            throw new ChatException('Only ' . (string) ($held['by']['name'] ?? 'the person it works for') . ' can decide this.', 403);
        }
        unset($meta['held']);
        $meta['decided'] = ['by' => $this->who($viewer->profileId), 'approved' => $approve];
        $this->repo->updateMessage($tenantId, $messageId, $meta, $approve ? 'noted' : 'cancelled');
        if ($approve) {
            $agentName = is_array($meta['agent'] ?? null) ? (string) ($meta['agent']['name'] ?? '') : '';
            $mentioned = is_array($meta['mentions'] ?? null) ? array_values(array_filter($meta['mentions'], 'is_array')) : [];
            $this->pushLine($tenantId, (int) $message['conversation_id'], $messageId, null, $agentName, (string) $message['body_text'], $mentioned);
        }

        return $this->presented($tenantId, $messageId, $viewer);
    }

    /**
     * Hand a line to the push notifier. Never stops the post.
     *
     * @param list<array<string, mixed>> $mentions
     */
    private function pushLine(int $tenantId, int $chatId, int $messageId, ?int $authorProfileId, string $authorName, string $body, array $mentions): void
    {
        if ($this->push === null) {
            return;
        }
        try {
            $chat = $this->repo->findChat($tenantId, $chatId);
            if ($chat === null) {
                return;
            }
            $members = $this->repo->membersOf($tenantId, [$chatId])[$chatId] ?? [];
            $kept = [];
            foreach ($mentions as $m) {
                $kept[] = ['type' => (string) ($m['type'] ?? ''), 'id' => (int) ($m['id'] ?? 0), 'name' => (string) ($m['name'] ?? '')];
            }
            $this->push->posted($tenantId, $chat, $members, $messageId, $authorProfileId, $authorName, $body, $kept);
        } catch (\Throwable) {
            // A push problem must never cost anyone their message.
        }
    }

    // ── Presenting ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>       $chat
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private function presentChat(array $chat, array $members, ChatViewer $viewer): array
    {
        $people = 0;
        $agents = 0;
        $others = [];
        foreach ($members as $m) {
            if ($m['agent_id'] !== null) {
                $agents++;
            } else {
                $people++;
            }
            $self = $viewer->isAgent()
                ? $m['agent_id'] !== null && (int) $m['agent_id'] === $viewer->agentId
                : $m['profile_id'] !== null && (int) $m['profile_id'] === $viewer->profileId;
            if (!$self) {
                $others[] = $m;
            }
        }
        $type = (string) $chat['type'];
        $title = (string) ($chat['subject'] ?? '');
        $with = null;
        if ($type === 'direct' && $others !== []) {
            $with = $this->presentMember($others[0]);
            $title = (string) $with['name'];
        }

        return [
            'id' => (int) $chat['id'],
            'type' => $type,
            'title' => $title,
            'with' => $with,
            'people_count' => $people,
            'agents_count' => $agents,
            'link' => $chat['link_type'] !== null
                ? ['type' => (string) $chat['link_type'], 'id' => (string) $chat['link_id'], 'label' => (string) $chat['link_label']]
                : null,
            'last_message_at' => self::iso($chat['last_message_at']),
            'created_at' => self::iso($chat['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function presentMember(array $m): array
    {
        if ($m['agent_id'] !== null) {
            return [
                'member_id' => (int) $m['id'],
                'type' => 'agent',
                'agent_id' => (int) $m['agent_id'],
                'name' => (string) ($m['agent_name'] ?? ''),
                'name_ar' => (string) ($m['agent_name_ar'] ?? ''),
                'role' => (string) ($m['agent_role'] ?? ''),
                'role_ar' => (string) ($m['agent_role_ar'] ?? ''),
                'works_for' => ['id' => (int) $m['works_for_profile_id'], 'name' => (string) ($m['works_for_name'] ?? '')],
                'reads' => (string) $m['reads'],
                // When its reading starts: when it joined, a week before that, or the start (null).
                'reads_from' => match ((string) $m['reads']) {
                    'now' => self::iso($m['joined_at']),
                    'week' => self::iso(gmdate('Y-m-d H:i:s', (int) strtotime((string) $m['joined_at'] . ' UTC') - 7 * 86400)),
                    default => null,
                },
                'actions' => self::actions($m['actions'] === [] ? self::DEFAULT_ACTIONS : $m['actions']),
                'added_by' => $m['added_by_profile_id'] !== null ? ['id' => (int) $m['added_by_profile_id'], 'name' => (string) ($m['added_by_name'] ?? '')] : null,
                'joined_at' => self::iso($m['joined_at']),
            ];
        }

        return [
            'member_id' => (int) $m['id'],
            'type' => 'person',
            'profile_id' => (int) $m['profile_id'],
            'name' => (string) ($m['person_name'] ?? ''),
            'added_by' => $m['added_by_profile_id'] !== null ? ['id' => (int) $m['added_by_profile_id'], 'name' => (string) ($m['added_by_name'] ?? '')] : null,
            'joined_at' => self::iso($m['joined_at']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentMessages(int $tenantId, array $rows, ChatViewer $viewer): array
    {
        $proposalIds = [];
        foreach ($rows as $r) {
            if (isset($r['chat_meta']['proposal_id'])) {
                $proposalIds[] = (int) $r['chat_meta']['proposal_id'];
            }
        }
        $proposals = $this->repo->proposals($tenantId, $proposalIds);

        // UC.6–UC.12: what each message carries beyond its words.
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $quotedIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['reply_to_message_id'] ?? 0), $rows))));
        $quoted = $this->repo->messagesByIds($tenantId, $quotedIds);
        $threads = $this->repo->threadSummaries($tenantId, $ids, 0);
        $reactions = $this->repo->reactions($tenantId, $ids);

        return array_map(function (array $r) use ($proposals, $viewer, $quoted, $threads, $reactions): array {
            $meta = $r['chat_meta'];
            $source = (string) $r['source'];
            $kind = match (true) {
                $source === 'chat.system' => 'system',
                $source === 'chat.proposal' => 'proposal',
                ($meta['style'] ?? null) === 'line' => 'line',
                default => 'message',
            };
            $author = null;
            if ($kind !== 'system' && $r['author_profile_id'] !== null) {
                $author = $r['by_agent']
                    ? [
                        'type' => 'agent',
                        'agent_id' => (int) ($meta['agent']['id'] ?? 0),
                        'name' => (string) ($meta['agent']['name'] ?? ''),
                        'works_for' => ['id' => (int) $r['author_profile_id'], 'name' => (string) ($r['author_name'] ?? '')],
                    ]
                    : ['type' => 'person', 'profile_id' => (int) $r['author_profile_id'], 'name' => (string) ($r['author_name'] ?? '')];
            }
            $held = is_array($meta['held'] ?? null) ? $meta['held'] : null;
            $proposal = null;
            if ($kind === 'proposal') {
                $p = $proposals[(int) ($meta['proposal_id'] ?? 0)] ?? null;
                $proposal = $p === null ? null : [
                    'id' => (int) $p['id'],
                    'tool' => (string) $p['tool'],
                    'summary' => (string) $p['summary'],
                    'status' => (string) $p['status'],
                    'waiting_for' => ['id' => (int) $p['on_behalf_of_profile_id'], 'name' => (string) ($p['decider_name'] ?? '')],
                    'can_decide' => !$viewer->isAgent() && (int) $p['on_behalf_of_profile_id'] === $viewer->profileId,
                    'expires_at' => self::iso($p['expires_at']),
                ];
            }

            $deleted = ($r['deleted_at'] ?? null) !== null;
            $id = (int) $r['id'];
            $q = $quoted[(int) ($r['reply_to_message_id'] ?? 0)] ?? null;
            $mine = !$viewer->isAgent() && !$r['by_agent'] && $r['author_profile_id'] !== null
                && (int) $r['author_profile_id'] === $viewer->profileId && $source === 'chat.person';
            $sent = strtotime((string) $r['created_at'] . ' UTC');
            $grouped = [];
            foreach ($reactions[$id] ?? [] as $x) {
                $g = $grouped[$x['emoji']] ?? ['emoji' => $x['emoji'], 'count' => 0, 'mine' => false, 'people' => []];
                $g['count']++;
                $g['mine'] = $g['mine'] || (!$viewer->isAgent() && $x['profile_id'] === $viewer->profileId);
                $g['people'][] = $x['name'];
                $grouped[$x['emoji']] = $g;
            }
            $thread = $threads[$id] ?? null;

            $parts = [
                'reply_to' => $q === null ? null : [
                    'id' => (int) $q['id'],
                    'author_name' => $q['by_agent'] ? (string) ($q['chat_meta']['agent']['name'] ?? '') : (string) ($q['author_name'] ?? ''),
                    'body' => $q['deleted_at'] !== null ? '' : mb_substr((string) $q['body_text'], 0, 160),
                    'deleted' => $q['deleted_at'] !== null,
                ],
                'thread_root_id' => isset($r['thread_root_id']) ? (int) $r['thread_root_id'] : null,
                'in_chat' => isset($r['in_chat']) ? DbBool::of($r['in_chat']) : true,
                'thread' => $thread === null ? null : [
                    'count' => $thread['count'],
                    'last_at' => self::iso($thread['last_at']),
                    'people' => array_slice($thread['people'], 0, 3),
                ],
                'reactions' => array_values($grouped),
                'pinned' => ($r['pinned_at'] ?? null) !== null,
                'edited' => ($r['edited_at'] ?? null) !== null,
                'deleted' => $deleted,
                'can_edit' => $mine && !$deleted,
                'can_delete' => $mine && !$deleted && $sent !== false && time() - $sent <= self::DELETE_WINDOW_SECONDS,
            ];

            return $parts + [
                'id' => $id,
                'chat_id' => (int) $r['conversation_id'],
                'kind' => $kind,
                'body' => $deleted ? '' : (string) $r['body_text'],
                'author' => $author,
                // Rebuilt in a fixed shape: jsonb does not keep key order.
                'mentions' => array_values(array_map(
                    static fn (mixed $m): array => [
                        'type' => (string) (is_array($m) ? ($m['type'] ?? '') : ''),
                        'id' => (int) (is_array($m) ? ($m['id'] ?? 0) : 0),
                        'name' => (string) (is_array($m) ? ($m['name'] ?? '') : ''),
                    ],
                    is_array($meta['mentions'] ?? null) ? $meta['mentions'] : [],
                )),
                'status' => $held !== null ? 'held' : ((string) $r['status'] === 'cancelled' ? 'declined' : 'posted'),
                'waiting_for' => $held !== null ? $held['by'] : null,
                'can_decide' => $held !== null && !$viewer->isAgent() && (int) ($held['by']['id'] ?? 0) === $viewer->profileId,
                'proposal' => $proposal,
                'event' => $kind === 'system' ? array_diff_key($meta, ['mentions' => true]) : null,
                'created_at' => self::iso($r['created_at']),
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function presented(int $tenantId, int $messageId, ChatViewer $viewer): array
    {
        $row = $this->repo->message($tenantId, $messageId);
        if ($row === null) {
            throw new ChatException('Message not found', 404);
        }

        return $this->presentMessages($tenantId, [$row], $viewer)[0];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The chat, its members and the viewer's own member row, or "not found"
     * when the viewer is not in it.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function open(int $tenantId, ChatViewer $viewer, int $chatId): array
    {
        $chat = $this->repo->findChat($tenantId, $chatId);
        if ($chat === null) {
            throw ChatException::notFound();
        }
        $members = $this->repo->membersOf($tenantId, [$chatId])[$chatId] ?? [];
        foreach ($members as $m) {
            $mine = $viewer->isAgent()
                ? $m['agent_id'] !== null && (int) $m['agent_id'] === $viewer->agentId && (int) $m['works_for_profile_id'] === $viewer->profileId
                : $m['profile_id'] !== null && (int) $m['profile_id'] === $viewer->profileId;
            if ($mine) {
                return [$chat, $members, $m];
            }
        }

        throw ChatException::notFound();
    }

    private function rejoin(int $tenantId, int $chatId, int $profileId): void
    {
        foreach ($this->repo->membersOf($tenantId, [$chatId])[$chatId] ?? [] as $m) {
            if ($m['profile_id'] !== null && (int) $m['profile_id'] === $profileId) {
                return;
            }
        }
        $this->repo->addPerson($tenantId, $chatId, $profileId, null);
    }

    private function mentionsSince(int $tenantId, int $chatId, int $mark, int $agentId): int
    {
        $count = 0;
        foreach ($this->repo->messages($tenantId, $chatId, $mark, $mark, null, 100) as $r) {
            foreach ((array) ($r['chat_meta']['mentions'] ?? []) as $m) {
                if (is_array($m) && ($m['type'] ?? null) === 'agent' && (int) ($m['id'] ?? 0) === $agentId) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $meta */
    private function system(int $tenantId, int $chatId, int $actor, array $meta, string $text): void
    {
        $this->repo->insertMessage($tenantId, $chatId, $actor, false, 'chat.system', $text, $meta);
    }

    /** @return array{id: int, name: string} */
    private function who(int $profileId): array
    {
        return ['id' => $profileId, 'name' => $this->repo->personName($profileId)];
    }

    private function personOnly(ChatViewer $viewer): void
    {
        if ($viewer->isAgent()) {
            throw new ChatException('Agents join chats when a person adds them; they cannot start or change chats.', 403);
        }
    }

    private function staffOrFail(int $tenantId, int $profileId): void
    {
        if ($profileId <= 0 || !$this->repo->isStaff($tenantId, $profileId)) {
            throw new ChatException('That person is not in this workspace.');
        }
    }

    /** @return array<string, mixed> */
    private function agentOrFail(int $tenantId, int $agentId): array
    {
        $agent = $agentId > 0 ? $this->repo->agent($tenantId, $agentId) : null;
        if ($agent === null) {
            throw new ChatException('That agent is not available in this workspace.');
        }

        return $agent;
    }

    /** @param array<string, mixed> $member */
    private static function mode(array $member, string $action): string
    {
        $actions = is_array($member['actions'] ?? null) && $member['actions'] !== [] ? $member['actions'] : self::DEFAULT_ACTIONS;
        $mode = $actions[$action] ?? 'off';

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    private static function title(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new ChatException('Give the group a name.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new ChatException('The name is too long (' . self::MAX_TITLE . ' characters at most).');
        }

        return $title;
    }

    /**
     * @param array<string, mixed>|null $link
     * @return array{type: string, id: string, label: string}|null
     */
    private static function link(?array $link): ?array
    {
        if ($link === null) {
            return null;
        }
        $type = (string) ($link['type'] ?? '');
        $id = trim((string) ($link['id'] ?? ''));
        $label = trim((string) ($link['label'] ?? ''));
        if (!in_array($type, self::LINK_TYPES, true) || $id === '' || strlen($id) > 64 || $label === '' || mb_strlen($label) > 255) {
            throw new ChatException('A chat can be linked to a board, a meeting or a record, with its id and name.');
        }

        return ['type' => $type, 'id' => $id, 'label' => $label];
    }

    /**
     * @param array<mixed> $actions
     * @return array<string, string>
     */
    private static function actions(array $actions): array
    {
        if (count($actions) > 20) {
            throw new ChatException('Too many actions.');
        }
        $out = [];
        foreach ($actions as $action => $mode) {
            if (!is_string($action) || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $action) !== 1 || !is_string($mode) || !in_array($mode, self::MODES, true)) {
                throw new ChatException('Each action is off, ask or allowed.');
            }
            $out[$action] = $mode;
        }

        return $out + self::DEFAULT_ACTIONS;
    }

    /**
     * @param list<mixed>                $mentions
     * @param list<array<string, mixed>> $members
     * @return list<array{type: string, id: int, name: string}>
     */
    private static function mentions(array $mentions, array $members, bool $group = false): array
    {
        $kept = [];
        foreach (array_slice($mentions, 0, 20) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $type = $m['type'] ?? null;
            // UC.10: @everyone tells every person in a group.
            if ($type === 'everyone' && $group) {
                $kept['everyone'] = ['type' => 'everyone', 'id' => 0, 'name' => 'everyone'];
                continue;
            }
            $id = (int) ($m['id'] ?? 0);
            foreach ($members as $member) {
                if ($type === 'person' && $member['profile_id'] !== null && (int) $member['profile_id'] === $id) {
                    $kept[$type . $id] = ['type' => 'person', 'id' => $id, 'name' => (string) $member['person_name']];
                } elseif ($type === 'agent' && $member['agent_id'] !== null && (int) $member['agent_id'] === $id) {
                    $kept[$type . $id] = ['type' => 'agent', 'id' => $id, 'name' => (string) $member['agent_name']];
                }
            }
        }

        return array_values($kept);
    }

    // ── The parts of a message (UC.6–UC.12) ──────────────────────────────────

    /** How long a person can delete their own message for everyone (UC.12). */
    public const DELETE_WINDOW_SECONDS = 2 * 86400;

    /** Seconds a "typing" beat lasts; the browser beats while the person types. */
    public const TYPING_SECONDS = 6;

    /**
     * Edit your own message: "edited" shows on it. Mentions still in the words stay.
     *
     * @return array<string, mixed>
     */
    public function edit(int $tenantId, ChatViewer $viewer, int $messageId, string $body): array
    {
        $this->personOnly($viewer);
        [$message] = $this->ownMessage($tenantId, $viewer, $messageId);
        $body = trim($body);
        if ($body === '') {
            throw new ChatException('The message is empty. To take it back, remove the message instead.');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new ChatException('The message is too long (' . self::MAX_BODY . ' characters at most).');
        }
        $meta = $message['chat_meta'];
        if (is_array($meta['mentions'] ?? null)) {
            $meta['mentions'] = array_values(array_filter(
                $meta['mentions'],
                static fn (mixed $m): bool => is_array($m) && str_contains($body, '@' . (string) ($m['name'] ?? '')),
            ));
            if ($meta['mentions'] === []) {
                unset($meta['mentions']);
            }
        }
        $this->repo->editMessage($tenantId, $messageId, $body, $meta);

        return $this->presented($tenantId, $messageId, $viewer);
    }

    /**
     * Delete your own message for everyone, within two days of sending it: it
     * stays in place as "This message was deleted".
     *
     * @return array<string, mixed>
     */
    public function delete(int $tenantId, ChatViewer $viewer, int $messageId): array
    {
        $this->personOnly($viewer);
        [$message] = $this->ownMessage($tenantId, $viewer, $messageId);
        $sent = strtotime((string) $message['created_at'] . ' UTC');
        if ($sent !== false && time() - $sent > self::DELETE_WINDOW_SECONDS) {
            throw new ChatException('A message can be deleted for everyone up to 2 days after it was sent.', 422);
        }
        $this->repo->deleteMessage($tenantId, $messageId);

        return $this->presented($tenantId, $messageId, $viewer);
    }

    /**
     * Add a reaction, or take it back when you already gave it.
     *
     * @return array<string, mixed> the message with its reactions
     */
    public function react(int $tenantId, ChatViewer $viewer, int $messageId, string $emoji): array
    {
        $this->personOnly($viewer);
        $emoji = trim($emoji);
        // One emoji (a few code points with skin tones and joiners), never words or markup.
        if ($emoji === '' || mb_strlen($emoji) > 8 || strlen($emoji) > 32 || preg_match('/[\p{L}\p{N}<>&"\x27\s]/u', $emoji) === 1) {
            throw new ChatException('Pick an emoji to react with.');
        }
        $message = $this->messageInChat($tenantId, $viewer, $messageId);
        if ($message['deleted_at'] !== null) {
            throw new ChatException('This message was deleted.');
        }
        $this->repo->toggleReaction($tenantId, $messageId, $viewer->profileId, $emoji);

        return $this->presented($tenantId, $messageId, $viewer);
    }

    /**
     * Pin a message to the top of its chat for everyone in it, or unpin it.
     *
     * @return array<string, mixed>
     */
    public function pinMessage(int $tenantId, ChatViewer $viewer, int $messageId, bool $pinned): array
    {
        $this->personOnly($viewer);
        $message = $this->messageInChat($tenantId, $viewer, $messageId);
        if ($pinned && $message['deleted_at'] !== null) {
            throw new ChatException('This message was deleted.');
        }
        $this->repo->setPinned($tenantId, $messageId, $pinned ? $viewer->profileId : null);

        return $this->presented($tenantId, $messageId, $viewer);
    }

    /**
     * A message and the replies in its thread (UC.6).
     *
     * @return array{root: array<string, mixed>, replies: list<array<string, mixed>>}
     */
    public function thread(int $tenantId, ChatViewer $viewer, int $messageId): array
    {
        $root = $this->messageInChat($tenantId, $viewer, $messageId);
        if ($root['thread_root_id'] !== null) {
            $root = $this->messageInChat($tenantId, $viewer, (int) $root['thread_root_id']);
        }
        [, , $me] = $this->open($tenantId, $viewer, (int) $root['conversation_id']);
        $replies = $this->repo->threadMessages($tenantId, (int) $root['conversation_id'], (int) $root['id'], (int) $me['reads_after_message_id']);

        return [
            'root' => $this->presentMessages($tenantId, [$root], $viewer)[0],
            'replies' => $this->presentMessages($tenantId, $replies, $viewer),
        ];
    }

    /**
     * Search one chat's messages by words and, optionally, by who wrote them (UC.8).
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $tenantId, ChatViewer $viewer, int $chatId, string $q, ?int $authorProfileId): array
    {
        [, , $me] = $this->open($tenantId, $viewer, $chatId);
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '' && $authorProfileId === null) {
            return [];
        }
        $rows = $this->repo->search($tenantId, $chatId, (int) $me['reads_after_message_id'], $q, $authorProfileId);

        return $this->presentMessages($tenantId, $rows, $viewer);
    }

    /** The person is typing in this chat (a beat every few seconds while they type). */
    public function typing(int $tenantId, ChatViewer $viewer, int $chatId): void
    {
        $this->personOnly($viewer);
        $this->open($tenantId, $viewer, $chatId);
        $this->store?->increment(self::typingKey($tenantId, $chatId, $viewer->profileId), self::TYPING_SECONDS);
    }

    /**
     * What an open chat polls for: new lines after `$after`, lines changed
     * since `$since` (edits, deletes, reactions, pins, thread replies), who has
     * read how far, who is typing, and the pinned messages.
     *
     * @return array<string, mixed>
     */
    public function state(int $tenantId, ChatViewer $viewer, int $chatId, int $after, ?string $since): array
    {
        [, $members, $me] = $this->open($tenantId, $viewer, $chatId);
        $floor = (int) $me['reads_after_message_id'];
        $now = gmdate('Y-m-d H:i:s');
        $new = $this->repo->messages($tenantId, $chatId, $floor, $after, null, 100);
        $changed = [];
        if ($since !== null && ($ts = strtotime($since)) !== false) {
            // A second of overlap: a change written in the same second as the last poll is not lost.
            $changed = $this->repo->changedSince($tenantId, $chatId, $floor, gmdate('Y-m-d H:i:s', $ts - 1));
        }

        return [
            'messages' => $this->presentMessages($tenantId, $new, $viewer),
            'changed' => $this->presentMessages($tenantId, $changed, $viewer),
            'read_marks' => $this->repo->readMarks($tenantId, $chatId),
            'typing' => $this->typingNames($tenantId, $chatId, $members, $viewer),
            'pinned' => $this->presentMessages($tenantId, $this->repo->pinnedMessages($tenantId, $chatId, $floor), $viewer),
            'now' => self::iso($now),
        ];
    }

    /** Bubbles or compact rows (UC.7). */
    public function layout(int $profileId): string
    {
        return $this->repo->chatLayout($profileId);
    }

    public function setLayout(int $profileId, string $layout): string
    {
        if (!in_array($layout, ['bubbles', 'rows'], true)) {
            throw new ChatException('Choose bubbles or rows.');
        }
        $this->repo->setChatLayout($profileId, $layout);

        return $layout;
    }

    /**
     * @param list<array<string, mixed>> $members
     * @return list<string>
     */
    private function typingNames(int $tenantId, int $chatId, array $members, ChatViewer $viewer): array
    {
        if ($this->store === null) {
            return [];
        }
        $names = [];
        foreach (array_slice($members, 0, 50) as $m) {
            if ($m['profile_id'] === null || (!$viewer->isAgent() && (int) $m['profile_id'] === $viewer->profileId)) {
                continue;
            }
            try {
                if ($this->store->count(self::typingKey($tenantId, $chatId, (int) $m['profile_id'])) > 0) {
                    $names[] = (string) $m['person_name'];
                }
            } catch (\Throwable) {
                // Typing is a nicety: a store problem shows nobody typing.
            }
        }

        return $names;
    }

    private static function typingKey(int $tenantId, int $chatId, int $profileId): string
    {
        return 'chat-typing:' . $tenantId . ':' . $chatId . ':' . $profileId;
    }

    /**
     * A message in a chat the viewer is in, above their read-from mark.
     *
     * @return array<string, mixed>
     */
    private function messageInChat(int $tenantId, ChatViewer $viewer, int $messageId): array
    {
        $message = $this->repo->message($tenantId, $messageId);
        if ($message === null) {
            throw new ChatException('Message not found', 404);
        }
        [, , $me] = $this->open($tenantId, $viewer, (int) $message['conversation_id']);
        if ((int) $message['id'] <= (int) $me['reads_after_message_id']) {
            throw new ChatException('Message not found', 404);
        }

        return $message;
    }

    /**
     * The viewer's own message, written by them as a person, not deleted.
     *
     * @return array{0: array<string, mixed>}
     */
    private function ownMessage(int $tenantId, ChatViewer $viewer, int $messageId): array
    {
        $message = $this->messageInChat($tenantId, $viewer, $messageId);
        $mine = $message['author_profile_id'] !== null && (int) $message['author_profile_id'] === $viewer->profileId
            && !$message['by_agent'] && (string) $message['source'] === 'chat.person';
        if (!$mine) {
            throw new ChatException('You can only change your own messages.', 403);
        }
        if ($message['deleted_at'] !== null) {
            throw new ChatException('This message was deleted.');
        }

        return [$message];
    }

    /**
     * The message a new one replies to or threads under: in the same chat and
     * readable by the viewer. A thread never nests: a reply in a thread goes to
     * its root.
     *
     * @return array<string, mixed>
     */
    private function target(int $tenantId, ChatViewer $viewer, int $chatId, int $messageId, string $what): array
    {
        $message = $this->repo->message($tenantId, $messageId);
        [, , $me] = $this->open($tenantId, $viewer, $chatId);
        if ($message === null || (int) $message['conversation_id'] !== $chatId || (int) $message['id'] <= (int) $me['reads_after_message_id']) {
            throw new ChatException("The message to {$what} is not in this chat.", 422);
        }

        return $message;
    }

    private static function iso(mixed $at): ?string
    {
        if (!is_string($at) || $at === '') {
            return null;
        }
        $ts = strtotime($at . (preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $at) === 1 ? '' : ' UTC'));

        return $ts === false ? $at : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
