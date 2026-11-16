<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Closure;
use PDO;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Messaging\Email\InboundEmailParser;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Sdk\Messaging\DeliveryStatusUpdate;
use Whity\Sdk\Messaging\InboundMessage;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;

/**
 * The one way a message is written (ADR 0016 §2).
 *
 * Staff replies, agent drafts, booking reminders from the calendar and anything
 * else in core all come through here, so every message lands in the customer's
 * conversation, is counted once against the plan, and leaves through a
 * background job rather than inside a request.
 *
 * Outbound messages are written `queued` and handed to `core.messages.deliver`
 * ({@see Jobs\DeliverMessageJob}). An agent's reply is held for
 * {@see AGENT_HOLD_SECONDS} before it goes, which is what makes it undoable:
 * {@see cancel()} works while it is still queued.
 */
final class MessagingService implements HostWiredService
{
    public const DELIVER_JOB = 'core.messages.deliver';
    public const AGENT_HOLD_SECONDS = 300;
    public const MAX_DELIVERY_ATTEMPTS = 3;
    private const PAGE = 200;

    /**
     * @param Closure(string, array<string, mixed>, array<string, mixed>): mixed $enqueue
     *        Queues a job: name, payload, options (`tenant_id`, `delay`, `idempotency_key`).
     */
    public function __construct(
        private readonly PDO $db,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly MessageChannelRegistry $channels,
        private readonly Closure $enqueue,
        private readonly ?MeterService $meters = null,
    ) {
        $this->contacts = new ConversationContacts($db);
    }

    /** C5: who a customer is, whatever channel they write on. */
    private ConversationContacts $contacts;

    /** UC.12: says whether a sender is blocked; what they send is then dropped. */
    private ?Closure $blocked = null;

    /** UC.4: the workspace's bots, after a customer's message is filed. */
    private ?Closure $afterInbound = null;

    /**
     * Rules for what customers send (design 3.5, WHIT-1097): blocked senders
     * are dropped before anything is filed, and the workspace's bots run after.
     *
     * @param Closure(int, string, string): bool|null $blocked tenant, channel, address
     * @param Closure(MessagingService, int, int, bool): void|null $afterInbound tenant, conversation, whether it is new
     */
    public function useInboundRules(?Closure $blocked, ?Closure $afterInbound): void
    {
        $this->blocked = $blocked;
        $this->afterInbound = $afterInbound;
    }

    public function conversations(): ConversationRepository
    {
        return $this->conversations;
    }

    public function messages(): MessageRepository
    {
        return $this->messages;
    }

    /** C5: who a customer is across channels. */
    public function contacts(): ConversationContacts
    {
        return $this->contacts;
    }

    public function channels(): MessageChannelRegistry
    {
        return $this->channels;
    }

    /**
     * Send to a person or an address, in their open conversation on the channel
     * or a new one. For other core modules, each with its own `source`. The
     * calendar's booking emails and reminders don't go through here yet: they
     * go to the Mailer through EmailBookingMessenger.
     *
     * @return array<string, mixed> the message
     *
     * @throws MessagingException when the channel is unknown or off, or the body or address is not usable
     */
    public function send(int $tenantId, Recipient $to, string $body, SendOptions $options = new SendOptions()): array
    {
        $channel = $this->channel($tenantId, $options->channel);

        $customerId = null;
        $name = $to->name;
        if ($to->profileId !== null) {
            [$address, $personName] = $this->addressOf($tenantId, $to->profileId, $options->channel);
            $customerId = $this->isCustomer($tenantId, $to->profileId) ? $to->profileId : null;
            $name ??= $personName;
        } else {
            $address = (string) $to->address;
        }
        if ($options->channel === 'email' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new MessagingException('The email address is not valid.');
        }

        // C5: the customer's conversation on any channel, then this address's.
        $contactId = $this->contacts->contactFor($tenantId, $address);
        $existing = $options->newConversation
            ? null
            : $this->contacts->conversationFor($tenantId, $options->channel, $address, $contactId, $customerId);
        if ($existing === null && !$options->newConversation) {
            $open = $this->conversations->findOpenForContact($tenantId, $options->channel, $address);
            $existing = $open !== null ? (int) $open['id'] : null;
        }
        $conversationId = $existing ?? $this->conversations->create($tenantId, $options->channel, $address, $name, $options->subject, $customerId);
        $this->contacts->remember($tenantId, $options->channel, $address, $conversationId, $contactId);

        return $this->queueOutbound($tenantId, $conversationId, $channel, $body, $options->authorProfileId, $options->byAgent, $options->source, [], $options->channel, $address);
    }

