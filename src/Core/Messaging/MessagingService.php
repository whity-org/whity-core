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
    }

    public function conversations(): ConversationRepository
    {
        return $this->conversations;
    }

    public function messages(): MessageRepository
    {
        return $this->messages;
    }

    public function channels(): MessageChannelRegistry
    {
        return $this->channels;
    }

    /**
     * Send to a person or an address, in their open conversation on the channel
     * or a new one. For other core modules: the calendar's reminders use this
     * with `source: calendar.reminder`.
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

        $conversation = $options->newConversation
            ? null
            : $this->conversations->findOpenForContact($tenantId, $options->channel, $address);
        $conversationId = $conversation !== null
            ? (int) $conversation['id']
            : $this->conversations->create($tenantId, $options->channel, $address, $name, $options->subject, $customerId);

        return $this->queueOutbound($tenantId, $conversationId, $channel, $body, $options->authorProfileId, $options->byAgent, $options->source);
    }

    /**
     * A reply in an existing conversation, on its channel.
     *
     * @return array<string, mixed> the message
     */
    public function reply(int $tenantId, int $conversationId, ?int $authorProfileId, string $body, bool $byAgent = false): array
    {
        $conversation = $this->conversations->find($tenantId, $conversationId);
        if ($conversation === null) {
            throw new MessagingException('Conversation not found.');
        }
        $channel = $this->channel($tenantId, (string) $conversation['channel']);

        $lastInbound = $conversation['last_inbound_at'] !== null ? strtotime((string) $conversation['last_inbound_at'] . ' UTC') : false;
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
            $byAgent ? 'agent.reply' : 'staff.reply'
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

        $conversationId ??= $this->threadFor($tenantId, $channelKey, $in);
        if ($conversationId === null) {
            $customerProfileId ??= $channelKey === 'email' ? $this->conversations->customerByEmail($tenantId, $in->from) : null;
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

        $id = $this->messages->insert($tenantId, $conversationId, [
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

        return $this->messages->find($tenantId, $id);
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
        $channel = $this->channels->get((string) $conversation['channel']);
        if ($channel === null) {
            $this->messages->markFailed($tenantId, $messageId, 'This channel is no longer installed.');

            return 'failed';
        }

        $thread = $this->messages->threadIds($tenantId, (int) $conversation['id']);
        $outbound = new OutboundMessage(
            tenantId: $tenantId,
            conversationId: (int) $conversation['id'],
            messageId: $messageId,
            to: (string) $conversation['contact_address'],
            toName: $conversation['contact_name'] !== null ? (string) $conversation['contact_name'] : null,
            subject: $conversation['subject'] !== null ? (string) $conversation['subject'] : null,
            body: (string) $message['body_text'],
            inReplyTo: $thread !== [] ? $thread[count($thread) - 1] : null,
            references: $thread,
        );

        try {
            $outcome = $channel->send($outbound);
        } catch (\Throwable $e) {
            error_log("[MessagingService] channel {$conversation['channel']} threw while sending: " . $e->getMessage());
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

    /** The conversation an inbound message continues, by its ids, then by sender and subject. */
    private function threadFor(int $tenantId, string $channelKey, InboundMessage $in): ?int
    {
        $ids = $in->references;
        if ($in->inReplyTo !== null) {
            $ids[] = $in->inReplyTo;
        }
        if ($in->threadKey !== null) {
            $ids[] = $in->threadKey;
        }
        $byId = $this->messages->conversationForExternalIds($tenantId, $ids);
        if ($byId !== null) {
            $conversation = $this->conversations->find($tenantId, $byId);
            if ($conversation !== null && $conversation['channel'] === $channelKey) {
                return $byId;
            }
        }

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
