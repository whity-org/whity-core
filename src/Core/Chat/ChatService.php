<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use Whity\Core\Agent\AgentAssignmentRepository;

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

    public function __construct(private readonly ChatRepository $repo)
    {
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
    public function post(int $tenantId, ChatViewer $viewer, int $chatId, string $body, array $mentions = [], string $style = 'message'): array
    {
        [, $members, $me] = $this->open($tenantId, $viewer, $chatId);
        $body = trim($body);
        if ($body === '') {
            throw new ChatException('The message is empty.');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new ChatException('The message is too long (' . self::MAX_BODY . ' characters at most).');
        }

        $meta = [];
        $kept = self::mentions($mentions, $members);
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

        $id = $this->repo->insertMessage($tenantId, $chatId, $viewer->profileId, $viewer->isAgent(), $viewer->isAgent() ? 'chat.agent' : 'chat.person', $body, $meta, $status);
        if (!$viewer->isAgent()) {
            $this->repo->markRead($tenantId, (int) $me['id'], $id);
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

        return $this->presented($tenantId, $messageId, $viewer);
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

        return array_map(function (array $r) use ($proposals, $viewer): array {
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

            return [
                'id' => (int) $r['id'],
                'chat_id' => (int) $r['conversation_id'],
                'kind' => $kind,
                'body' => (string) $r['body_text'],
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
    private static function mentions(array $mentions, array $members): array
    {
        $kept = [];
        foreach (array_slice($mentions, 0, 20) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $type = $m['type'] ?? null;
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

    private static function iso(mixed $at): ?string
    {
        if (!is_string($at) || $at === '') {
            return null;
        }
        $ts = strtotime($at . (preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $at) === 1 ? '' : ' UTC'));

        return $ts === false ? $at : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