    /**
     * A reply in an existing conversation, on its channel.
     *
     * @param array<string, mixed> $meta what the team sees with it (a block, UC.5); the customer gets the words
     * @return array<string, mixed> the message
     */
    public function reply(int $tenantId, int $conversationId, ?int $authorProfileId, string $body, bool $byAgent = false, ?string $source = null, array $meta = [], ?string $sendBy = null): array
    {
        if ($source !== null && preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $source) !== 1) {
            throw new MessagingException('The source must be a short lowercase word such as calendar.reminder.');
        }
        $conversation = $this->conversations->find($tenantId, $conversationId);
        if ($conversation === null) {
            throw new MessagingException('Conversation not found.');
        }
        // C5 (UC.3): by the channel the customer last wrote on, unless "Send by"
        // chose another they have; the conversation's own channel when they
        // haven't written yet.
        $addresses = $this->contacts->addresses($tenantId, $conversationId);
        $key = $sendBy ?? (array_key_first($addresses) ?? (string) $conversation['channel']);
        $address = $addresses[$key] ?? ($key === (string) $conversation['channel'] ? (string) $conversation['contact_address'] : null);
        if ($address === null) {
            throw new MessagingException('This customer has not written on that channel, so there is no address to send to.');
        }
        $channel = $this->channel($tenantId, $key);

        $lastOnChannel = $this->messages->lastInboundAt($tenantId, $conversationId, $key)
            ?? ($key === (string) $conversation['channel'] ? $conversation['last_inbound_at'] : null);
        $lastInbound = $lastOnChannel !== null ? strtotime((string) $lastOnChannel . ' UTC') : false;
        $since = $lastInbound === false ? null : max(0, time() - $lastInbound);
        if (!$channel->capabilities()->allowsFreeform($since)) {
            throw new MessagingException('This channel only allows an approved template now, because the customer has not written recently.');
        }

        return $this->queueOutbound(
            $tenantId,
            $conversationId,
            $channel,
            $body,
            $authorProfileId,
            $byAgent,
            $source ?? ($byAgent ? 'agent.reply' : 'staff.reply'),
            $meta,
            $key,
            $address,
        );
    }

    /**
     * An internal note: staff only, never sent, not counted.
     *
     * @return array<string, mixed>
     */
    public function note(int $tenantId, int $conversationId, int $authorProfileId, string $body, bool $byAgent = false): array
    {
        if ($this->conversations->find($tenantId, $conversationId) === null) {
            throw new MessagingException('Conversation not found.');
        }
        $body = self::cleanBody($body, 20000);
        $id = $this->messages->insert($tenantId, $conversationId, [
            'kind' => MessageRepository::KIND_NOTE,
            'status' => 'noted',
            'body_text' => $body,
            'author_profile_id' => $authorProfileId,
            'by_agent' => $byAgent,
            'source' => 'staff.note',
        ]);

        return (array) $this->messages->find($tenantId, $id);
    }

    /**
     * File a message a customer sent. Null when it repeats one already filed
     * (providers retry webhooks).
     *
     * @param int|null $conversationId Put it here (web chat knows its conversation).
     * @param int|null $customerProfileId The signed-in customer who wrote it.
     *
     * @return array<string, mixed>|null the message
     */
    public function ingest(int $tenantId, string $channelKey, InboundMessage $in, ?int $conversationId = null, ?int $customerProfileId = null): ?array
    {
        if (!$this->channels->has($channelKey)) {
            throw new MessagingException('Unknown channel.');
        }
        // A blocked sender's message goes nowhere (UC.12); the sender is not told.
        if ($this->blocked !== null && ($this->blocked)($tenantId, $channelKey, $in->from)) {
            return null;
        }

        $customerProfileId ??= $channelKey === 'email' ? $this->conversations->customerByEmail($tenantId, $in->from) : null;
        $contactId = $this->contacts->contactFor($tenantId, $in->from);
        // C5 (owner Q35.2): a reply's own ids first; then who the customer is,
        // on any channel (a conversation closed within 30 days reopens, Q11);
        // then the older per-channel matches.
        $conversationId ??= $this->threadByIds($tenantId, $in)
            ?? $this->contacts->conversationFor($tenantId, $channelKey, $in->from, $contactId, $customerProfileId)
            ?? $this->threadFor($tenantId, $channelKey, $in);
        $isNew = $conversationId === null;
        if ($conversationId === null) {
            $subject = $in->subject !== null ? InboundEmailParser::baseSubject($in->subject) : null;
            $conversationId = $this->conversations->create(
                $tenantId,
                $channelKey,
                $in->from,
                $in->fromName,
                $subject !== '' ? $subject : null,
                $customerProfileId
            );
        } elseif ($customerProfileId !== null) {
            $this->conversations->linkCustomer($tenantId, $conversationId, $customerProfileId);
        }

        if ($in->externalId !== null && $this->messages->hasExternalId($tenantId, $conversationId, $in->externalId)) {
            return null;
        }

        $this->contacts->remember($tenantId, $channelKey, $in->from, $conversationId, $contactId);
        $this->conversations->useChannel($tenantId, $conversationId, $channelKey, $in->from);

        $id = $this->messages->insert($tenantId, $conversationId, [
            'channel' => $channelKey,
            'channel_address' => $in->from,
            'kind' => MessageRepository::KIND_INBOUND,
            'status' => 'received',
            'body_text' => self::cleanBody($in->body, InboundEmailParser::MAX_BODY_CHARS, true),
            'body_html' => $in->htmlBody,
            'external_id' => $in->externalId,
            'in_reply_to' => $in->inReplyTo,
            'attachments_not_shown' => max(0, $in->attachmentsNotShown),
            'source' => 'customer',
        ]);
        $this->conversations->touch($tenantId, $conversationId, true);
        $filed = $this->messages->find($tenantId, $id);
        if ($this->afterInbound !== null) {
            try {
                ($this->afterInbound)($this, $tenantId, $conversationId, $isNew);
            } catch (\Throwable) {
                // The customer's message is filed whatever a bot does.
            }
        }

        return $filed;
    }

    /** A provider's delivery news. Moves a message's status forward only. */
    public function applyStatus(int $tenantId, string $channelKey, DeliveryStatusUpdate $update): int
    {
        return $this->messages->applyStatus($tenantId, $update->externalId, $update->status, $update->detail);
    }

    /** Stop a message that has not left yet (an agent's held reply). */
    public function cancel(int $tenantId, int $messageId): bool
    {
        return $this->messages->cancel($tenantId, $messageId);
    }

    /**
     * Hand one queued message to its channel. Called by the deliver job.
     *
     * @return string `sent`, `failed`, `retry` or `skipped`
     */
    public function deliver(int $tenantId, int $messageId, int $attempt = 1): string
    {
        $message = $this->messages->find($tenantId, $messageId);
        if ($message === null || $message['status'] !== 'queued' || $message['kind'] !== MessageRepository::KIND_OUTBOUND) {
            return 'skipped';
        }

        if ($message['send_after'] !== null) {
            $wait = (int) strtotime((string) $message['send_after'] . ' UTC') - time();
            if ($wait > 0) {
                $this->queueDelivery($tenantId, $messageId, $wait, $attempt);

                return 'skipped';
            }
        }

        $conversation = $this->conversations->find($tenantId, (int) $message['conversation_id']);
        if ($conversation === null) {
            return 'skipped';
        }
        // C5: a message goes on its own channel to its own address; older
        // messages without one use the conversation's.
        $channelKey = is_string($message['channel'] ?? null) && $message['channel'] !== '' ? (string) $message['channel'] : (string) $conversation['channel'];
        $to = is_string($message['channel_address'] ?? null) && $message['channel_address'] !== '' ? (string) $message['channel_address'] : (string) $conversation['contact_address'];
        $channel = $this->channels->get($channelKey);
        if ($channel === null) {
            $this->messages->markFailed($tenantId, $messageId, 'This channel is no longer installed.');

            return 'failed';
        }

        $thread = $this->messages->threadIds($tenantId, (int) $conversation['id'], $channelKey);
        $outbound = new OutboundMessage(
            tenantId: $tenantId,
            conversationId: (int) $conversation['id'],
            messageId: $messageId,
            to: $to,
            toName: $conversation['contact_name'] !== null ? (string) $conversation['contact_name'] : null,
            subject: $conversation['subject'] !== null ? (string) $conversation['subject'] : null,
            body: (string) $message['body_text'],
            inReplyTo: $thread !== [] ? $thread[count($thread) - 1] : null,
            references: $thread,
        );

        try {
            $outcome = $channel->send($outbound);
        } catch (\Throwable $e) {
            error_log("[MessagingService] channel {$channelKey} threw while sending: " . $e->getMessage());
            $outcome = \Whity\Sdk\Messaging\SendOutcome::failed('The channel could not send the message.', true);
        }

        if ($outcome->sent) {
            $this->messages->markSent($tenantId, $messageId, $outcome->externalId);

            return 'sent';
        }
        if ($outcome->retryable && $attempt < self::MAX_DELIVERY_ATTEMPTS) {
            $this->queueDelivery($tenantId, $messageId, 60 * $attempt, $attempt + 1);

            return 'retry';
        }
        $this->messages->markFailed($tenantId, $messageId, (string) $outcome->reason);

        return 'failed';
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function queueOutbound(
        int $tenantId,
        int $conversationId,
        MessageChannel $channel,
        string $body,
        ?int $authorProfileId,
        bool $byAgent,
        string $source,
        array $meta = [],
        ?string $channelKey = null,
        ?string $address = null,
    ): array {
        $body = self::cleanBody($body, $channel->capabilities()->maxBodyLength);
        $hold = $byAgent ? self::AGENT_HOLD_SECONDS : 0;

        $id = $this->messages->insert($tenantId, $conversationId, [
            'kind' => MessageRepository::KIND_OUTBOUND,
            'status' => 'queued',
            'body_text' => $body,
            'author_profile_id' => $authorProfileId,
            'by_agent' => $byAgent,
            'source' => $source,
            'send_after_seconds' => $hold,
            'chat_meta' => $meta,
            'channel' => $channelKey,
            'channel_address' => $address,
        ]);
        $this->conversations->touch($tenantId, $conversationId, false);
        $this->count($tenantId);

        // Web chat has no provider to wait for: the visitor sees it once it is
        // marked sent, so it is marked now unless it is being held.
        if ($hold === 0 && $channel instanceof WebChatChannel) {
            $this->deliver($tenantId, $id);
        } else {
            $this->queueDelivery($tenantId, $id, $hold, 1);
        }

        return (array) $this->messages->find($tenantId, $id);
    }

    private function queueDelivery(int $tenantId, int $messageId, int $delay, int $attempt): void
    {
        ($this->enqueue)(
            self::DELIVER_JOB,
            ['tenant_id' => $tenantId, 'message_id' => $messageId, 'attempt' => $attempt],
            [
                'tenant_id' => $tenantId,
                'delay' => max(0, $delay),
                'idempotency_key' => 'message-deliver:' . $messageId . ':' . $attempt,
            ]
        );
    }

    /** Counted when queued, on every channel; warns past the plan's allowance, never refuses. */
    private function count(int $tenantId): void
    {
        if ($this->meters === null) {
            return;
        }
        try {
            $decision = $this->meters->record($tenantId, [EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH]);
            if ($decision->warned) {
                error_log("[MessagingService] workspace {$tenantId} is past its monthly message allowance");
            }
        } catch (\Throwable $e) {
            error_log('[MessagingService] could not count an outbound message: ' . $e->getMessage());
        }
    }

    private function channel(int $tenantId, string $key): MessageChannel
    {
        $channel = $this->channels->get($key);
        if ($channel === null) {
            throw new MessagingException('This channel is not available.');
        }
        try {
            $enabled = $channel->isEnabled($tenantId);
        } catch (\Throwable $e) {
            error_log("[MessagingService] channel {$key} failed isEnabled: " . $e->getMessage());
            $enabled = false;
        }
        if (!$enabled) {
            throw new MessagingException('This channel is turned off for the workspace.');
        }

        return $channel;
    }

    /** The conversation a reply names by its ids (In-Reply-To, References, a thread key), on any channel. */
    private function threadByIds(int $tenantId, InboundMessage $in): ?int
    {
        $ids = $in->references;
        if ($in->inReplyTo !== null) {
            $ids[] = $in->inReplyTo;
        }
        if ($in->threadKey !== null) {
            $ids[] = $in->threadKey;
        }
        $byId = $this->messages->conversationForExternalIds($tenantId, $ids);

        return $byId !== null && $this->conversations->find($tenantId, $byId) !== null ? $byId : null;
    }

    /** The older per-channel matches: by sender and subject (email), or the address's open conversation. */
    private function threadFor(int $tenantId, string $channelKey, InboundMessage $in): ?int
    {
        if ($channelKey === 'email') {
            $subject = InboundEmailParser::baseSubject($in->subject);
            $match = $subject !== '' ? $this->conversations->findBySubject($tenantId, $channelKey, $in->from, $subject) : null;

            return $match !== null ? (int) $match['id'] : null;
        }

        $open = $this->conversations->findOpenForContact($tenantId, $channelKey, $in->from);

        return $open !== null ? (int) $open['id'] : null;
    }

    /**
     * The person's address on the channel, and their name, if they belong to the workspace.
     *
     * @return array{0: string, 1: ?string}
     */
    private function addressOf(int $tenantId, int $profileId, string $channel): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.display_name, e.email
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
               LEFT JOIN profile_emails e ON e.profile_id = m.profile_id
              WHERE m.tenant_id = :tid AND m.profile_id = :pid AND m.status = \'active\'
              ORDER BY e.is_primary DESC, e.id ASC LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new MessagingException('That person is not in this workspace.');
        }
        $name = $row['display_name'] !== null && $row['display_name'] !== '' ? (string) $row['display_name'] : null;

        if ($channel === 'email') {
            if (!is_string($row['email']) || $row['email'] === '') {
                throw new MessagingException('That person has no email address.');
            }

            return [(string) $row['email'], $name];
        }

        return ['profile:' . $profileId, $name];
    }

    private function isCustomer(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND kind = \'customer\''
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Trimmed, without control characters other than line breaks and tabs, and not longer than allowed. */
    public static function cleanBody(string $body, int $max, bool $truncate = false): string
    {
        $body = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($body, 'UTF-8'));
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') {
            throw new MessagingException('The message is empty.');
        }
        if (mb_strlen($body) > $max) {
            if (!$truncate) {
                throw new MessagingException("The message is longer than this channel allows ({$max} characters).");
            }
            $body = mb_substr($body, 0, $max);
        }

        return $body;
    }

    /** How many items a page of a conversation shows at most. */
    public static function pageSize(): int
    {
        return self::PAGE;
    }
}
